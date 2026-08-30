<?php

declare(strict_types=1);

namespace KitAudit;

use RuntimeException;

final class OAuthService
{
    private const FLOW_SESSION_KEY = 'kit_oauth_flow';
    private const FLOW_TTL_SECONDS = 600;
    private const REFRESH_SKEW_SECONDS = 120;

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly Config $config,
        private readonly string $refreshLockPath,
    ) {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for Kit OAuth.');
        }
    }

    public function isConfigured(): bool
    {
        return $this->config->kitOAuthClientId() !== ''
            && $this->config->kitOAuthRedirectUri() !== ''
            && $this->config->kitOAuthAuthorizeUrl() !== ''
            && $this->config->kitOAuthTokenUrl() !== ''
            && $this->config->kitOAuthReturnUri() !== '';
    }

    public function isConnected(): bool
    {
        return $this->credentials->hasOAuthCredentials();
    }

    public function status(): string
    {
        if (!$this->isConnected()) {
            return 'not connected';
        }

        return $this->credentials->oauthExpiresAt() > time() ? 'connected' : 'connected; token refresh due';
    }

    public function authorizationUrl(): string
    {
        if (!$this->isConfigured()) {
            throw new HttpException('Kit OAuth is not configured for this local app.', 500);
        }

        $verifier = $this->base64UrlEncode(random_bytes(64));
        $challenge = $this->base64UrlEncode(hash('sha256', $verifier, true));
        $nonce = bin2hex(random_bytes(16));
        $returnTo = $this->config->kitOAuthReturnUri()
            . (str_contains($this->config->kitOAuthReturnUri(), '?') ? '&' : '?')
            . 'oauth_nonce=' . rawurlencode($nonce);
        $state = $this->base64UrlEncode((string) json_encode([
            'return_to' => $returnTo,
            'client_id' => $this->config->kitOAuthClientId(),
        ], JSON_THROW_ON_ERROR));

        $_SESSION[self::FLOW_SESSION_KEY] = [
            'state' => $state,
            'verifier' => $verifier,
            'nonce' => $nonce,
            'created_at' => time(),
        ];

        $parameters = [
            'client_id' => $this->config->kitOAuthClientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->config->kitOAuthRedirectUri(),
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
        ];
        if ($this->config->kitOAuthTenantName() !== '') {
            $parameters['tenant_name'] = $this->config->kitOAuthTenantName();
        }

        return $this->config->kitOAuthAuthorizeUrl() . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, mixed> $query */
    public function handleCallback(array $query): void
    {
        $flow = $_SESSION[self::FLOW_SESSION_KEY] ?? null;
        unset($_SESSION[self::FLOW_SESSION_KEY]);
        if (!is_array($flow)) {
            throw new HttpException('The Kit OAuth session expired. Start the connection again.', 422);
        }
        if ((int) ($flow['created_at'] ?? 0) < time() - self::FLOW_TTL_SECONDS) {
            throw new HttpException('The Kit OAuth session expired. Start the connection again.', 422);
        }

        $state = $query['state'] ?? null;
        $code = $query['code'] ?? null;
        if (!is_string($state) || !is_string($code) || $state === '' || $code === '') {
            foreach (['error_description', 'error', 'error_reason'] as $key) {
                if (is_string($query[$key] ?? null) && $query[$key] !== '') {
                    throw new HttpException('Kit OAuth was not approved: ' . $this->safeOAuthError($query[$key]), 422);
                }
            }
            $keys = array_values(array_filter(array_keys($query), static fn (mixed $key): bool => is_string($key) && preg_match('/\A[a-z_]+\z/', $key) === 1));
            throw new HttpException(
                'Kit did not return an authorization code. Callback fields received: ' . ($keys === [] ? 'none' : implode(', ', $keys)) . '.',
                422
            );
        }
        if (!is_string($flow['state'] ?? null) || !hash_equals($flow['state'], $state)) {
            throw new HttpException('The Kit OAuth callback could not be verified. Start the connection again.', 422);
        }
        if (!is_string($flow['verifier'] ?? null) || !is_string($flow['nonce'] ?? null)) {
            throw new HttpException('The Kit OAuth session is invalid. Start the connection again.', 422);
        }
        if (!is_string($query['oauth_nonce'] ?? null) || !hash_equals($flow['nonce'], $query['oauth_nonce'])) {
            throw new HttpException('The Kit OAuth callback could not be verified. Start the connection again.', 422);
        }

        $stateData = $this->decodeState($state);
        if (($stateData['client_id'] ?? '') !== $this->config->kitOAuthClientId()) {
            throw new HttpException('The Kit OAuth client could not be verified. Start the connection again.', 422);
        }
        $expectedReturnTo = $this->config->kitOAuthReturnUri()
            . (str_contains($this->config->kitOAuthReturnUri(), '?') ? '&' : '?')
            . 'oauth_nonce=' . rawurlencode($flow['nonce']);
        if (($stateData['return_to'] ?? '') !== $expectedReturnTo) {
            throw new HttpException('The Kit OAuth callback destination could not be verified. Start the connection again.', 422);
        }

        $tokens = $this->tokenRequest([
            'client_id' => $this->config->kitOAuthClientId(),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->kitOAuthRedirectUri(),
            'code_verifier' => $flow['verifier'],
        ]);
        $this->saveTokenResponse($tokens);
    }

    public function accessToken(): string
    {
        if (!$this->credentials->hasOAuthCredentials()) {
            return '';
        }
        if ($this->credentials->oauthExpiresAt() > time() + self::REFRESH_SKEW_SECONDS) {
            return $this->credentials->oauthAccessToken();
        }

        $lock = fopen($this->refreshLockPath, 'c');
        if ($lock === false) {
            throw new KitApiException('Unable to lock the Kit OAuth refresh operation.', 0);
        }
        chmod($this->refreshLockPath, 0600);
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new KitApiException('Unable to lock the Kit OAuth refresh operation.', 0);
        }

        try {
            if ($this->credentials->oauthExpiresAt() > time() + self::REFRESH_SKEW_SECONDS) {
                return $this->credentials->oauthAccessToken();
            }
            $tokens = $this->tokenRequest([
                'client_id' => $this->config->kitOAuthClientId(),
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->credentials->oauthRefreshToken(),
            ]);
            $this->saveTokenResponse($tokens, $this->credentials->oauthRefreshToken());

            return $this->credentials->oauthAccessToken();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function disconnect(): void
    {
        $this->credentials->clearOAuthCredentials();
    }

    /** @return array<string, mixed> */
    private function tokenRequest(array $parameters): array
    {
        $curl = curl_init($this->config->kitOAuthTokenUrl());
        if ($curl === false) {
            throw new KitApiException('Unable to initialise the Kit OAuth client.', 0);
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ];
        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false) {
            throw new KitApiException('Network error while connecting Kit OAuth: ' . ($curlError ?: 'unknown cURL error'), 0);
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new KitApiException('Kit OAuth returned HTTP ' . $statusCode . ': ' . $this->decodeTokenError($response), $statusCode);
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new KitApiException('Kit OAuth returned invalid JSON.', $statusCode, [], $exception);
        }
        if (!is_array($decoded)) {
            throw new KitApiException('Kit OAuth returned an unexpected response.', $statusCode);
        }

        return $decoded;
    }

    /** @param array<string, mixed> $tokens */
    private function saveTokenResponse(array $tokens, ?string $fallbackRefreshToken = null): void
    {
        $accessToken = is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : '';
        $refreshToken = is_string($tokens['refresh_token'] ?? null) && $tokens['refresh_token'] !== ''
            ? $tokens['refresh_token']
            : (is_string($fallbackRefreshToken) ? $fallbackRefreshToken : '');
        $expiresIn = is_numeric($tokens['expires_in'] ?? null) ? (int) $tokens['expires_in'] : 3600;
        $scope = is_string($tokens['scope'] ?? null) ? $tokens['scope'] : $this->credentials->oauthScope();
        $this->credentials->saveOAuthTokens($accessToken, $refreshToken, $expiresIn, $scope);
    }

    /** @return array<string, mixed> */
    private function decodeState(string $state): array
    {
        $decoded = base64_decode(strtr($state, '-_', '+/'), true);
        if ($decoded === false) {
            throw new HttpException('The Kit OAuth state could not be decoded. Start the connection again.', 422);
        }
        try {
            $data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException('The Kit OAuth state was invalid. Start the connection again.', 422);
        }
        if (!is_array($data)) {
            throw new HttpException('The Kit OAuth state was invalid. Start the connection again.', 422);
        }

        return $data;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function safeOAuthError(string $error): string
    {
        $error = trim(preg_replace('/\s+/', ' ', $error) ?? 'Kit authorization was not completed.');
        return strlen($error) > 240 ? substr($error, 0, 240) . '…' : $error;
    }

    private function decodeTokenError(string $response): string
    {
        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'authorization failed';
        }
        if (!is_array($decoded)) {
            return 'authorization failed';
        }
        foreach (['error_description', 'error', 'message'] as $key) {
            if (is_string($decoded[$key] ?? null) && $decoded[$key] !== '') {
                return $this->safeOAuthError($decoded[$key]);
            }
        }

        return 'authorization failed';
    }
}
