#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/kit-audit-test-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);
mkdir($temporary . '/migrations', 0700, true);
copy($root . '/database/migrations/001_initial.sql', $temporary . '/migrations/001_initial.sql');
copy($root . '/database/migrations/002_credentials.sql', $temporary . '/migrations/002_credentials.sql');
copy($root . '/database/migrations/003_oauth_flows.sql', $temporary . '/migrations/003_oauth_flows.sql');

require_once $root . '/app/Config.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/CredentialStore.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/Settings.php';
require_once $root . '/app/AuditService.php';

use KitAudit\AuditService;
use KitAudit\Config;
use KitAudit\CredentialStore;
use KitAudit\Database;
use KitAudit\Settings;

$database = new Database($temporary . '/app.sqlite');
$database->migrate($temporary . '/migrations');
$credentials = new CredentialStore($database, new Config([]), $temporary . '/credentials.key');
$credentials->saveOAuthFlow('test-state', 'test-code-verifier', 600);
if ($credentials->consumeOAuthFlow('wrong-state') !== null || $credentials->consumeOAuthFlow('test-state') !== 'test-code-verifier' || $credentials->consumeOAuthFlow('test-state') !== null) {
    throw new RuntimeException('OAuth flow state test failed.');
}
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

$audit = new AuditService($database);
$metrics = $audit->dashboardMetrics($settings);
if ($metrics['total_active'] !== 3 || $metrics['removal_candidates'] !== 1 || $metrics['very_cold'] !== 2) {
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

echo "Audit service tests passed.\n";
