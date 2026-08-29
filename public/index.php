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
            'csrfToken' => csrf_token(),
            'flashMessages' => consume_flash(),
            'apiConfigured' => $config->hasApiKey(),
            'authEnabled' => $auth->enabled(),
        ]);
        exit;
    }

    if ($path === '/settings' && $method === 'GET') {
        $template->render('settings', [
            'pageTitle' => 'Settings',
            'settings' => $settings,
            'apiConfigured' => $config->hasApiKey(),
            'csrfToken' => csrf_token(),
            'flashMessages' => consume_flash(),
            'authEnabled' => $auth->enabled(),
        ]);
        exit;
    }

    if ($path === '/settings' && $method === 'POST') {
        verify_csrf();
        $settingsStore->save($_POST);
        flash('success', 'Settings saved.');
        redirect('/settings');
    }

    if ($path === '/sync/start' && $method === 'POST') {
        verify_csrf();
        if (!$config->hasApiKey()) {
            throw new HttpException('Configure KIT_API_KEY before starting a sync.', 422);
        }
        json_response($sync->start((int) $settings['batch_size']));
    }

    if ($path === '/sync/step' && $method === 'POST') {
        verify_csrf();
        json_response($sync->step((int) $settings['batch_size']));
    }

    if ($path === '/sync/status' && $method === 'GET') {
        json_response($sync->latestProgress());
    }

    if ($path === '/export.csv' && $method === 'GET') {
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'group' => (string) ($_GET['group'] ?? 'all'),
            'sort' => (string) ($_GET['sort'] ?? 'created'),
            'direction' => (string) ($_GET['direction'] ?? 'desc'),
        ];
        $result = $audit->subscribers($filters, $settings, true);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="kit-subscriber-audit-' . gmdate('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new HttpException('Unable to create the CSV export.', 500);
        }
        fputcsv($output, [
            'Kit ID', 'Email', 'First name', 'State', 'Created at', 'Last sent', 'Last opened',
            'Last clicked', 'Emails sent', 'Sends since last open', 'Open rate', 'Click rate', 'Stats updated at',
        ]);
        foreach ($result['rows'] as $row) {
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
                csv_safe($row['open_rate']),
                csv_safe($row['click_rate']),
                csv_safe($row['stats_updated_at']),
            ]);
        }
        fclose($output);
        exit;
    }

    if ($path === '/cleanup/review' && $method === 'POST') {
        verify_csrf();
        $ids = is_array($_POST['subscriber_ids'] ?? null) ? $_POST['subscriber_ids'] : [];
        $candidates = $audit->removalCandidatesByIds($ids, $settings);
        $_SESSION['cleanup_selection'] = array_map(static fn (array $row): int => (int) $row['id'], $candidates);
        $template->render('cleanup-review', [
            'pageTitle' => 'Review unsubscribe list',
            'candidates' => $candidates,
            'settings' => $settings,
            'csrfToken' => csrf_token(),
            'flashMessages' => consume_flash(),
            'apiConfigured' => $config->hasApiKey(),
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
        if (!$config->hasApiKey()) {
            throw new HttpException('Configure KIT_API_KEY before starting cleanup.', 422);
        }
        $ids = is_array($_SESSION['cleanup_selection'] ?? null) ? $_SESSION['cleanup_selection'] : [];
        $progress = $cleanup->start($ids, $settings);
        unset($_SESSION['cleanup_selection']);
        json_response($progress);
    }

    if ($path === '/cleanup/step' && $method === 'POST') {
        verify_csrf();
        json_response($cleanup->step((int) $settings['batch_size']));
    }

    if ($path === '/cleanup/status' && $method === 'GET') {
        json_response($cleanup->latestProgress());
    }

    if ($path === '/cleanup/progress' && $method === 'GET') {
        $template->render('cleanup-progress', [
            'pageTitle' => 'Cleanup progress',
            'cleanupProgress' => $cleanup->latestProgress(),
            'csrfToken' => csrf_token(),
            'flashMessages' => consume_flash(),
            'authEnabled' => $auth->enabled(),
        ]);
        exit;
    }

    http_response_code(404);
    $template->render('error', ['pageTitle' => 'Not found', 'message' => 'The requested page was not found.', 'csrfToken' => csrf_token(), 'authEnabled' => $auth->enabled()]);
} catch (HttpException $exception) {
    if (str_starts_with($path, '/sync/') || str_starts_with($path, '/cleanup/')) {
        json_response(['error' => $exception->getMessage()], $exception->status);
    }
    flash('error', $exception->getMessage());
    redirect($path === '/settings' ? '/settings' : ($path === '/login' ? '/login' : '/'));
} catch (KitApiException $exception) {
    if (str_starts_with($path, '/sync/') || str_starts_with($path, '/cleanup/')) {
        json_response(['error' => $exception->getMessage()], 502);
    }
    flash('error', $exception->getMessage());
    redirect('/');
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    if (str_starts_with($path, '/sync/') || str_starts_with($path, '/cleanup/')) {
        json_response(['error' => 'Unexpected server error. Check the PHP error log.'], 500);
    }
    http_response_code(500);
    $template->render('error', ['pageTitle' => 'Application error', 'message' => 'Unexpected server error. Check the PHP error log.', 'csrfToken' => csrf_token(), 'authEnabled' => $auth->enabled()]);
}
