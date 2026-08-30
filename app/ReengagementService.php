<?php

declare(strict_types=1);

namespace KitAudit;

use DateTimeImmutable;
use DateTimeZone;

final class ReengagementService
{
    private const MAX_PAGES = 100;

    public function __construct(
        private readonly Database $database,
        private readonly KitApiClient $kit,
        private readonly AuditService $audit,
    ) {
    }

    /** @return list<array{id: int, name: string}> */
    public function availableTags(): array
    {
        $tags = [];
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->kit->listTags($after);
            foreach (($response['tags'] ?? []) as $tag) {
                if (is_array($tag) && isset($tag['id'], $tag['name'])) {
                    $tags[] = ['id' => (int) $tag['id'], 'name' => (string) $tag['name']];
                }
            }

            $pagination = is_array($response['pagination'] ?? null) ? $response['pagination'] : [];
            if (!(bool) ($pagination['has_next_page'] ?? false)) {
                break;
            }
            $next = (string) ($pagination['end_cursor'] ?? '');
            if ($next === '' || $next === $after) {
                throw new KitApiException('Kit returned an invalid tag pagination cursor.');
            }
            $after = $next;
        }

        usort($tags, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
        return $tags;
    }

    /** @return list<array{id: int, subject: string, sent_at: string}> */
    public function availableBroadcasts(): array
    {
        $broadcasts = [];
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->kit->listBroadcasts('completed', $after);
            foreach (($response['broadcasts'] ?? []) as $broadcast) {
                if (!is_array($broadcast) || !isset($broadcast['id'], $broadcast['send_at'])) {
                    continue;
                }
                $sentAt = $this->validTimestamp($broadcast['send_at']);
                if ($sentAt === null) {
                    continue;
                }
                $broadcasts[] = [
                    'id' => (int) $broadcast['id'],
                    'subject' => (string) ($broadcast['subject'] ?? '(No subject)'),
                    'sent_at' => $sentAt,
                ];
            }

            $pagination = is_array($response['pagination'] ?? null) ? $response['pagination'] : [];
            if (!(bool) ($pagination['has_next_page'] ?? false)) {
                break;
            }
            $next = (string) ($pagination['end_cursor'] ?? '');
            if ($next === '' || $next === $after) {
                throw new KitApiException('Kit returned an invalid broadcast pagination cursor.');
            }
            $after = $next;
        }

