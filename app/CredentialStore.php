<?php

declare(strict_types=1);

namespace KitAudit;

use RuntimeException;

final class CredentialStore
{
    private string $encryptionKey;

    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
        string $keyPath,
    ) {
        $this->encryptionKey = $this->loadOrCreateKey($keyPath);
    }

    public function hasStoredApiKey(): bool
    {
        return $this->storedValue('encrypted_api_key') !== '';
    }

    public function hasApiKey(): bool
    {
        return $this->hasStoredApiKey() || $this->config->hasApiKey();
    }

    public function hasAnyCredential(): bool
    {
        return $this->hasOAuthTokens() || $this->hasApiKey();
    }

    public function apiKeySource(): string
    {
        if ($this->hasStoredApiKey()) {
            return 'encrypted SQLite';
        }
        return $this->config->hasApiKey() ? '.env' : 'not configured';
    }

    public function apiKey(): string
    {
        $stored = $this->storedValue('encrypted_api_key');
        return $stored !== '' ? $stored : $this->config->apiKey();
    }

    public function hasOAuthTokens(): bool
    {
        return $this->storedValue('encrypted_access_token') !== ''
            && $this->storedValue('encrypted_refresh_token') !== '';
    }

    /** @return array<string, int|string|null> */
    public function oauthStatus(): array
    {
        $row = $this->row();
        return [
            'connected' => $this->hasOAuthTokens(),
            'expires_at' => $row['oauth_expires_at'] ?? null,
            'scope' => $row['oauth_scope'] ?? null,
            'connected_at' => $row['oauth_connected_at'] ?? null,
        ];
    }

    public function saveApiKey(string $apiKey): void
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '' || strlen($apiKey) > 512 || preg_match('/\s/', $apiKey) === 1) {
            throw new HttpException('Enter a valid Kit v4 API key without spaces.', 422);
        }
        $this->upsert(['encrypted_api_key' => $this->encrypt($apiKey)]);
    }

    public function clearStoredApiKey(): void
    {
        $this->upsert(['encrypted_api_key' => null]);
    }

    /** @param array<string, mixed> $tokens */
    public function saveOAuthTokens(array $tokens): void
    {
        $accessToken = trim((string) ($tokens['access_token'] ?? ''));
        $refreshToken = trim((string) ($tokens['refresh_token'] ?? ''));
        if ($accessToken === '' || $refreshToken === '') {
            throw new RuntimeException('Kit OAuth did not return both access and refresh tokens.');
        }
        $expiresIn = max(0, (int) ($tokens['expires_in'] ?? 0));
        $createdAt = max(0, (int) ($tokens['created_at'] ?? time()));
        $current = $this->row();
        $this->upsert([
            'encrypted_access_token' => $this->encrypt($accessToken),
            'encrypted_refresh_token' => $this->encrypt($refreshToken),
            'oauth_expires_at' => $expiresIn > 0 ? $createdAt + $expiresIn : 0,
            'oauth_scope' => (string) ($tokens['scope'] ?? ($current['oauth_scope'] ?? 'public')),
            'oauth_created_at' => $createdAt,
            'oauth_connected_at' => utc_now(),
        ]);
    }

    public function clearOAuthTokens(): void
    {
        $this->upsert([
            'encrypted_access_token' => null,
            'encrypted_refresh_token' => null,
            'oauth_expires_at' => null,
            'oauth_scope' => null,
            'oauth_created_at' => null,
            'oauth_connected_at' => null,
        ]);
    }

    public function saveOAuthFlow(string $state, string $codeVerifier, int $ttl = 600): void
    {
        if ($state === '' || $codeVerifier === '') {
            throw new RuntimeException('Unable to start the Kit OAuth flow.');
        }

        $this->database->execute('DELETE FROM oauth_flows');
        $this->database->execute(
            'INSERT INTO oauth_flows (id, state_hash, encrypted_code_verifier, expires_at, created_at)
             VALUES (1, :state_hash, :encrypted_code_verifier, :expires_at, :created_at)',
            [
                'state_hash' => hash('sha256', $state),
                'encrypted_code_verifier' => $this->encrypt($codeVerifier),
                'expires_at' => time() + max(60, $ttl),
                'created_at' => utc_now(),
            ]
        );
    }

    public function consumeOAuthFlow(string $state): ?string
    {
        if ($state === '') {
            return null;
        }

        $flow = $this->database->fetchOne('SELECT * FROM oauth_flows WHERE id = 1');
        $stateHash = hash('sha256', $state);
        if ($flow === null || !hash_equals((string) $flow['state_hash'], $stateHash)) {
            return null;
        }

        $this->database->execute('DELETE FROM oauth_flows WHERE id = 1');
        if ((int) $flow['expires_at'] < time()) {
            return null;
        }

        $verifier = $this->decrypt((string) $flow['encrypted_code_verifier']);
        return $verifier !== '' ? $verifier : null;
    }

    public function clearOAuthFlow(): void
    {
        $this->database->execute('DELETE FROM oauth_flows WHERE id = 1');
    }

    public function oauthAccessToken(): string
    {
        return $this->storedValue('encrypted_access_token');
    }

    public function oauthRefreshToken(): string
    {
        return $this->storedValue('encrypted_refresh_token');
    }

    public function oauthExpiresAt(): int
    {
        return (int) ($this->row()['oauth_expires_at'] ?? 0);
    }

    private function row(): array
    {
        return $this->database->fetchOne('SELECT * FROM credentials WHERE id = 1') ?? [];
    }

    private function storedValue(string $column): string
    {
        $value = $this->row()[$column] ?? null;
        return is_string($value) && $value !== '' ? $this->decrypt($value) : '';
    }

    /** @param array<string, mixed> $values */
    private function upsert(array $values): void
    {
        $current = $this->row();
        $record = array_merge([
            'encrypted_api_key' => $current['encrypted_api_key'] ?? null,
            'encrypted_access_token' => $current['encrypted_access_token'] ?? null,
            'encrypted_refresh_token' => $current['encrypted_refresh_token'] ?? null,
            'oauth_expires_at' => $current['oauth_expires_at'] ?? null,
            'oauth_scope' => $current['oauth_scope'] ?? null,
            'oauth_created_at' => $current['oauth_created_at'] ?? null,
            'oauth_connected_at' => $current['oauth_connected_at'] ?? null,
        ], $values);
        $this->database->execute(
            'INSERT INTO credentials (
                id, encrypted_api_key, encrypted_access_token, encrypted_refresh_token, oauth_expires_at,
                oauth_scope, oauth_created_at, oauth_connected_at, updated_at
             ) VALUES (1, :encrypted_api_key, :encrypted_access_token, :encrypted_refresh_token, :oauth_expires_at,
                :oauth_scope, :oauth_created_at, :oauth_connected_at, :updated_at)
             ON CONFLICT(id) DO UPDATE SET
                encrypted_api_key = excluded.encrypted_api_key,
                encrypted_access_token = excluded.encrypted_access_token,
                encrypted_refresh_token = excluded.encrypted_refresh_token,
                oauth_expires_at = excluded.oauth_expires_at,
                oauth_scope = excluded.oauth_scope,
                oauth_created_at = excluded.oauth_created_at,
                oauth_connected_at = excluded.oauth_connected_at,
                updated_at = excluded.updated_at',
            array_merge($record, ['updated_at' => utc_now()])
        );
    }

    private function loadOrCreateKey(string $keyPath): string
    {
        $directory = dirname($keyPath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the credential key directory.');
        }
        if (is_readable($keyPath)) {
            $key = file_get_contents($keyPath);
            if (is_string($key) && strlen($key) === 32) {
                return $key;
            }
        }
        $key = random_bytes(32);
        if (file_put_contents($keyPath, $key, LOCK_EX) === false) {
            throw new RuntimeException('Unable to create the local credential encryption key.');
        }
        chmod($keyPath, 0600);

        return $key;
    }

    private function encrypt(string $plaintext): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->encryptionKey);
            return 'sodium:v1:' . base64_encode($nonce . $ciphertext);
        }
        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(openssl_cipher_iv_length('aes-256-gcm'));
            $tag = '';
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
            if ($ciphertext !== false) {
                return 'openssl:v1:' . base64_encode($iv . $tag . $ciphertext);
            }
        }

        throw new RuntimeException('No authenticated encryption method is available.');
    }

    private function decrypt(string $value): string
    {
        $parts = explode(':', $value, 3);
        if (count($parts) !== 3 || $parts[2] === '') {
            return '';
        }
        $data = base64_decode($parts[2], true);
        if ($data === false) {
            return '';
        }
        if ($parts[0] === 'sodium' && $parts[1] === 'v1' && function_exists('sodium_crypto_secretbox_open')) {
            $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $nonce = substr($data, 0, $nonceLength);
            $ciphertext = substr($data, $nonceLength);
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->encryptionKey);
            return $plaintext === false ? '' : $plaintext;
        }
        if ($parts[0] === 'openssl' && $parts[1] === 'v1' && function_exists('openssl_decrypt')) {
            $ivLength = openssl_cipher_iv_length('aes-256-gcm');
            $iv = substr($data, 0, $ivLength);
            $tag = substr($data, $ivLength, 16);
            $ciphertext = substr($data, $ivLength + 16);
            $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
            return $plaintext === false ? '' : $plaintext;
        }

        return '';
    }
}
