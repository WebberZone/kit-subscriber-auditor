<?php

declare(strict_types=1);

use KitAudit\AuditService;
use KitAudit\CleanupService;
use KitAudit\Config;
use KitAudit\CredentialStore;
use KitAudit\Database;
use KitAudit\KitApiClient;
use KitAudit\OAuthClient;
use KitAudit\Settings;
use KitAudit\SyncService;
use KitAudit\Template;

$projectRoot = dirname(__DIR__);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Authentication.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CredentialStore.php';
require_once __DIR__ . '/OAuthClient.php';
require_once __DIR__ . '/KitApiClient.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/SyncService.php';
require_once __DIR__ . '/CleanupService.php';
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
    'APP_URL' => getenv('APP_URL') !== false ? (string) getenv('APP_URL') : ($envValues['APP_URL'] ?? ''),
    'KIT_API_KEY' => getenv('KIT_API_KEY') !== false ? (string) getenv('KIT_API_KEY') : ($envValues['KIT_API_KEY'] ?? ''),
    'KIT_OAUTH_CLIENT_ID' => getenv('KIT_OAUTH_CLIENT_ID') !== false ? (string) getenv('KIT_OAUTH_CLIENT_ID') : ($envValues['KIT_OAUTH_CLIENT_ID'] ?? ''),
    'KIT_OAUTH_REDIRECT_URI' => getenv('KIT_OAUTH_REDIRECT_URI') !== false ? (string) getenv('KIT_OAUTH_REDIRECT_URI') : ($envValues['KIT_OAUTH_REDIRECT_URI'] ?? ''),
    'KIT_OAUTH_RETURN_URL' => getenv('KIT_OAUTH_RETURN_URL') !== false ? (string) getenv('KIT_OAUTH_RETURN_URL') : ($envValues['KIT_OAUTH_RETURN_URL'] ?? ''),
]));

date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

$database = new Database($projectRoot . '/storage/app.sqlite');
$database->migrate($projectRoot . '/database/migrations');
$settingsStore = new Settings($database);
$settings = $settingsStore->all();
$credentials = new CredentialStore($database, $config, $projectRoot . '/storage/.credentials.key');
$oauth = new OAuthClient($config);
$kit = new KitApiClient($config, $credentials, $oauth);
$audit = new AuditService($database);
$sync = new SyncService($database, $kit);
$cleanup = new CleanupService($database, $kit, $audit);
$template = new Template($projectRoot . '/app/views');
