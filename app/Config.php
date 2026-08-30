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

    public function isProduction(): bool
    {
        return $this->get('APP_ENV', 'local') === 'production';
    }

    public function trustsProxy(): bool
    {
        return $this->get('TRUST_PROXY', '0') === '1';
    }

    public function kitOAuthClientId(): string
    {
        return trim($this->get('KIT_OAUTH_CLIENT_ID', 'HXZlOCj-K5r0ufuWCtyoyo3f688VmMAYSsKg1eGvw0Y'));
    }

    public function kitOAuthRedirectUri(): string
    {
        return trim($this->get('KIT_OAUTH_REDIRECT_URI', 'https://app.kit.com/wordpress/redirect'));
    }

    public function kitOAuthAuthorizeUrl(): string
    {
        return trim($this->get('KIT_OAUTH_AUTHORIZE_URL', 'https://app.kit.com/oauth/authorize'));
    }

    public function kitOAuthTokenUrl(): string
    {
        return trim($this->get('KIT_OAUTH_TOKEN_URL', 'https://api.kit.com/oauth/token'));
    }

    public function kitOAuthReturnUri(): string
    {
        return trim($this->get('KIT_OAUTH_RETURN_URI', 'https://kit-subscriber-auditor.test/oauth/callback'));
    }

    public function kitOAuthTenantName(): string
    {
        return trim($this->get('KIT_OAUTH_TENANT_NAME', 'kit-subscriber-auditor.test'));
    }
}
