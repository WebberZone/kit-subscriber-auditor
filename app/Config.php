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

    public function appUrl(): string
    {
        $appUrl = trim($this->get('APP_URL'));
        return rtrim($appUrl !== '' ? $appUrl : 'https://kit-subscriber-auditor.test', '/');
    }

    public function oauthClientId(): string
    {
        $clientId = trim($this->get('KIT_OAUTH_CLIENT_ID'));
        return $clientId !== '' ? $clientId : 'HXZlOCj-K5r0ufuWCtyoyo3f688VmMAYSsKg1eGvw0Y';
    }

    public function oauthRedirectUri(): string
    {
        $redirectUri = trim($this->get('KIT_OAUTH_REDIRECT_URI'));
        return $redirectUri !== '' ? $redirectUri : 'https://app.kit.com/wordpress/redirect';
    }

    public function oauthReturnUrl(): string
    {
        $returnUrl = trim($this->get('KIT_OAUTH_RETURN_URL'));
        return $returnUrl !== '' ? $returnUrl : $this->appUrl() . '/oauth/callback';
    }

    public function hasOAuthConfig(): bool
    {
        return $this->oauthClientId() !== '' && $this->oauthRedirectUri() !== '' && $this->oauthReturnUrl() !== '';
    }

    public function appPassword(): string
    {
        return $this->get('APP_PASSWORD');
    }

    public function isProduction(): bool
    {
        return $this->get('APP_ENV', 'local') === 'production';
    }
}
