#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/kit-audit-test-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);
mkdir($temporary . '/migrations', 0700, true);
copy($root . '/database/migrations/001_initial.sql', $temporary . '/migrations/001_initial.sql');
copy($root . '/database/migrations/002_credentials.sql', $temporary . '/migrations/002_credentials.sql');
copy($root . '/database/migrations/004_remove_oauth.sql', $temporary . '/migrations/004_remove_oauth.sql');
copy($root . '/database/migrations/005_incremental_sync.sql', $temporary . '/migrations/005_incremental_sync.sql');
copy($root . '/database/migrations/006_reengagement.sql', $temporary . '/migrations/006_reengagement.sql');

require_once $root . '/app/Config.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/CredentialStore.php';
require_once $root . '/app/KitApiClient.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/Settings.php';
require_once $root . '/app/AuditService.php';
require_once $root . '/app/SyncService.php';
require_once $root . '/app/ReengagementService.php';

use KitAudit\AuditService;
use KitAudit\Config;
use KitAudit\CredentialStore;
use KitAudit\Database;
use KitAudit\KitApiClient;
use KitAudit\Settings;
use KitAudit\SyncService;
use function KitAudit\csv_safe;

if (csv_safe(' =SUM(A1)') !== "' =SUM(A1)" || csv_safe('normal@example.com') !== 'normal@example.com') {
    throw new RuntimeException('CSV formula-injection guard test failed.');
}

$database = new Database($temporary . '/app.sqlite');
$database->migrate($temporary . '/migrations');
$credentials = new CredentialStore($database, new Config([]), $temporary . '/credentials.key');
$credentials->saveApiKey('test-kit-key-123');
if (!$credentials->hasStoredApiKey() || $credentials->apiKey() !== 'test-kit-key-123' || $credentials->apiKeySource() !== 'encrypted SQLite') {
    throw new RuntimeException('Credential encryption test failed.');
}
$encrypted = (string) ($database->fetchOne('SELECT encrypted_api_key FROM credentials WHERE id = 1')['encrypted_api_key'] ?? '');
if ($encrypted === '' || str_contains($encrypted, 'test-kit-key-123')) {
    throw new RuntimeException('Credential storage test failed.');
}
$credentials->clearStoredApiKey();
if ($credentials->hasStoredApiKey()) {
    throw new RuntimeException('Credential removal test failed.');
}
$settingsStore = new Settings($database);
$settings = $settingsStore->all();
if ($settings['min_sends_since_engagement'] !== 6) {
    throw new RuntimeException('Minimum sends since engagement setting default test failed.');
}
if ($settings['stats_refresh_hours'] !== 24) {
    throw new RuntimeException('Stats refresh setting default test failed.');
}
$settingsStore->save(['stats_refresh_hours' => 48]);
$settings = $settingsStore->all();
if ($settings['stats_refresh_hours'] !== 48) {
    throw new RuntimeException('Stats refresh setting save test failed.');
}
$sync = new SyncService($database, new KitApiClient($credentials), $settingsStore);
$incrementalRun = $sync->start(50);
$incrementalRow = $database->fetchOne('SELECT force_full, stats_refresh_hours FROM sync_runs WHERE id = :id', ['id' => $incrementalRun['id']]);
if ((int) $incrementalRow['force_full'] !== 0 || (int) $incrementalRow['stats_refresh_hours'] !== 48) {
    throw new RuntimeException('Incremental sync run configuration test failed.');
}
$database->execute("UPDATE sync_runs SET status = 'completed', phase = 'complete' WHERE id = :id", ['id' => $incrementalRun['id']]);
$fullRun = $sync->start(50, true);
$fullRow = $database->fetchOne('SELECT force_full FROM sync_runs WHERE id = :id', ['id' => $fullRun['id']]);
if ((int) $fullRow['force_full'] !== 1) {
    throw new RuntimeException('Full sync run configuration test failed.');
}
$database->execute("UPDATE sync_runs SET status = 'completed', phase = 'complete' WHERE id = :id", ['id' => $fullRun['id']]);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$old = $now->modify('-400 days')->format('c');
$recent = $now->modify('-10 days')->format('c');

