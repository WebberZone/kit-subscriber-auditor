<?php

declare(strict_types=1);

namespace KitAudit;

use RuntimeException;

final class OAuthClient
{
    private const AUTHORIZE_URL = 'https://api.kit.com/v4/oauth/authorize';
    private const TOKEN_URL = 'https://api.kit.com/v4/oauth/token';

    public function __construct(private readonly Config $config)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for OAuth.');
        }
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $this->config->oauthClientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->config->oauthRedirectUri(),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'scope' => 'public',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string, mixed> */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        return $this->tokenRequest([
            'client_id' => $this->config->oauthClientId(),
            'code_verifier' => $codeVerifier,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->oauthRedirectUri(),
        ]);
    }

    /** @return array<string, mixed> */
    public function refreshToken(string $refreshToken): array
    {
        return $this->tokenRequest([
            'client_id' => $this->config->oauthClientId(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /** @param array<string, string> $payload @return array<string, mixed> */
    private function tokenRequest(array $payload): array
    {
        $curl = curl_init(self::TOKEN_URL);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialise the OAuth HTTP client.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        ]);
        $rawResponse = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($rawResponse === false) {
            throw new RuntimeException('OAuth network error: ' . ($error ?: 'unknown cURL error'));
        }
        try {
            $response = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Kit OAuth returned invalid JSON.', 0, $exception);
        }
        if ($status < 200 || $status >= 300 || !is_array($response)) {
            $errors = is_array($response['errors'] ?? null) ? $response['errors'] : [];
            $message = is_array($errors) && isset($errors[0]) ? (string) $errors[0] : 'Kit OAuth request failed.';
            throw new RuntimeException($message);
        }

        return $response;
    }
}
