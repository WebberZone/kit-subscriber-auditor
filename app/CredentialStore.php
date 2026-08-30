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
        return $this->storedApiKey() !== '';
    }

    public function hasApiKey(): bool
    {
        return $this->hasStoredApiKey() || $this->config->hasApiKey();
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
        $stored = $this->storedApiKey();
        return $stored !== '' ? $stored : $this->config->apiKey();
    }

    public function hasOAuthCredentials(): bool
    {
        return $this->oauthAccessToken() !== '' && $this->oauthRefreshToken() !== '';
    }

    public function oauthAccessToken(): string
    {
        return $this->decryptCredential('encrypted_access_token');
    }

    public function oauthRefreshToken(): string
    {
        return $this->decryptCredential('encrypted_refresh_token');
    }

    public function oauthExpiresAt(): int
    {
        $row = $this->database->fetchOne('SELECT oauth_expires_at FROM credentials WHERE id = 1');
        return isset($row['oauth_expires_at']) ? (int) $row['oauth_expires_at'] : 0;
    }

    public function oauthScope(): string
    {
        $row = $this->database->fetchOne('SELECT oauth_scope FROM credentials WHERE id = 1');
        return is_string($row['oauth_scope'] ?? null) ? (string) $row['oauth_scope'] : '';
    }

    public function oauthConnectedAt(): string
    {
        $row = $this->database->fetchOne('SELECT oauth_connected_at FROM credentials WHERE id = 1');
        return is_string($row['oauth_connected_at'] ?? null) ? (string) $row['oauth_connected_at'] : '';
    }

    public function saveOAuthTokens(string $accessToken, string $refreshToken, int $expiresIn, string $scope = ''): void
    {
        $accessToken = trim($accessToken);
        $refreshToken = trim($refreshToken);
        if ($accessToken === '' || $refreshToken === '' || strlen($accessToken) > 8192 || strlen($refreshToken) > 8192) {
            throw new HttpException('Kit did not return valid OAuth tokens.', 502);
        }
        if (preg_match('/\s/', $accessToken) === 1 || preg_match('/\s/', $refreshToken) === 1) {
            throw new HttpException('Kit returned malformed OAuth tokens.', 502);
        }

        $this->upsert([
            'encrypted_access_token' => $this->encrypt($accessToken),
            'encrypted_refresh_token' => $this->encrypt($refreshToken),
            'oauth_expires_at' => time() + max(60, min(315360000, $expiresIn > 0 ? $expiresIn : 3600)),
            'oauth_scope' => trim($scope),
            'oauth_created_at' => time(),
            'oauth_connected_at' => utc_now(),
        ]);
    }

    public function clearOAuthCredentials(): void
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

    private function storedApiKey(): string
    {
        return $this->decryptCredential('encrypted_api_key');
    }

    private function decryptCredential(string $column): string
    {
        $allowed = [
            'encrypted_api_key',
            'encrypted_access_token',
            'encrypted_refresh_token',
        ];
        if (!in_array($column, $allowed, true)) {
            throw new RuntimeException('Invalid credential column.');
        }
        $row = $this->database->fetchOne('SELECT ' . $column . ' FROM credentials WHERE id = 1');
        $value = $row[$column] ?? null;
        return is_string($value) && $value !== '' ? $this->decrypt($value) : '';
    }

    /** @param array<string, mixed> $values */
    private function upsert(array $values): void
    {
        $current = $this->database->fetchOne(
            'SELECT encrypted_api_key, encrypted_access_token, encrypted_refresh_token,
                    oauth_expires_at, oauth_scope, oauth_created_at, oauth_connected_at
             FROM credentials WHERE id = 1'
        ) ?? [];
        $record = [
            'encrypted_api_key' => array_key_exists('encrypted_api_key', $values) ? $values['encrypted_api_key'] : ($current['encrypted_api_key'] ?? null),
            'encrypted_access_token' => array_key_exists('encrypted_access_token', $values) ? $values['encrypted_access_token'] : ($current['encrypted_access_token'] ?? null),
            'encrypted_refresh_token' => array_key_exists('encrypted_refresh_token', $values) ? $values['encrypted_refresh_token'] : ($current['encrypted_refresh_token'] ?? null),
            'oauth_expires_at' => array_key_exists('oauth_expires_at', $values) ? $values['oauth_expires_at'] : ($current['oauth_expires_at'] ?? null),
            'oauth_scope' => array_key_exists('oauth_scope', $values) ? $values['oauth_scope'] : ($current['oauth_scope'] ?? null),
            'oauth_created_at' => array_key_exists('oauth_created_at', $values) ? $values['oauth_created_at'] : ($current['oauth_created_at'] ?? null),
            'oauth_connected_at' => array_key_exists('oauth_connected_at', $values) ? $values['oauth_connected_at'] : ($current['oauth_connected_at'] ?? null),
        ];
        $this->database->execute(
            'INSERT INTO credentials (
                id, encrypted_api_key, encrypted_access_token, encrypted_refresh_token,
                oauth_expires_at, oauth_scope, oauth_created_at, oauth_connected_at, updated_at
             ) VALUES (
                1, :encrypted_api_key, :encrypted_access_token, :encrypted_refresh_token,
                :oauth_expires_at, :oauth_scope, :oauth_created_at, :oauth_connected_at, :updated_at
             )
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
