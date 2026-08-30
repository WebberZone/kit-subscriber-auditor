<?php

declare(strict_types=1);

namespace KitAudit;

final class Authentication
{
    private const SESSION_IDLE_SECONDS = 43200;

    public function __construct(private readonly Config $config)
    {
    }

    public function enabled(): bool
    {
        return !$this->config->allowsUnauthenticatedAccess();
    }

    public function configured(): bool
    {
        return trim($this->config->appPassword()) !== '';
    }

    public function isAuthenticated(): bool
    {
        if (!$this->enabled()) {
            return true;
        }
        if (($_SESSION['authenticated'] ?? false) !== true) {
            return false;
        }
        $lastActivity = (int) ($_SESSION['last_activity_at'] ?? 0);
        if ($lastActivity < time() - self::SESSION_IDLE_SECONDS) {
            $this->logout();
            return false;
        }
        $_SESSION['last_activity_at'] = time();

        return true;
    }

    public function login(string $password): bool
    {
        if (!$this->enabled() || !$this->configured() || !hash_equals($this->config->appPassword(), $password)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['authenticated_at'] = time();
        $_SESSION['last_activity_at'] = time();

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION['authenticated'], $_SESSION['authenticated_at'], $_SESSION['last_activity_at']);
        session_regenerate_id(true);
    }

    public function requireLogin(): void
    {
        if (!$this->isAuthenticated()) {
            redirect('/login');
        }
    }
}
