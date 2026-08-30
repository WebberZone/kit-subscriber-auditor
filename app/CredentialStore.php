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
        return $this->decryptCredential('encrypted_api_key');
    }

    private function decryptCredential(string $column): string
    {
        $allowed = [
            'encrypted_api_key',
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
        $record = ['encrypted_api_key' => $values['encrypted_api_key'] ?? null];
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
        if (file_exists($keyPath)) {
            if (!is_file($keyPath) || !is_readable($keyPath)) {
                throw new RuntimeException('The local credential encryption key is not readable.');
            }
            $key = file_get_contents($keyPath);
            if (is_string($key) && strlen($key) === 32) {
                chmod($keyPath, 0600);
                return $key;
            }
            throw new RuntimeException('The local credential encryption key is invalid.');
        }
        $key = random_bytes(32);
        $handle = @fopen($keyPath, 'x');
        if ($handle === false) {
            if (is_readable($keyPath)) {
                $existingKey = file_get_contents($keyPath);
                if (is_string($existingKey) && strlen($existingKey) === 32) {
                    chmod($keyPath, 0600);
                    return $existingKey;
                }
            }
            throw new RuntimeException('Unable to create the local credential encryption key.');
        }
        $written = fwrite($handle, $key);
        fclose($handle);
        if ($written !== strlen($key)) {
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
