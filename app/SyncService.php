<?php

declare(strict_types=1);

namespace KitAudit;

final class SyncService
{
    private const STATS_REQUEST_INTERVAL_SECONDS = 0.55;
    private const MAX_STEP_SECONDS = 45.0;

    public function __construct(
        private readonly Database $database,
        private readonly KitApiClient $kit,
    ) {
    }

    public function start(int $batchSize): array
    {
        $active = $this->database->fetchOne(
            "SELECT * FROM sync_runs WHERE status IN ('running', 'pending') ORDER BY id DESC LIMIT 1"
        );
        if ($active !== null) {
            return $this->progress((int) $active['id']);
        }

        $now = utc_now();
        $this->database->execute(
            'INSERT INTO sync_runs (status, phase, page_number, last_message, started_at, updated_at)
             VALUES (:status, :phase, 0, :message, :started_at, :updated_at)',
            [
                'status' => 'running',
                'phase' => 'fetching_subscribers',
                'message' => 'Starting subscriber sync.',
                'started_at' => $now,
                'updated_at' => $now,
            ]
        );
        $runId = $this->database->lastInsertId();

        return $this->progress($runId);
    }

    public function step(int $batchSize, ?float $maxSeconds = self::MAX_STEP_SECONDS): array
    {
        $run = $this->database->fetchOne(
            "SELECT * FROM sync_runs WHERE status = 'running' ORDER BY id DESC LIMIT 1"
        );
        if ($run === null) {
            $latest = $this->database->fetchOne('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1');
            return $latest === null ? ['status' => 'idle', 'message' => 'No sync has run yet.'] : $this->progress((int) $latest['id']);
        }

        $runId = (int) $run['id'];
        try {
            if ($run['phase'] === 'fetching_subscribers') {
                $this->fetchSubscriberPage($run, $runId);
            } elseif ($run['phase'] === 'fetching_stats') {
                $this->fetchStatsBatch($runId, max(1, min(100, $batchSize)), $maxSeconds);
            } else {
                $this->fail($runId, 'The sync entered an unknown phase.');
            }
        } catch (KitApiException $exception) {
            $this->fail($runId, $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->fail($runId, 'Unexpected sync error: ' . $exception->getMessage());
        }

        return $this->progress($runId);
    }

    public function launchWorker(int $batchSize, string $workerPath, string $logPath): void
    {
        if (!function_exists('proc_open')) {
            throw new HttpException('The PHP process extension is required to run background syncs.', 500);
        }

        $phpBinary = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
        if (!is_executable($phpBinary)) {
            $phpBinary = preg_replace('/-fpm$/', '', PHP_BINARY) ?: PHP_BINARY;
        }
        if (!is_executable($phpBinary)) {
            throw new HttpException('Unable to locate the PHP CLI binary for background sync.', 500);
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
            throw new HttpException('Unable to start the local sync worker.', 500);
        }
        proc_close($process);
    }

    /** @return array<string, mixed> */
    public function latestProgress(): array
    {
        $run = $this->database->fetchOne('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1');
        return $run === null ? ['status' => 'idle', 'message' => 'No sync has run yet.'] : $this->progress((int) $run['id']);
    }

    /** @param array<string, mixed> $run */
    private function fetchSubscriberPage(array $run, int $runId): void
    {
        $pageNumber = (int) $run['page_number'] + 1;
        $after = $run['page_cursor'] !== null && $run['page_cursor'] !== '' ? (string) $run['page_cursor'] : null;
        $response = $this->kit->listSubscribers($after, 1000, $pageNumber === 1);
        $subscribers = $response['subscribers'] ?? [];
        $pagination = is_array($response['pagination'] ?? null) ? $response['pagination'] : [];
        if (!is_array($subscribers)) {
            throw new KitApiException('Kit returned an invalid subscriber list.');
        }

        $this->database->transaction(function () use ($subscribers, $runId): void {
            foreach ($subscribers as $subscriber) {
                if (!is_array($subscriber) || !isset($subscriber['id'], $subscriber['email_address'], $subscriber['created_at'])) {
                    continue;
                }
                $this->upsertSubscriber($subscriber, $runId);
                $this->database->execute(
                    'INSERT OR IGNORE INTO sync_queue (run_id, subscriber_id, status) VALUES (:run_id, :subscriber_id, :status)',
                    ['run_id' => $runId, 'subscriber_id' => (int) $subscriber['id'], 'status' => 'pending']
                );
            }
        });

        $hasNext = (bool) ($pagination['has_next_page'] ?? false);
        $nextCursor = $hasNext ? (string) ($pagination['end_cursor'] ?? '') : null;
        if ($hasNext && $nextCursor === '') {
            throw new KitApiException('Kit indicated another page but did not return an end cursor.');
        }

        $total = isset($pagination['total_count']) ? (int) $pagination['total_count'] : null;
        $now = utc_now();
        $this->database->execute(
            'UPDATE sync_runs
             SET phase = :phase, page_cursor = :page_cursor, page_number = :page_number,
                 total_subscribers = COALESCE(:total_subscribers, total_subscribers),
                 last_message = :last_message, updated_at = :updated_at
             WHERE id = :id',
            [
                'phase' => $hasNext ? 'fetching_subscribers' : 'fetching_stats',
                'page_cursor' => $nextCursor,
                'page_number' => $pageNumber,
                'total_subscribers' => $total,
                'last_message' => sprintf('Fetched subscriber page %d (%d records).', $pageNumber, count($subscribers)),
                'updated_at' => $now,
                'id' => $runId,
            ]
        );
    }

    private function fetchStatsBatch(int $runId, int $batchSize, ?float $maxSeconds): void
    {
        $queue = $this->database->fetchAll(
            "SELECT q.id AS queue_id, q.subscriber_id
             FROM sync_queue q
             WHERE q.run_id = :run_id AND q.status = 'pending'
             ORDER BY q.id ASC LIMIT " . $batchSize,
            ['run_id' => $runId]
        );

        if ($queue === []) {
            $this->database->execute(
                "UPDATE sync_runs SET status = CASE WHEN failed_subscribers > 0 THEN 'completed_with_errors' ELSE 'completed' END,
                 phase = 'complete', finished_at = :finished_at, updated_at = :updated_at,
                 last_message = :last_message WHERE id = :id",
                [
                    'finished_at' => utc_now(),
                    'updated_at' => utc_now(),
                    'last_message' => 'Sync complete. Cached subscriber data is ready.',
                    'id' => $runId,
                ]
            );
            return;
        }

        $stepStartedAt = microtime(true);
        $processedThisStep = 0;
        foreach ($queue as $item) {
            if ($maxSeconds !== null && $processedThisStep > 0 && microtime(true) - $stepStartedAt >= $maxSeconds) {
                break;
            }

            $queueId = (int) $item['queue_id'];
            $subscriberId = (int) $item['subscriber_id'];
            $this->database->execute(
                'UPDATE sync_queue SET attempts = attempts + 1 WHERE id = :id',
                ['id' => $queueId]
            );

            try {
                $response = $this->kit->getSubscriberStats($subscriberId);
                $stats = $response['subscriber']['stats'] ?? [];
                if (!is_array($stats)) {
                    throw new KitApiException('Kit returned invalid stats for subscriber ' . $subscriberId . '.');
                }
                $this->saveStats($subscriberId, $stats, $response);
                $this->database->execute(
                    "UPDATE sync_queue SET status = 'complete', processed_at = :processed_at, error_message = NULL WHERE id = :id",
                    ['processed_at' => utc_now(), 'id' => $queueId]
                );
                $processedThisStep++;
            } catch (KitApiException $exception) {
                $this->database->execute(
                    "UPDATE sync_queue SET status = 'failed', processed_at = :processed_at, error_message = :error_message WHERE id = :id",
                    [
                        'processed_at' => utc_now(),
                        'error_message' => $exception->getMessage(),
                        'id' => $queueId,
                    ]
                );
                $this->database->execute(
                    'UPDATE subscribers SET last_sync_error = :error, updated_local_at = :updated_at WHERE id = :id',
                    ['error' => $exception->getMessage(), 'updated_at' => utc_now(), 'id' => $subscriberId]
                );
                $this->database->execute(
                    'UPDATE sync_runs SET failed_subscribers = failed_subscribers + 1 WHERE id = :id',
                    ['id' => $runId]
                );
                $processedThisStep++;
            }
        }

        $this->database->execute(
            'UPDATE sync_runs SET last_message = :last_message, updated_at = :updated_at WHERE id = :id',
            [
                'last_message' => sprintf('Fetched %d subscriber stats in this step.', $processedThisStep),
                'updated_at' => utc_now(),
                'id' => $runId,
            ]
        );
    }

    /** @param array<string, mixed> $subscriber */
    private function upsertSubscriber(array $subscriber, int $runId): void
    {
        $now = utc_now();
        $this->database->execute(
            'INSERT INTO subscribers (
                id, email_address, first_name, state, created_at, canceled_at, raw_subscriber_json,
                last_seen_run_id, last_sync_error, created_local_at, updated_local_at
             ) VALUES (
                :id, :email_address, :first_name, :state, :created_at, :canceled_at, :raw_subscriber_json,
                :last_seen_run_id, NULL, :created_local_at, :updated_local_at
             ) ON CONFLICT(id) DO UPDATE SET
                email_address = excluded.email_address,
                first_name = excluded.first_name,
                state = excluded.state,
                created_at = excluded.created_at,
                canceled_at = excluded.canceled_at,
                raw_subscriber_json = excluded.raw_subscriber_json,
                last_seen_run_id = excluded.last_seen_run_id,
                last_sync_error = NULL,
                updated_local_at = excluded.updated_local_at',
            [
                'id' => (int) $subscriber['id'],
                'email_address' => (string) $subscriber['email_address'],
                'first_name' => isset($subscriber['first_name']) ? (string) $subscriber['first_name'] : null,
                'state' => (string) ($subscriber['state'] ?? 'active'),
                'created_at' => (string) $subscriber['created_at'],
                'canceled_at' => isset($subscriber['canceled_at']) ? (string) $subscriber['canceled_at'] : null,
                'raw_subscriber_json' => json_encode($subscriber, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'last_seen_run_id' => $runId,
                'created_local_at' => $now,
                'updated_local_at' => $now,
            ]
        );
    }

    /** @param array<string, mixed> $stats @param array<string, mixed> $response */
    private function saveStats(int $subscriberId, array $stats, array $response): void
    {
        $this->database->execute(
            'UPDATE subscribers SET sent = :sent, opened = :opened, clicked = :clicked, bounced = :bounced,
                last_sent = :last_sent, last_opened = :last_opened, last_clicked = :last_clicked,
                sends_since_last_open = :sends_since_last_open, sends_since_last_click = :sends_since_last_click,
                open_rate = :open_rate, click_rate = :click_rate, stats_updated_at = :stats_updated_at,
                raw_stats_json = :raw_stats_json, last_sync_error = NULL, updated_local_at = :updated_at
             WHERE id = :id',
            [
                'sent' => $this->nullableInt($stats['sent'] ?? null),
                'opened' => $this->nullableInt($stats['opened'] ?? null),
                'clicked' => $this->nullableInt($stats['clicked'] ?? null),
                'bounced' => $this->nullableInt($stats['bounced'] ?? null),
                'last_sent' => $this->nullableString($stats['last_sent'] ?? null),
                'last_opened' => $this->nullableString($stats['last_opened'] ?? null),
                'last_clicked' => $this->nullableString($stats['last_clicked'] ?? null),
                'sends_since_last_open' => $this->nullableInt($stats['sends_since_last_open'] ?? null),
                'sends_since_last_click' => $this->nullableInt($stats['sends_since_last_click'] ?? null),
                'open_rate' => $this->nullableFloat($stats['open_rate'] ?? null),
                'click_rate' => $this->nullableFloat($stats['click_rate'] ?? null),
                'stats_updated_at' => utc_now(),
                'raw_stats_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => utc_now(),
                'id' => $subscriberId,
            ]
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function fail(int $runId, string $message): void
    {
        $this->database->execute(
            "UPDATE sync_runs SET status = 'failed', phase = 'failed', error_message = :error_message,
             last_message = :last_message, finished_at = :finished_at, updated_at = :updated_at WHERE id = :id",
            [
                'error_message' => $message,
                'last_message' => $message,
                'finished_at' => utc_now(),
                'updated_at' => utc_now(),
                'id' => $runId,
            ]
        );
    }

    /** @return array<string, mixed> */
    private function progress(int $runId): array
    {
        $run = $this->database->fetchOne('SELECT * FROM sync_runs WHERE id = :id', ['id' => $runId]);
        if ($run === null) {
            return ['status' => 'idle', 'message' => 'No sync has run yet.'];
        }

        $queue = $this->database->fetchOne(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status IN (\'complete\', \'failed\') THEN 1 ELSE 0 END) AS processed,
                    SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) AS failed
             FROM sync_queue WHERE run_id = :run_id',
            ['run_id' => $runId]
        );
        $total = max((int) ($run['total_subscribers'] ?? 0), (int) ($queue['total'] ?? 0));
        $processed = (int) ($queue['processed'] ?? 0);
        $percent = $total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : 0;
        if ($run['status'] === 'completed' || $run['status'] === 'completed_with_errors') {
            $percent = 100;
        }

        return [
            'id' => $runId,
            'status' => $run['status'],
            'phase' => $run['phase'],
            'page_number' => (int) $run['page_number'],
            'total' => $total,
            'processed' => $processed,
            'failed' => (int) ($queue['failed'] ?? 0),
            'percent' => $percent,
            'message' => $this->progressMessage($run, $processed, $total),
            'elapsed_seconds' => $this->elapsedSeconds($run['started_at']),
            'estimated_remaining_seconds' => $this->estimatedRemainingSeconds($run, $processed, $total),
            'started_at' => $run['started_at'],
            'finished_at' => $run['finished_at'],
        ];
    }

    /** @param array<string, mixed> $run */
    private function progressMessage(array $run, int $processed, int $total): string
    {
        $message = (string) ($run['error_message'] ?: $run['last_message']);
        if ($run['status'] !== 'running' || $run['phase'] !== 'fetching_stats' || $total < 1) {
            return $message;
        }

        $remaining = $this->estimatedRemainingSeconds($run, $processed, $total);
        return sprintf(
            'Fetching subscriber stats — %d of %d complete. About %s remaining at the Kit API-key rate.',
            $processed,
            $total,
            $this->formatDuration($remaining)
        );
    }

    private function elapsedSeconds(mixed $startedAt): int
    {
        $started = is_string($startedAt) ? strtotime($startedAt) : false;
        return $started === false ? 0 : max(0, time() - $started);
    }

    /** @param array<string, mixed> $run */
    private function estimatedRemainingSeconds(array $run, int $processed, int $total): int
    {
        $remaining = max(0, $total - $processed);
        if ($remaining === 0) {
            return 0;
        }
        if ($processed === 0) {
            return (int) ceil($remaining * self::STATS_REQUEST_INTERVAL_SECONDS);
        }

        $elapsed = max(1, $this->elapsedSeconds($run['started_at']));
        return (int) ceil(($elapsed / $processed) * $remaining);
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        }
        $minutes = intdiv($seconds, 60);
        $hours = intdiv($minutes, 60);
        $minutes %= 60;
        return $hours > 0 ? sprintf('%dh %02dm', $hours, $minutes) : $minutes . ' minutes';
    }
}
