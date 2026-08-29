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
        $row = $this->database->fetchOne('SELECT encrypted_api_key FROM credentials WHERE id = 1');
        $value = $row['encrypted_api_key'] ?? null;
        return is_string($value) && $value !== '' ? $this->decrypt($value) : '';
    }

    /** @param array<string, mixed> $values */
    private function upsert(array $values): void
    {
        $current = $this->database->fetchOne('SELECT encrypted_api_key FROM credentials WHERE id = 1') ?? [];
        $record = [
            'encrypted_api_key' => array_key_exists('encrypted_api_key', $values)
                ? $values['encrypted_api_key']
                : ($current['encrypted_api_key'] ?? null),
        ];
        $this->database->execute(
            'INSERT INTO credentials (id, encrypted_api_key, updated_at)
             VALUES (1, :encrypted_api_key, :updated_at)
             ON CONFLICT(id) DO UPDATE SET
                encrypted_api_key = excluded.encrypted_api_key,
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
