<?php

declare(strict_types=1);

namespace KitAudit;

final class CleanupService
{
    public function __construct(
        private readonly Database $database,
        private readonly KitApiClient $kit,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function start(array $ids, array $settings): array
    {
        $activeSync = $this->database->fetchOne(
            "SELECT id FROM sync_runs WHERE status IN ('running', 'pending') ORDER BY id DESC LIMIT 1"
        );
        if ($activeSync !== null) {
            throw new HttpException('Wait for the active sync to finish before starting cleanup.', 409);
        }

        $activeReengagement = $this->database->fetchOne(
            "SELECT id FROM reengagement_campaigns WHERE status IN ('tagging', 'resyncing') ORDER BY id DESC LIMIT 1"
        );
        if ($activeReengagement !== null) {
            throw new HttpException('Wait for the active re-engagement run to finish before starting cleanup.', 409);
        }

        $active = $this->database->fetchOne(
            "SELECT * FROM cleanup_jobs WHERE status IN ('pending', 'running') ORDER BY id DESC LIMIT 1"
        );
        if ($active !== null) {
            return $this->progress((int) $active['id']);
        }

        $candidates = $this->audit->removalCandidatesByIds($ids, $settings);
        if ($candidates === []) {
            throw new HttpException('No selected subscribers still match the current removal rule.', 422);
        }

        $now = utc_now();
        $dryRun = (int) ($settings['dry_run'] ?? 1) === 1;
        $this->database->transaction(function () use ($candidates, $dryRun, $now): void {
            $this->database->execute(
                'INSERT INTO cleanup_jobs (status, dry_run, total_items, created_at, updated_at)
                 VALUES (:status, :dry_run, :total_items, :created_at, :updated_at)',
                [
                    'status' => $dryRun ? 'dry_run_pending' : 'pending',
                    'dry_run' => $dryRun ? 1 : 0,
                    'total_items' => count($candidates),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $jobId = $this->database->lastInsertId();
            foreach ($candidates as $subscriber) {
                $this->database->execute(
                    'INSERT INTO cleanup_items (
                        job_id, subscriber_id, email_address, first_name, state_before, created_at,
                        last_opened, last_clicked, sent, reason
                     ) VALUES (
                        :job_id, :subscriber_id, :email_address, :first_name, :state_before, :created_at,
                        :last_opened, :last_clicked, :sent, :reason
                     )',
                    [
                        'job_id' => $jobId,
                        'subscriber_id' => (int) $subscriber['id'],
                        'email_address' => (string) $subscriber['email_address'],
                        'first_name' => $subscriber['first_name'],
                        'state_before' => (string) $subscriber['state'],
                        'created_at' => (string) $subscriber['created_at'],
                        'last_opened' => $subscriber['last_opened'],
                        'last_clicked' => $subscriber['last_clicked'],
                        'sent' => $subscriber['sent'],
                        'reason' => $this->audit->removalReason($settings),
                    ]
                );
            }
        });

        $job = $this->database->fetchOne('SELECT * FROM cleanup_jobs ORDER BY id DESC LIMIT 1');
        return $job === null ? ['status' => 'idle'] : $this->progress((int) $job['id']);
    }

    /** @return array<string, mixed> */
    public function step(int $batchSize): array
    {
        $job = $this->database->fetchOne(
            "SELECT * FROM cleanup_jobs WHERE status IN ('pending', 'running', 'dry_run_pending') ORDER BY id DESC LIMIT 1"
        );
        if ($job === null) {
            return $this->latestProgress();
        }

        $jobId = (int) $job['id'];
        $dryRun = (int) $job['dry_run'] === 1;
        if ($dryRun || $job['status'] === 'dry_run_pending') {
            $this->database->execute(
                "UPDATE cleanup_jobs SET status = 'dry_run_complete', processed_items = total_items,
                 successful_items = total_items, updated_at = :updated_at, finished_at = :finished_at
                 WHERE id = :id",
                ['updated_at' => utc_now(), 'finished_at' => utc_now(), 'id' => $jobId]
            );
            $this->database->execute(
                "UPDATE cleanup_items SET status = 'simulated', processed_at = :processed_at WHERE job_id = :job_id AND status = 'pending'",
                ['processed_at' => utc_now(), 'job_id' => $jobId]
            );
            return $this->progress($jobId);
        }

        $this->database->execute(
            "UPDATE cleanup_jobs SET status = 'running', started_at = COALESCE(started_at, :started_at), updated_at = :updated_at WHERE id = :id",
            ['started_at' => utc_now(), 'updated_at' => utc_now(), 'id' => $jobId]
        );
        $items = $this->database->fetchAll(
            "SELECT * FROM cleanup_items WHERE job_id = :job_id AND status = 'pending' ORDER BY id ASC LIMIT "
            . max(1, min(100, $batchSize)),
            ['job_id' => $jobId]
        );

        foreach ($items as $item) {
            try {
                $this->kit->unsubscribeSubscriber((int) $item['subscriber_id']);
                $this->database->execute(
                    "UPDATE cleanup_items SET status = 'success', processed_at = :processed_at, error_message = NULL WHERE id = :id",
                    ['processed_at' => utc_now(), 'id' => (int) $item['id']]
                );
                $this->database->execute(
                    "UPDATE subscribers SET state = 'cancelled', canceled_at = :canceled_at, updated_local_at = :updated_at,
                     last_sync_error = NULL WHERE id = :id",
                    [
                        'canceled_at' => utc_now(),
                        'updated_at' => utc_now(),
                        'id' => (int) $item['subscriber_id'],
                    ]
                );
                $this->database->execute(
                    'UPDATE cleanup_jobs SET successful_items = successful_items + 1 WHERE id = :id',
                    ['id' => $jobId]
                );
            } catch (KitApiException $exception) {
                $this->database->execute(
                    "UPDATE cleanup_items SET status = 'failed', processed_at = :processed_at, error_message = :error_message WHERE id = :id",
                    [
                        'processed_at' => utc_now(),
                        'error_message' => $exception->getMessage(),
                        'id' => (int) $item['id'],
                    ]
                );
                $this->database->execute(
                    'UPDATE cleanup_jobs SET failed_items = failed_items + 1 WHERE id = :id',
                    ['id' => $jobId]
                );
            }
        }

        $pending = $this->database->fetchOne(
            "SELECT COUNT(*) AS total FROM cleanup_items WHERE job_id = :job_id AND status = 'pending'",
            ['job_id' => $jobId]
        );
        if ((int) ($pending['total'] ?? 0) === 0) {
            $this->database->execute(
                "UPDATE cleanup_jobs SET status = CASE WHEN failed_items > 0 THEN 'completed_with_errors' ELSE 'completed' END,
                 finished_at = :finished_at, updated_at = :updated_at WHERE id = :id",
                ['finished_at' => utc_now(), 'updated_at' => utc_now(), 'id' => $jobId]
            );
        }

        return $this->progress($jobId);
    }

    /** @return array<string, mixed> */
    public function latestProgress(): array
    {
        $job = $this->database->fetchOne('SELECT * FROM cleanup_jobs ORDER BY id DESC LIMIT 1');
        return $job === null ? ['status' => 'idle', 'message' => 'No cleanup job has run yet.'] : $this->progress((int) $job['id']);
    }

    /** @return array<string, mixed> */
    private function progress(int $jobId): array
    {
        $job = $this->database->fetchOne('SELECT * FROM cleanup_jobs WHERE id = :id', ['id' => $jobId]);
        if ($job === null) {
            return ['status' => 'idle'];
        }
        $total = (int) $job['total_items'];
        $processed = (int) $job['processed_items'];
        $processed = max($processed, (int) $job['successful_items'] + (int) $job['failed_items']);
        $percent = $total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : 100;
        if (in_array($job['status'], ['completed', 'completed_with_errors', 'dry_run_complete'], true)) {
            $percent = 100;
        }

        return [
            'id' => $jobId,
            'status' => $job['status'],
            'dry_run' => (bool) $job['dry_run'],
            'total' => $total,
            'processed' => $processed,
            'successful' => (int) $job['successful_items'],
            'failed' => (int) $job['failed_items'],
            'percent' => $percent,
            'message' => $job['error_message'] ?? null,
            'started_at' => $job['started_at'],
            'finished_at' => $job['finished_at'],
        ];
    }
}
