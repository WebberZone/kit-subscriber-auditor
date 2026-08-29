<?php

declare(strict_types=1);

namespace KitAudit;

final class Authentication
{
    public function __construct(private readonly Config $config)
    {
    }

    public function enabled(): bool
    {
        return $this->config->appPassword() !== '';
    }

    public function isAuthenticated(): bool
    {
        return !$this->enabled() || ($_SESSION['authenticated'] ?? false) === true;
    }

    public function login(string $password): bool
    {
        if (!$this->enabled() || !hash_equals($this->config->appPassword(), $password)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION['authenticated']);
        session_regenerate_id(true);
    }

    public function requireLogin(): void
    {
        if (!$this->isAuthenticated()) {
            redirect('/login');
        }
    }
}

