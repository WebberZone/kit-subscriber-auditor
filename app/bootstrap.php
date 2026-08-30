<?php

declare(strict_types=1);

use KitAudit\AuditService;
use KitAudit\CleanupService;
use KitAudit\Config;
use KitAudit\CredentialStore;
use KitAudit\Database;
use KitAudit\KitApiClient;
use KitAudit\ReengagementService;
use KitAudit\Settings;
use KitAudit\SyncService;
use KitAudit\Template;

$projectRoot = dirname(__DIR__);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Authentication.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CredentialStore.php';
require_once __DIR__ . '/KitApiClient.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/SyncService.php';
require_once __DIR__ . '/CleanupService.php';
require_once __DIR__ . '/ReengagementService.php';
require_once __DIR__ . '/Template.php';
require_once __DIR__ . '/helpers.php';

/** @return array<string, string> */
function load_env_file(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        $values[$key] = $value;
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }

    return $values;
}

$envValues = load_env_file($projectRoot . '/.env');
$config = new Config(array_merge($envValues, [
    'APP_ENV' => getenv('APP_ENV') !== false ? (string) getenv('APP_ENV') : ($envValues['APP_ENV'] ?? 'local'),
    'APP_PASSWORD' => getenv('APP_PASSWORD') !== false ? (string) getenv('APP_PASSWORD') : ($envValues['APP_PASSWORD'] ?? ''),
    'KIT_API_KEY' => getenv('KIT_API_KEY') !== false ? (string) getenv('KIT_API_KEY') : ($envValues['KIT_API_KEY'] ?? ''),
]));

date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
$requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$requestHostName = strtok($requestHost, ':') ?: '';
$directHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$forwardedHttps = $config->trustsProxy() && strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 2)[0])) === 'https';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $directHttps || $forwardedHttps || str_ends_with($requestHostName, '.test'),
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

$database = new Database($projectRoot . '/storage/app.sqlite');
$database->migrate($projectRoot . '/database/migrations');
$settingsStore = new Settings($database);
$settings = $settingsStore->all();
$credentials = new CredentialStore($database, $config, $projectRoot . '/storage/.credentials.key');
$kit = new KitApiClient($credentials);
$audit = new AuditService($database);
$sync = new SyncService($database, $kit, $settingsStore);
$cleanup = new CleanupService($database, $kit, $audit);
$reengagement = new ReengagementService($database, $kit, $audit);
$template = new Template($projectRoot . '/app/views');
