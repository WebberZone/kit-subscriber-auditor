<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use KitAudit\Authentication;
use KitAudit\HttpException;
use KitAudit\KitApiException;
use function KitAudit\consume_flash;
use function KitAudit\csrf_token;
use function KitAudit\csv_safe;
use function KitAudit\flash;
use function KitAudit\json_response;
use function KitAudit\redirect;
use function KitAudit\verify_csrf;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$auth = new Authentication($config);
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$forwardedHttps = $config->trustsProxy() && strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 2)[0])) === 'https';
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedHttps;
header('Cache-Control: private, no-store, no-cache, must-revalidate');
if (str_ends_with(strtok($host, ':') ?: '', '.test') && !$isHttps) {
    header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 308);
    exit;
}
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000');
}
$resolveSelection = static function (array $input) use ($audit, $settings): array {
    $group = $audit->validateGroup((string) ($input['selection_group'] ?? 'removal'));
    $mode = (string) ($input['selection_mode'] ?? 'visible');
    if ($mode === 'all') {
        $filters = [
            'q' => trim((string) ($input['selection_q'] ?? '')),
            'group' => $group,
            'sort' => (string) ($input['selection_sort'] ?? 'created'),
            'direction' => (string) ($input['selection_direction'] ?? 'desc'),
            'page' => 1,
        ];
        return [$group, $audit->subscribers($filters, $settings, true)['rows']];
    }
    if ($mode !== 'visible') {
        throw new HttpException('Invalid subscriber selection mode.', 422);
    }

    $ids = is_array($input['subscriber_ids'] ?? null) ? $input['subscriber_ids'] : [];
    return [$group, $audit->selectedSubscribersByIds($ids, $group, $settings)];
};
$writeSubscriberCsv = static function (array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kit-subscriber-audit-' . gmdate('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new HttpException('Unable to create the CSV export.', 500);
    }
    fputcsv($output, [
        'Kit ID', 'Email', 'First name', 'State', 'Created at', 'Last sent', 'Last opened',
        'Last clicked', 'Emails sent', 'Sends since last open', 'Sends since last click', 'Open rate', 'Click rate', 'Stats updated at',
    ]);
    foreach ($rows as $row) {
        fputcsv($output, [
            csv_safe($row['id']),
            csv_safe($row['email_address']),
            csv_safe($row['first_name']),
            csv_safe($row['state']),
            csv_safe($row['created_at']),
            csv_safe($row['last_sent']),
            csv_safe($row['last_opened']),
            csv_safe($row['last_clicked']),
            csv_safe($row['sent']),
            csv_safe($row['sends_since_last_open']),
            csv_safe($row['sends_since_last_click']),
            csv_safe($row['open_rate']),
            csv_safe($row['click_rate']),
            csv_safe($row['stats_updated_at']),
        ]);
    }
    fclose($output);
    exit;
};
$renderReengagementReview = static function (array $candidates, string $selectionGroup) use ($kit, $reengagement, $settings, $template, $auth): void {
    $availableTags = [];
    $tagError = null;
    if ($kit->hasCredentials()) {
        try {
            $availableTags = $reengagement->availableTags();
        } catch (KitApiException $exception) {
            $tagError = $exception->getMessage();
        }
    }
    $template->render('reengagement-review', [
        'pageTitle' => 'Review re-engagement tag',
        'candidates' => $candidates,
        'selectionGroup' => $selectionGroup,
        'settings' => $settings,
        'availableTags' => $availableTags,
        'tagError' => $tagError,
        'csrfToken' => csrf_token(),
        'flashMessages' => consume_flash(),
        'apiConfigured' => $kit->hasCredentials(),
        'authEnabled' => $auth->enabled(),
    ]);
};

