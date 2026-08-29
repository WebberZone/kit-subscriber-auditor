<?php

declare(strict_types=1);

namespace KitAudit;

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $known = (string) ($_SESSION['csrf_token'] ?? '');
    if ($known === '' || $submitted === '' || !hash_equals($known, $submitted)) {
        http_response_code(419);
        throw new HttpException('Your form expired. Please reload the page and try again.', 419);
    }
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type: string, message: string}> */
function consume_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($messages) ? $messages : [];
}

function utc_now(): string
{
    return gmdate('c');
}

function format_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }

    try {
        return (new \DateTimeImmutable($date))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    } catch (\Exception) {
        return 'Unknown';
    }
}

function format_percent(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return number_format((float) $value * 100, 1) . '%';
}

function csv_safe(mixed $value): string
{
    $value = (string) ($value ?? '');
    if ($value !== '' && preg_match('/\A[\x00-\x20]*[=+\-@]/', $value) === 1) {
        return "'" . $value;
    }

    return $value;
}

/** @param array<string, scalar|null> $parameters */
function query_url(string $path, array $parameters = []): string
{
    $parameters = array_filter($parameters, static fn (mixed $value): bool => $value !== null && $value !== '');
    return $path . ($parameters === [] ? '' : '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986));
}

function date_age(?string $date): string
{
    if ($date === null || $date === '') {
        return 'Never';
    }

    try {
        $days = (int) floor((time() - (new \DateTimeImmutable($date))->getTimestamp()) / 86400);
        if ($days < 1) {
            return 'Today';
        }
        return $days . 'd ago';
    } catch (\Exception) {
        return 'Unknown';
    }
}

final class HttpException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message, $status);
    }
}
