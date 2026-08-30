<?php

declare(strict_types=1);

namespace KitAudit;

final class Config
{
    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<string, string> $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->values[$key] ?? $default;
    }

    public function apiKey(): string
    {
        return trim($this->get('KIT_API_KEY'));
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey() !== '';
    }

    public function appPassword(): string
    {
        return $this->get('APP_PASSWORD');
    }

    public function allowsUnauthenticatedAccess(): bool
    {
        return $this->get('APP_ALLOW_NO_AUTH', '0') === '1';
    }

    public function isProduction(): bool
    {
        return $this->get('APP_ENV', 'local') === 'production';
    }

}