        usort($broadcasts, fn (array $left, array $right): int => strtotime($right['sent_at']) <=> strtotime($left['sent_at']));
        return array_slice($broadcasts, 0, 100);
    }

    /** @return array<string, mixed> */
    public function startTagging(array $ids, array $settings): array
    {
        $this->ensureCanStart();
        $tagId = (int) ($settings['reengagement_tag_id'] ?? 0);
        if ($tagId < 1) {
            throw new HttpException('Choose a Kit tag in Settings before tagging subscribers.', 422);
        }

        $candidates = $this->audit->removalCandidatesByIds($ids, $settings);
        if ($candidates === []) {
            throw new HttpException('No selected subscribers still match the current removal rule.', 422);
        }

        $tagName = $this->tagName($tagId);
        $now = utc_now();
        $this->database->transaction(function () use ($candidates, $tagId, $tagName, $now): void {
            $this->database->execute(
                'INSERT INTO reengagement_campaigns (
                    tag_id, tag_name, status, phase, total_items, created_at, updated_at
                 ) VALUES (
                    :tag_id, :tag_name, :status, :phase, :total_items, :created_at, :updated_at
                 )',
                [
                    'tag_id' => $tagId,
                    'tag_name' => $tagName,
                    'status' => 'tagging',
                    'phase' => 'tagging',
                    'total_items' => count($candidates),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $campaignId = $this->database->lastInsertId();
            foreach ($candidates as $subscriber) {
                $this->database->execute(
                    'INSERT INTO reengagement_items (
                        campaign_id, subscriber_id, email_address, first_name, state_before, created_at,
                        last_clicked_before_tag
                     ) VALUES (
                        :campaign_id, :subscriber_id, :email_address, :first_name, :state_before, :created_at,
                        :last_clicked_before_tag
                     )',
                    [
                        'campaign_id' => $campaignId,
                        'subscriber_id' => (int) $subscriber['id'],
                        'email_address' => (string) $subscriber['email_address'],
                        'first_name' => $subscriber['first_name'],
                        'state_before' => (string) $subscriber['state'],
                        'created_at' => (string) $subscriber['created_at'],
                        'last_clicked_before_tag' => $subscriber['last_clicked'],
                    ]
                );
            }
        });

        return $this->latestProgress();
    }

    /** @return array<string, mixed> */
    public function startResync(array $settings, int $broadcastId): array
    {
        $this->ensureCanStart();
        $tagId = (int) ($settings['reengagement_tag_id'] ?? 0);
        if ($tagId < 1) {
            throw new HttpException('Choose a Kit tag in Settings before resyncing.', 422);
        }
        if ($broadcastId < 1) {
            throw new HttpException('Choose the completed broadcast used for re-engagement.', 422);
        }

        $broadcastResponse = $this->kit->getBroadcast($broadcastId);
        $broadcast = is_array($broadcastResponse['broadcast'] ?? null) ? $broadcastResponse['broadcast'] : [];
        if ((string) ($broadcast['status'] ?? '') !== 'completed') {
            throw new HttpException('Choose a completed Kit broadcast, not a draft or scheduled broadcast.', 422);
        }
        $sentAt = $this->validTimestamp($broadcast['send_at'] ?? null);
        if ($sentAt === null || strtotime($sentAt) > time()) {
            throw new HttpException('Kit did not return a valid send time for that broadcast.', 422);
        }

        $tagName = $this->tagName($tagId);
        $now = utc_now();
        $this->database->execute(
            'INSERT INTO reengagement_campaigns (
                tag_id, tag_name, status, phase, broadcast_id, broadcast_subject, broadcast_sent_at,
                created_at, resync_started_at, updated_at
             ) VALUES (
                :tag_id, :tag_name, :status, :phase, :broadcast_id, :broadcast_subject, :broadcast_sent_at,
                :created_at, :resync_started_at, :updated_at
             )',
            [
                'tag_id' => $tagId,
                'tag_name' => $tagName,
                'status' => 'resyncing',
                'phase' => 'fetching_tag_members',
                'broadcast_id' => $broadcastId,
                'broadcast_subject' => (string) ($broadcast['subject'] ?? '(No subject)'),
                'broadcast_sent_at' => $sentAt,
                'created_at' => $now,
                'resync_started_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $this->latestProgress();
    }

    /** @return array<string, mixed> */
    public function step(int $batchSize): array
    {
        $campaign = $this->database->fetchOne(
            "SELECT * FROM reengagement_campaigns WHERE status IN ('tagging', 'resyncing') ORDER BY id DESC LIMIT 1"
        );
        if ($campaign === null) {
            return $this->latestProgress();
        }

        $campaignId = (int) $campaign['id'];
        try {
            if ($campaign['phase'] === 'tagging') {
                $this->tagBatch($campaignId, max(1, min(100, $batchSize)));
            } elseif ($campaign['phase'] === 'fetching_tag_members') {
                $this->fetchTagMembers($campaign);
            } elseif ($campaign['phase'] === 'fetching_stats') {
                $this->fetchStatsBatch($campaign, max(1, min(100, $batchSize)));
            } else {
                $this->fail($campaignId, 'The re-engagement run entered an unknown phase.');
            }
        } catch (KitApiException $exception) {
            $this->fail($campaignId, $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->fail($campaignId, 'Unexpected re-engagement error: ' . $exception->getMessage());
        }

        return $this->progress($campaignId);
    }

    public function launchWorker(int $batchSize, string $workerPath, string $logPath): void
    {
        if (!function_exists('proc_open')) {
            throw new HttpException('The PHP process extension is required to run re-engagement workers.', 500);
        }

        $phpBinary = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
        if (!is_executable($phpBinary)) {
            $phpBinary = preg_replace('/-fpm$/', '', PHP_BINARY) ?: PHP_BINARY;
        }
        if (!is_executable($phpBinary)) {
            throw new HttpException('Unable to locate the PHP CLI binary for the re-engagement worker.', 500);
        }

        $command = 'nohup ' . escapeshellarg($phpBinary)
            . ' ' . escapeshellarg($workerPath)
            . ' --batch-size=' . max(1, min(100, $batchSize))
            . ' >> ' . escapeshellarg($logPath) . ' 2>&1 < /dev/null &';
        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $logPath, 'ab'],
                2 => ['file', $logPath, 'ab'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            throw new HttpException('Unable to start the local re-engagement worker.', 500);
        }
        if (file_exists($logPath)) {
            chmod($logPath, 0600);
        }
        proc_close($process);
    }

    /** @return array<string, mixed> */
    public function latestProgress(): array
    {
        $campaign = $this->database->fetchOne('SELECT * FROM reengagement_campaigns ORDER BY id DESC LIMIT 1');
        return $campaign === null ? ['status' => 'idle', 'message' => 'No re-engagement run has started.'] : $this->progress((int) $campaign['id']);
    }

    /** @return list<array<string, mixed>> */
    public function staleRows(?int $campaignId = null): array
    {
        $campaignId ??= $this->latestCampaignId();
        if ($campaignId === null) {
            return [];
        }

        return $this->database->fetchAll(
            "SELECT i.*, s.state, s.last_clicked, s.stats_updated_at
             FROM reengagement_items i
             JOIN subscribers s ON s.id = i.subscriber_id
             WHERE i.campaign_id = :campaign_id
               AND i.resync_status = 'complete'
               AND i.click_status = 'stale'
             ORDER BY i.created_at ASC, i.id ASC",
            ['campaign_id' => $campaignId]
        );
    }

    private function tagBatch(int $campaignId, int $batchSize): void
    {
        $items = $this->database->fetchAll(
            "SELECT * FROM reengagement_items WHERE campaign_id = :campaign_id AND tag_status = 'pending' ORDER BY id ASC LIMIT " . $batchSize,
            ['campaign_id' => $campaignId]
        );
        if ($items === []) {
            $this->finishTagging($campaignId);
            return;
        }

        $campaign = $this->database->fetchOne('SELECT * FROM reengagement_campaigns WHERE id = :id', ['id' => $campaignId]);
        if ($campaign === null) {
            return;
        }
        foreach ($items as $item) {
            try {
                $this->kit->tagSubscriber((int) $campaign['tag_id'], (int) $item['subscriber_id']);
                $this->database->execute(
                    "UPDATE reengagement_items SET tag_status = 'tagged', processed_at = :processed_at, error_message = NULL WHERE id = :id",
                    ['processed_at' => utc_now(), 'id' => (int) $item['id']]
                );
                $this->database->execute(
                    'UPDATE reengagement_campaigns SET successful_items = successful_items + 1, updated_at = :updated_at WHERE id = :id',
                    ['updated_at' => utc_now(), 'id' => $campaignId]
                );
            } catch (KitApiException $exception) {
                $this->database->execute(
                    "UPDATE reengagement_items SET tag_status = 'failed', processed_at = :processed_at, error_message = :error_message WHERE id = :id",
                    [
                        'processed_at' => utc_now(),
                        'error_message' => $exception->getMessage(),
                        'id' => (int) $item['id'],
                    ]
                );
                $this->database->execute(
                    'UPDATE reengagement_campaigns SET failed_items = failed_items + 1, updated_at = :updated_at WHERE id = :id',
                    ['updated_at' => utc_now(), 'id' => $campaignId]
                );
            }
        }

        $this->database->execute(
            'UPDATE reengagement_campaigns SET updated_at = :updated_at WHERE id = :id',
            ['updated_at' => utc_now(), 'id' => $campaignId]
        );
    }

    private function finishTagging(int $campaignId): void
    {
        $this->database->execute(
            "UPDATE reengagement_campaigns
             SET status = CASE WHEN failed_items > 0 THEN 'tagged_with_errors' ELSE 'tagged' END,
                 phase = 'tagged', tagged_at = :tagged_at,
                 processed_items = successful_items + failed_items, updated_at = :updated_at
             WHERE id = :id",
            ['tagged_at' => utc_now(), 'updated_at' => utc_now(), 'id' => $campaignId]
        );
    }

    /** @param array<string, mixed> $campaign */
    private function fetchTagMembers(array $campaign): void
    {
        $after = $campaign['tag_page_cursor'] !== null && $campaign['tag_page_cursor'] !== ''
            ? (string) $campaign['tag_page_cursor']
            : null;
        $response = $this->kit->listTagSubscribers((int) $campaign['tag_id'], $after);
        $subscribers = $response['subscribers'] ?? [];
        if (!is_array($subscribers)) {
            throw new KitApiException('Kit returned an invalid tagged subscriber list.');
        }

        $campaignId = (int) $campaign['id'];
        $this->database->transaction(function () use ($subscribers, $campaignId): void {
            foreach ($subscribers as $subscriber) {
                if (!is_array($subscriber) || !isset($subscriber['id'], $subscriber['email_address'], $subscriber['created_at'])) {
                    continue;
                }
                $this->upsertTaggedSubscriber($subscriber);
                $this->database->execute(
                    'INSERT OR IGNORE INTO reengagement_items (
                        campaign_id, subscriber_id, email_address, first_name, state_before, created_at,
                        last_clicked_before_tag, tag_status, resync_status
                     ) VALUES (
                        :campaign_id, :subscriber_id, :email_address, :first_name, :state_before, :created_at,
                        :last_clicked_before_tag, :tag_status, :resync_status
                     )',
                    [
                        'campaign_id' => $campaignId,
                        'subscriber_id' => (int) $subscriber['id'],
                        'email_address' => (string) $subscriber['email_address'],
                        'first_name' => $subscriber['first_name'] ?? null,
                        'state_before' => (string) ($subscriber['state'] ?? 'active'),
                        'created_at' => (string) $subscriber['created_at'],
                        'last_clicked_before_tag' => null,
                        'tag_status' => 'tagged',
                        'resync_status' => 'pending',
                    ]
                );
            }
        });

        $pagination = is_array($response['pagination'] ?? null) ? $response['pagination'] : [];
        $hasNext = (bool) ($pagination['has_next_page'] ?? false);
        $nextCursor = $hasNext ? (string) ($pagination['end_cursor'] ?? '') : null;
        if ($hasNext && ($nextCursor === '' || $nextCursor === $after)) {
            throw new KitApiException('Kit indicated another tagged subscriber page but did not return a valid cursor.');
        }

        $this->database->execute(
            'UPDATE reengagement_campaigns SET
                phase = :phase, tag_page_cursor = :tag_page_cursor, tag_page_number = tag_page_number + 1,
                total_items = (SELECT COUNT(*) FROM reengagement_items WHERE campaign_id = :count_campaign_id),
                updated_at = :updated_at
             WHERE id = :id',
            [
                'phase' => $hasNext ? 'fetching_tag_members' : 'fetching_stats',
                'tag_page_cursor' => $nextCursor,
                'count_campaign_id' => $campaignId,
                'updated_at' => utc_now(),
                'id' => $campaignId,
            ]
        );
    }

    /** @param array<string, mixed> $subscriber */
    private function upsertTaggedSubscriber(array $subscriber): void
    {
        $now = utc_now();
        $this->database->execute(
            'INSERT INTO subscribers (
                id, email_address, first_name, state, created_at, canceled_at, raw_subscriber_json,
                created_local_at, updated_local_at
             ) VALUES (
                :id, :email_address, :first_name, :state, :created_at, :canceled_at, :raw_subscriber_json,
                :created_local_at, :updated_local_at
             ) ON CONFLICT(id) DO UPDATE SET
                email_address = excluded.email_address,
                first_name = excluded.first_name,
                state = excluded.state,
                created_at = excluded.created_at,
                canceled_at = excluded.canceled_at,
                raw_subscriber_json = excluded.raw_subscriber_json,
                updated_local_at = excluded.updated_local_at',
            [
                'id' => (int) $subscriber['id'],
                'email_address' => (string) $subscriber['email_address'],
                'first_name' => $subscriber['first_name'] ?? null,
                'state' => (string) ($subscriber['state'] ?? 'active'),
                'created_at' => (string) $subscriber['created_at'],
                'canceled_at' => $subscriber['canceled_at'] ?? null,
                'raw_subscriber_json' => json_encode($subscriber, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_local_at' => $now,
                'updated_local_at' => $now,
            ]
        );
    }

    /** @param array<string, mixed> $campaign */
    private function fetchStatsBatch(array $campaign, int $batchSize): void
    {
        $items = $this->database->fetchAll(
            "SELECT * FROM reengagement_items
             WHERE campaign_id = :campaign_id AND tag_status = 'tagged' AND resync_status = 'pending'
             ORDER BY id ASC LIMIT " . $batchSize,
            ['campaign_id' => (int) $campaign['id']]
        );
        if ($items === []) {
            $this->finishResync((int) $campaign['id']);
            return;
        }

        $sentAfter = (new DateTimeImmutable((string) $campaign['broadcast_sent_at'], new DateTimeZone('UTC')))->format('Y-m-d');
        foreach ($items as $item) {
            try {
                $response = $this->kit->getSubscriberStats((int) $item['subscriber_id'], $sentAfter);
                $stats = $response['subscriber']['stats'] ?? [];
                if (!is_array($stats)) {
                    throw new KitApiException('Kit returned invalid stats for subscriber ' . (int) $item['subscriber_id'] . '.');
                }
                $lastClicked = $this->validTimestamp($stats['last_clicked'] ?? null);
                $clicked = $lastClicked !== null && $this->isAfter($lastClicked, (string) $campaign['broadcast_sent_at']);
                $this->database->execute(
                    "UPDATE reengagement_items SET resync_status = 'complete', click_status = :click_status,
                        last_clicked_since_broadcast = :last_clicked_since_broadcast, stats_synced_at = :stats_synced_at,
                        processed_at = :processed_at, evaluated_at = :evaluated_at, error_message = NULL
                     WHERE id = :id",
                    [
                        'click_status' => $clicked ? 'clicked' : 'stale',
                        'last_clicked_since_broadcast' => $lastClicked,
                        'stats_synced_at' => utc_now(),
                        'processed_at' => utc_now(),
                        'evaluated_at' => utc_now(),
                        'id' => (int) $item['id'],
                    ]
                );
                $this->database->execute(
                    'UPDATE reengagement_campaigns SET successful_items = successful_items + 1, updated_at = :updated_at WHERE id = :id',
                    ['updated_at' => utc_now(), 'id' => (int) $campaign['id']]
                );
            } catch (KitApiException $exception) {
                $this->database->execute(
                    "UPDATE reengagement_items SET resync_status = 'failed', processed_at = :processed_at, error_message = :error_message WHERE id = :id",
                    [
                        'processed_at' => utc_now(),
                        'error_message' => $exception->getMessage(),
                        'id' => (int) $item['id'],
                    ]
                );
                $this->database->execute(
                    'UPDATE reengagement_campaigns SET failed_items = failed_items + 1, updated_at = :updated_at WHERE id = :id',
                    ['updated_at' => utc_now(), 'id' => (int) $campaign['id']]
                );
            }
        }

        $this->database->execute(
            'UPDATE reengagement_campaigns SET updated_at = :updated_at WHERE id = :id',
            ['updated_at' => utc_now(), 'id' => (int) $campaign['id']]
        );
    }

    private function finishResync(int $campaignId): void
    {
        $this->database->execute(
            "UPDATE reengagement_campaigns
             SET status = CASE WHEN failed_items > 0 THEN 'complete_with_errors' ELSE 'complete' END,
                 phase = 'complete', finished_at = :finished_at,
                 processed_items = successful_items + failed_items, updated_at = :updated_at
             WHERE id = :id",
            ['finished_at' => utc_now(), 'updated_at' => utc_now(), 'id' => $campaignId]
        );
    }

    private function fail(int $campaignId, string $message): void
    {
        $this->database->execute(
            "UPDATE reengagement_campaigns SET status = 'failed', phase = 'failed', error_message = :error_message,
                updated_at = :updated_at, finished_at = :finished_at WHERE id = :id",
            [
                'error_message' => $message,
                'updated_at' => utc_now(),
                'finished_at' => utc_now(),
                'id' => $campaignId,
            ]
        );
    }

    /** @return array<string, mixed> */
    private function progress(int $campaignId): array
    {
        $campaign = $this->database->fetchOne('SELECT * FROM reengagement_campaigns WHERE id = :id', ['id' => $campaignId]);
        if ($campaign === null) {
            return ['status' => 'idle', 'message' => 'No re-engagement run has started.'];
        }

        $counts = $this->database->fetchOne(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN tag_status = \'tagged\' THEN 1 ELSE 0 END) AS tagged,
                    SUM(CASE WHEN tag_status = \'failed\' THEN 1 ELSE 0 END) AS tag_failed,
                    SUM(CASE WHEN resync_status = \'complete\' THEN 1 ELSE 0 END) AS resynced,
                    SUM(CASE WHEN resync_status = \'failed\' THEN 1 ELSE 0 END) AS resync_failed,
                    SUM(CASE WHEN click_status = \'clicked\' THEN 1 ELSE 0 END) AS clicked,
                    SUM(CASE WHEN click_status = \'stale\' THEN 1 ELSE 0 END) AS stale
             FROM reengagement_items WHERE campaign_id = :campaign_id',
            ['campaign_id' => $campaignId]
        ) ?? [];
        $total = (int) ($counts['total'] ?? 0);
        $phase = (string) $campaign['phase'];
        $processed = $phase === 'tagging'
            ? (int) ($counts['tagged'] ?? 0) + (int) ($counts['tag_failed'] ?? 0)
            : (int) ($counts['resynced'] ?? 0) + (int) ($counts['resync_failed'] ?? 0);
        $percent = $total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : ($phase === 'fetching_tag_members' ? 0 : 100);
        if (in_array($campaign['status'], ['tagged', 'tagged_with_errors', 'complete', 'complete_with_errors', 'failed'], true)) {
            $percent = $phase === 'tagged' ? 100 : ($total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : 100);
        }

        return [
            'id' => $campaignId,
            'status' => $campaign['status'],
            'phase' => $phase,
            'tag_id' => (int) $campaign['tag_id'],
            'tag_name' => $campaign['tag_name'],
            'broadcast_id' => $campaign['broadcast_id'] === null ? null : (int) $campaign['broadcast_id'],
            'broadcast_subject' => $campaign['broadcast_subject'],
            'broadcast_sent_at' => $campaign['broadcast_sent_at'],
            'total' => $total,
            'processed' => $processed,
            'successful' => $phase === 'tagging'
                ? (int) ($counts['tagged'] ?? 0)
                : (int) ($counts['resynced'] ?? 0),
            'failed' => (int) ($counts['tag_failed'] ?? 0) + (int) ($counts['resync_failed'] ?? 0),
            'clicked' => (int) ($counts['clicked'] ?? 0),
            'stale' => (int) ($counts['stale'] ?? 0),
            'percent' => $percent,
            'message' => $this->progressMessage($campaign, $processed, $total, $counts),
            'started_at' => $campaign['created_at'],
            'finished_at' => $campaign['finished_at'],
        ];
    }

    /** @param array<string, mixed> $campaign @param array<string, mixed> $counts */
    private function progressMessage(array $campaign, int $processed, int $total, array $counts): string
    {
        if ($campaign['status'] === 'failed') {
            return (string) ($campaign['error_message'] ?: 'The re-engagement run failed.');
        }
        if ($campaign['phase'] === 'tagging') {
            return sprintf('Applying Kit tag “%s” — %d of %d complete.', $campaign['tag_name'], $processed, $total);
        }
        if ($campaign['phase'] === 'fetching_tag_members') {
            return sprintf('Fetching current subscribers in Kit tag “%s”.', $campaign['tag_name']);
        }
        if ($campaign['phase'] === 'fetching_stats') {
            return sprintf('Refreshing clicks since “%s” — %d of %d complete.', $campaign['broadcast_subject'], $processed, $total);
        }
        if ($campaign['phase'] === 'tagged') {
            return sprintf('Tag applied to %d subscribers. Send the broadcast in Kit, then resync this tag.', (int) ($counts['tagged'] ?? 0));
        }
        return sprintf(
            'Resync complete — %d clicked since the broadcast; %d are stale; %d failed.',
            (int) ($counts['clicked'] ?? 0),
            (int) ($counts['stale'] ?? 0),
            (int) ($counts['resync_failed'] ?? 0)
        );
    }

    private function latestCampaignId(): ?int
    {
        $row = $this->database->fetchOne('SELECT id FROM reengagement_campaigns ORDER BY id DESC LIMIT 1');
        return $row === null ? null : (int) $row['id'];
    }

    private function ensureCanStart(): void
    {
        $activeSync = $this->database->fetchOne(
            "SELECT id FROM sync_runs WHERE status IN ('running', 'pending') ORDER BY id DESC LIMIT 1"
        );
        if ($activeSync !== null) {
            throw new HttpException('Wait for the active sync to finish before starting re-engagement work.', 409);
        }

        $activeCleanup = $this->database->fetchOne(
            "SELECT id FROM cleanup_jobs WHERE status IN ('pending', 'running') ORDER BY id DESC LIMIT 1"
        );
        if ($activeCleanup !== null) {
            throw new HttpException('Wait for the active cleanup job to finish before starting re-engagement work.', 409);
        }

        $activeCampaign = $this->database->fetchOne(
            "SELECT id FROM reengagement_campaigns WHERE status IN ('tagging', 'resyncing') ORDER BY id DESC LIMIT 1"
        );
        if ($activeCampaign !== null) {
            throw new HttpException('A re-engagement run is already in progress.', 409);
        }
    }

    private function tagName(int $tagId): string
    {
        foreach ($this->availableTags() as $tag) {
            if ($tag['id'] === $tagId) {
                return $tag['name'];
            }
        }

        throw new HttpException('The configured Kit tag could not be found.', 422);
    }

    private function validTimestamp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('c');
        } catch (\Exception) {
            return null;
        }
    }

    private function isAfter(string $left, string $right): bool
    {
        $leftTimestamp = strtotime($left);
        $rightTimestamp = strtotime($right);
        return $leftTimestamp !== false && $rightTimestamp !== false && $leftTimestamp > $rightTimestamp;
    }
}