$database->execute(
    'INSERT INTO subscribers (id, email_address, first_name, state, created_at, sent, last_opened, last_clicked, updated_local_at, created_local_at)
     VALUES (1, :email, :name, :state, :created, :sent, NULL, NULL, :updated, :created_local)',
    ['email' => 'cold@example.com', 'name' => 'Cold', 'state' => 'active', 'created' => $old, 'sent' => 10, 'updated' => $now->format('c'), 'created_local' => $now->format('c')]
);
$database->execute(
    'INSERT INTO subscribers (id, email_address, first_name, state, created_at, sent, last_opened, last_clicked, updated_local_at, created_local_at)
     VALUES (2, :email, :name, :state, :created, :sent, :opened, NULL, :updated, :created_local)',
    ['email' => 'recent@example.com', 'name' => 'Recent', 'state' => 'active', 'created' => $recent, 'sent' => 20, 'opened' => $recent, 'updated' => $now->format('c'), 'created_local' => $now->format('c')]
);
$database->execute(
    'INSERT INTO subscribers (id, email_address, first_name, state, created_at, sent, updated_local_at, created_local_at)
     VALUES (3, :email, :name, :state, :created, :sent, :updated, :created_local)',
    ['email' => 'cancelled@example.com', 'name' => 'Cancelled', 'state' => 'cancelled', 'created' => $old, 'sent' => 20, 'updated' => $now->format('c'), 'created_local' => $now->format('c')]
);
$database->execute(
    'INSERT INTO subscribers (id, email_address, first_name, state, created_at, sent, updated_local_at, created_local_at)
     VALUES (4, :email, :name, :state, :created, :sent, :updated, :created_local)',
    ['email' => 'recent-cold@example.com', 'name' => 'Recent cold', 'state' => 'active', 'created' => $recent, 'sent' => 10, 'updated' => $now->format('c'), 'created_local' => $now->format('c')]
);
$database->execute(
    'INSERT INTO subscribers (id, email_address, first_name, state, created_at, sent, last_opened, last_clicked, sends_since_last_open, sends_since_last_click, updated_local_at, created_local_at)
     VALUES (5, :email, :name, :state, :created, :sent, :last_opened, :last_clicked, :sends_open, :sends_click, :updated, :created_local)',
    ['email' => 'not-cold-enough@example.com', 'name' => 'Not cold enough', 'state' => 'active', 'created' => $old, 'sent' => 10, 'last_opened' => $old, 'last_clicked' => $old, 'sends_open' => 5, 'sends_click' => 5, 'updated' => $now->format('c'), 'created_local' => $now->format('c')]
);

$audit = new AuditService($database);
$metrics = $audit->dashboardMetrics($settings);
if ($metrics['total_active'] !== 4 || $metrics['removal_candidates'] !== 1 || $metrics['very_cold'] !== 3) {
    throw new RuntimeException('Audit metrics test failed.');
}
$result = $audit->subscribers(['group' => 'removal', 'page' => 1], $settings);
if ($result['total'] !== 1 || $result['rows'][0]['email_address'] !== 'cold@example.com') {
    throw new RuntimeException('Removal filter test failed.');
}
$candidates = $audit->removalCandidatesByIds([1, 2, 999], $settings);
if (count($candidates) !== 1 || (int) $candidates[0]['id'] !== 1) {
    throw new RuntimeException('Candidate revalidation test failed.');
}
$cadenceCandidates = $audit->removalCandidatesByIds([1, 5], $settings);
if (count($cadenceCandidates) !== 1 || (int) $cadenceCandidates[0]['id'] !== 1) {
    throw new RuntimeException('Broadcast cadence cold-check test failed.');
}

echo "Audit service tests passed.\n";
