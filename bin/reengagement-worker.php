#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$lockPath = $root . '/storage/reengagement-worker.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
chmod($lockPath, 0600);

require_once $root . '/app/bootstrap.php';

$batchSize = 50;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--batch-size=')) {
        $batchSize = max(1, min(100, (int) substr($argument, 13)));
    }
}

try {
    $progress = $reengagement->latestProgress();
    while (in_array($progress['status'] ?? 'idle', ['tagging', 'resyncing'], true)) {
        $progress = $reengagement->step($batchSize);
        echo sprintf(
            "[%s] %s: %d/%d\n",
            gmdate('Y-m-d H:i:s'),
            (string) ($progress['message'] ?? $progress['status']),
            (int) ($progress['processed'] ?? 0),
            (int) ($progress['total'] ?? 0)
        );
        flush();
    }
} catch (Throwable $exception) {
    error_log('Re-engagement worker failed: ' . $exception->getMessage());
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