try {
    if ($path === '/login' && $method === 'GET') {
        if ($auth->isAuthenticated()) {
            redirect('/');
        }
        $template->render('login', [
            'pageTitle' => 'Sign in',
            'csrfToken' => csrf_token(),
            'flashMessages' => consume_flash(),
        ]);
        exit;
    }

    if ($path === '/login' && $method === 'POST') {
        verify_csrf();
        if (!$auth->login((string) ($_POST['password'] ?? ''))) {
            throw new HttpException('Incorrect app password.', 401);
        }
        redirect('/');
    }

    if ($path === '/logout' && $method === 'POST') {
        verify_csrf();
        $auth->logout();
        redirect('/login');
    }

    if ($path === '/oauth/start' && $method === 'GET') {
        $auth->requireLogin();
        redirect($oauth->authorizationUrl());
    }

    if ($path === '/oauth/callback' && $method === 'GET') {
        $auth->requireLogin();
        try {
            $oauth->handleCallback($_GET);
            flash('success', 'Kit OAuth connected. Kit API requests will now use the OAuth connection.');
        } catch (HttpException|KitApiException $exception) {
            flash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Kit OAuth callback failed: ' . $exception->getMessage());
            flash('error', 'Kit OAuth could not be completed. Check the PHP error log and try again.');
        }
        redirect('/settings');
    }

    if ($path === '/oauth/disconnect' && $method === 'POST') {
        $auth->requireLogin();
        verify_csrf();
        $oauth->disconnect();
        flash('success', 'The local Kit OAuth tokens were removed.');
        redirect('/settings');
    }

    $auth->requireLogin();

    if ($path === '/' && $method === 'GET') {
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'group' => (string) ($_GET['group'] ?? 'all'),
            'sort' => (string) ($_GET['sort'] ?? 'created'),
            'direction' => (string) ($_GET['direction'] ?? 'desc'),
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
        ];
        $metrics = $audit->dashboardMetrics($settings);
        $subscriberResult = $audit->subscribers($filters, $settings);
        $syncProgress = $sync->latestProgress();
        $cleanupProgress = $cleanup->latestProgress();
        $template->render('dashboard', [
            'pageTitle' => 'Subscriber audit',
            'metrics' => $metrics,
            'settings' => $settings,
            'filters' => $filters,
            'subscriberResult' => $subscriberResult,
            'syncProgress' => $syncProgress,
            'cleanupProgress' => $cleanupProgress,
            'reengagementProgress' => $reengagement->latestProgress(),
            'csrfToken' => csrf_token(),
            'flashMessages' => consume_flash(),
            'apiConfigured' => $kit->hasCredentials(),
            'authEnabled' => $auth->enabled(),
        ]);
        exit;
    }

    if ($path === '/settings' && $method === 'GET') {
        $availableTags = [];
        if ($kit->hasCredentials()) {
            try {
                $availableTags = $reengagement->availableTags();
            } catch (KitApiException $exception) {
                flash('error', 'Unable to load Kit tags: ' . $exception->getMessage());
            }
        }
        $template->render('settings', [
            'pageTitle' => 'Settings',
            'settings' => $settings,
            'apiConfigured' => $kit->hasCredentials(),
            'apiKeySource' => $credentials->apiKeySource(),
            'oauthStatus' => $oauth->status(),
            'oauthConnected' => $oauth->isConnected(),
            'oauthConfigured' => $oauth->isConfigured(),
            'availableTags' => $availableTags,
            'csrfToken' => csrf_token(),
            'flashMessages' => consume_flash(),
            'authEnabled' => $auth->enabled(),
        ]);
        exit;
    }

    if ($path === '/settings' && $method === 'POST') {
        verify_csrf();
        $credentialAction = (string) ($_POST['credential_action'] ?? '');
        if ($credentialAction === 'save_api_key') {
            $credentials->saveApiKey((string) ($_POST['kit_api_key'] ?? ''));
            flash('success', 'Kit API key encrypted and saved locally.');
        } elseif ($credentialAction === 'clear_api_key') {
            $credentials->clearStoredApiKey();
            flash('success', 'Stored Kit API key removed.');
        } else {
            $settingsStore->save($_POST);
            flash('success', 'Settings saved.');
        }
        redirect('/settings');
    }

    if ($path === '/sync/start' && $method === 'POST') {
        verify_csrf();
        if (!$kit->hasCredentials()) {
            throw new HttpException('Connect Kit via OAuth or configure an API key in Settings before starting a sync.', 422);
        }
        $batchSize = (int) $settings['batch_size'];
        $forceFull = (string) ($_POST['force_full'] ?? '') === '1';
        $progress = $sync->start($batchSize, $forceFull);
        $sync->launchWorker($batchSize, $projectRoot . '/bin/sync-worker.php', $projectRoot . '/storage/sync-worker.log');
        json_response($progress);
    }

    if ($path === '/sync/step' && $method === 'POST') {
        verify_csrf();
        json_response($sync->latestProgress());
    }

    if ($path === '/sync/status' && $method === 'GET') {
        json_response($sync->latestProgress());
    }

    if ($path === '/reengagement' && $method === 'GET') {
        $availableBroadcasts = [];
        $broadcastError = null;
        if ($kit->hasCredentials()) {
            try {
                $availableBroadcasts = $reengagement->availableBroadcasts();
            } catch (KitApiException $exception) {
                $broadcastError = $exception->getMessage();
            }
        }
        $template->render('reengagement', [
            'pageTitle' => 'Re-engagement',
            'settings' => $settings,
            'reengagementProgress' => $reengagement->latestProgress(),
            'availableBroadcasts' => $availableBroadcasts,
            'broadcastError' => $broadcastError,
            'staleRows' => $reengagement->staleRows(),
            'csrfToken' => csrf_token(),
            'flashMessages' => consume_flash(),
            'apiConfigured' => $kit->hasCredentials(),
            'authEnabled' => $auth->enabled(),
        ]);
        exit;
    }

    if ($path === '/reengagement/review' && $method === 'GET') {
        $ids = is_array($_SESSION['reengagement_selection'] ?? null) ? $_SESSION['reengagement_selection'] : [];
        $selectionGroup = $audit->validateGroup((string) ($_SESSION['reengagement_selection_group'] ?? 'removal'));
        $candidates = $audit->selectedSubscribersByIds($ids, $selectionGroup, $settings);
        $renderReengagementReview($candidates, $selectionGroup);
        exit;
    }

    if ($path === '/reengagement/review' && $method === 'POST') {
        verify_csrf();
        [$selectionGroup, $candidates] = $resolveSelection($_POST);
        $_SESSION['reengagement_selection'] = array_map(static fn (array $row): int => (int) $row['id'], $candidates);
        $_SESSION['reengagement_selection_group'] = $selectionGroup;
        $renderReengagementReview($candidates, $selectionGroup);
        exit;
    }

    if ($path === '/reengagement/tag/create' && $method === 'POST') {
        verify_csrf();
        if (!$kit->hasCredentials()) {
            throw new HttpException('Connect Kit via OAuth or configure an API key in Settings before creating a tag.', 422);
        }
        $tagName = trim((string) ($_POST['tag_name'] ?? ''));
        if ($tagName === '' || strlen($tagName) > 100 || preg_match('/[\x00-\x1F\x7F]/', $tagName) === 1) {
            throw new HttpException('Enter a tag name up to 100 characters without control characters.', 422);
        }
        $response = $kit->createTag($tagName);
        $tag = is_array($response['tag'] ?? null) ? $response['tag'] : [];
        $tagId = (int) ($tag['id'] ?? 0);
        $resolvedTagName = trim((string) ($tag['name'] ?? $tagName));
        if ($tagId < 1 || $resolvedTagName === '') {
            throw new KitApiException('Kit did not return the created tag.', 502);
        }
        $settingsStore->save(array_merge($settings, ['reengagement_tag_id' => $tagId]));
        flash('success', 'Kit tag “' . $resolvedTagName . '” is ready and selected for this cohort.');
        redirect('/reengagement/review');
        exit;
    }

    if ($path === '/reengagement/start' && $method === 'POST') {
        verify_csrf();
        if (!$kit->hasCredentials()) {
            throw new HttpException('Connect Kit via OAuth or configure an API key in Settings before tagging subscribers.', 422);
        }
        if (trim((string) ($_POST['confirm_phrase'] ?? '')) !== 'TAG') {
            throw new HttpException('Type TAG to confirm applying the Kit tag.', 422);
        }
        if (empty($_POST['confirm_tag'])) {
            throw new HttpException('Confirm that you want to apply the selected Kit tag.', 422);
        }
        $ids = is_array($_SESSION['reengagement_selection'] ?? null) ? $_SESSION['reengagement_selection'] : [];
        $selectionGroup = $audit->validateGroup((string) ($_SESSION['reengagement_selection_group'] ?? 'removal'));
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        if ($tagId < 1) {
            $tagId = (int) ($settings['reengagement_tag_id'] ?? 0);
        }
        $jobSettings = $settings;
        $jobSettings['reengagement_tag_id'] = $tagId;
        $progress = $reengagement->startTagging($ids, $jobSettings, $selectionGroup);
        $settingsStore->save($jobSettings);
        unset($_SESSION['reengagement_selection']);
        unset($_SESSION['reengagement_selection_group']);
        $reengagement->launchWorker($settings['batch_size'], $projectRoot . '/bin/reengagement-worker.php', $projectRoot . '/storage/reengagement-worker.log');
        json_response($progress);
    }

    if ($path === '/reengagement/resync' && $method === 'POST') {
        verify_csrf();
        if (!$kit->hasCredentials()) {
            throw new HttpException('Connect Kit via OAuth or configure an API key in Settings before resyncing the tag.', 422);
        }
        if (empty($_POST['confirm_resync'])) {
            throw new HttpException('Confirm that you sent the selected Kit broadcast and want to resync the tag.', 422);
        }
        $progress = $reengagement->startResync($settings, (int) ($_POST['broadcast_id'] ?? 0));
        $reengagement->launchWorker($settings['batch_size'], $projectRoot . '/bin/reengagement-worker.php', $projectRoot . '/storage/reengagement-worker.log');
        json_response($progress);
    }

    if ($path === '/reengagement/status' && $method === 'GET') {
        json_response($reengagement->latestProgress());
    }

    if ($path === '/export.csv' && $method === 'GET') {
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'group' => (string) ($_GET['group'] ?? 'all'),
            'sort' => (string) ($_GET['sort'] ?? 'created'),
            'direction' => (string) ($_GET['direction'] ?? 'desc'),
        ];
        $result = $audit->subscribers($filters, $settings, true);
        $writeSubscriberCsv($result['rows']);
    }

    if ($path === '/cleanup/export.csv' && $method === 'GET') {
        $ids = is_array($_SESSION['cleanup_selection'] ?? null) ? $_SESSION['cleanup_selection'] : [];
        $selectionGroup = $audit->validateGroup((string) ($_SESSION['cleanup_selection_group'] ?? 'removal'));
        $rows = $audit->selectedSubscribersByIds($ids, $selectionGroup, $settings);
        if ($rows === []) {
            throw new HttpException('The proposed unsubscribe list is no longer available.', 404);
        }
        $writeSubscriberCsv($rows);
    }

    if ($path === '/cleanup/review' && $method === 'POST') {
        verify_csrf();
        [$selectionGroup, $candidates] = $resolveSelection($_POST);
        $_SESSION['cleanup_selection'] = array_map(static fn (array $row): int => (int) $row['id'], $candidates);
        $_SESSION['cleanup_selection_group'] = $selectionGroup;
        $template->render('cleanup-review', [
            'pageTitle' => 'Review unsubscribe list',
            'candidates' => $candidates,
            'settings' => $settings,
            'selectionGroup' => $selectionGroup,
            'csrfToken' => csrf_token(),
            'flashMessages' => consume_flash(),
            'apiConfigured' => $kit->hasCredentials(),
            'authEnabled' => $auth->enabled(),
        ]);
        exit;
    }

    if ($path === '/cleanup/start' && $method === 'POST') {
        verify_csrf();
        $phrase = trim((string) ($_POST['confirm_phrase'] ?? ''));
        if ($phrase !== 'UNSUBSCRIBE') {
            throw new HttpException('Type UNSUBSCRIBE to confirm the action.', 422);
        }
        if (empty($_POST['confirm_export'])) {
            throw new HttpException('Confirm that you reviewed or exported the proposed list.', 422);
        }
        if (!$kit->hasCredentials()) {
            throw new HttpException('Connect Kit via OAuth or configure an API key in Settings before starting cleanup.', 422);
        }
        $ids = is_array($_SESSION['cleanup_selection'] ?? null) ? $_SESSION['cleanup_selection'] : [];
        $selectionGroup = $audit->validateGroup((string) ($_SESSION['cleanup_selection_group'] ?? 'removal'));
        $jobSettings = $settings;
        $jobSettings['dry_run'] = (int) ($_POST['cleanup_dry_run'] ?? $settings['dry_run']) === 1 ? 1 : 0;
        $progress = $cleanup->start($ids, $jobSettings, $selectionGroup);
        unset($_SESSION['cleanup_selection']);
        unset($_SESSION['cleanup_selection_group']);
        json_response($progress);
    }

    if ($path === '/cleanup/step' && $method === 'POST') {
        verify_csrf();
        $cleanupLock = fopen($projectRoot . '/storage/cleanup-worker.lock', 'c');
        if ($cleanupLock === false) {
            throw new HttpException('Unable to acquire the cleanup worker lock.', 503);
        }
        chmod($projectRoot . '/storage/cleanup-worker.lock', 0600);

        if (!flock($cleanupLock, LOCK_EX | LOCK_NB)) {
            fclose($cleanupLock);
            json_response($cleanup->latestProgress());
        }

        try {
            $cleanupProgress = $cleanup->step((int) $settings['batch_size']);
        } finally {
            flock($cleanupLock, LOCK_UN);
            fclose($cleanupLock);
        }

        json_response($cleanupProgress);
    }

    if ($path === '/cleanup/status' && $method === 'GET') {
        json_response($cleanup->latestProgress());
    }

    if ($path === '/cleanup/progress' && $method === 'GET') {
        $template->render('cleanup-progress', [
            'pageTitle' => 'Cleanup progress',
            'cleanupProgress' => $cleanup->latestProgress(),
            'settings' => $settings,
            'csrfToken' => csrf_token(),
            'flashMessages' => consume_flash(),
            'authEnabled' => $auth->enabled(),
        ]);
        exit;
    }

    http_response_code(404);
    $template->render('error', ['pageTitle' => 'Not found', 'message' => 'The requested page was not found.', 'csrfToken' => csrf_token(), 'authEnabled' => $auth->enabled()]);
} catch (HttpException $exception) {
    if (str_starts_with($path, '/sync/') || str_starts_with($path, '/cleanup/') || str_starts_with($path, '/reengagement/')) {
        json_response(['error' => $exception->getMessage()], $exception->status);
    }
    flash('error', $exception->getMessage());
    redirect($path === '/settings' || str_starts_with($path, '/oauth/') ? '/settings' : ($path === '/login' ? '/login' : '/'));
} catch (KitApiException $exception) {
    if (str_starts_with($path, '/sync/') || str_starts_with($path, '/cleanup/') || str_starts_with($path, '/reengagement/')) {
        json_response(['error' => $exception->getMessage()], 502);
    }
    flash('error', $exception->getMessage());
    redirect(str_starts_with($path, '/oauth/') ? '/settings' : '/');
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    if (str_starts_with($path, '/sync/') || str_starts_with($path, '/cleanup/') || str_starts_with($path, '/reengagement/')) {
        json_response(['error' => 'Unexpected server error. Check the PHP error log.'], 500);
    }
    http_response_code(500);
    $template->render('error', ['pageTitle' => 'Application error', 'message' => 'Unexpected server error. Check the PHP error log.', 'csrfToken' => csrf_token(), 'authEnabled' => $auth->enabled()]);
}
