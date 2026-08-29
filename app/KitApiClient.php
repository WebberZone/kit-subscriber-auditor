<?php

declare(strict_types=1);

namespace KitAudit;

use RuntimeException;

final class KitApiException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}

final class KitApiClient
{
    private const BASE_URL = 'https://api.kit.com/v4';
    private const REQUEST_INTERVAL_SECONDS = 0.55;
    private const MAX_ATTEMPTS = 4;

    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly CredentialStore $credentials,
    )
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function listSubscribers(?string $after, int $perPage, bool $includeTotalCount): array
    {
        $query = [
            'status' => 'all',
            'per_page' => min(1000, max(1, $perPage)),
            'include_total_count' => $includeTotalCount ? 'true' : 'false',
        ];
        if ($after !== null && $after !== '') {
            $query['after'] = $after;
        }

        return $this->request('GET', '/subscribers', $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubscriberStats(int $subscriberId): array
    {
        return $this->request('GET', '/subscribers/' . $subscriberId . '/stats');
    }

    public function unsubscribeSubscriber(int $subscriberId): void
    {
        $this->request('POST', '/subscribers/' . $subscriberId . '/unsubscribe', [], new \stdClass());
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string>|object|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], array|object|null $body = null): array
    {
        if (!$this->credentials->hasApiKey()) {
            throw new KitApiException('Configure a Kit API key in Settings.', 0);
        }

        $url = self::BASE_URL . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $lastError = 'Kit API request failed.';
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $this->throttle();
            $responseHeaders = [];
            $curl = curl_init($url);
            if ($curl === false) {
                throw new KitApiException('Unable to initialise the HTTP client.');
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                ],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                    $separator = strpos($headerLine, ':');
                    if ($separator !== false) {
                        $name = strtolower(trim(substr($headerLine, 0, $separator)));
                        $value = trim(substr($headerLine, $separator + 1));
                        $responseHeaders[$name] = $value;
                    }

                    return strlen($headerLine);
                },
            ];
            $options[CURLOPT_HTTPHEADER][] = 'X-Kit-Api-Key: ' . $this->credentials->apiKey();
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            }

            curl_setopt_array($curl, $options);
            $rawResponse = curl_exec($curl);
            $curlError = curl_error($curl);
            $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($rawResponse === false) {
                $lastError = 'Network error while contacting Kit: ' . ($curlError ?: 'unknown cURL error');
                if ($attempt < self::MAX_ATTEMPTS - 1) {
                    $this->backoff($attempt);
                    continue;
                }
                throw new KitApiException($lastError, 0);
            }

            if ($statusCode >= 200 && $statusCode < 300) {
                if ($statusCode === 204 || trim($rawResponse) === '') {
                    return [];
                }

                try {
                    $decoded = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new KitApiException('Kit returned invalid JSON.', $statusCode, [], $exception);
                }

                if (!is_array($decoded)) {
                    throw new KitApiException('Kit returned an unexpected response.', $statusCode);
                }

                return $decoded;
            }

            $errors = $this->decodeErrors($rawResponse);
            $lastError = $errors[0] ?? ('Kit API returned HTTP ' . $statusCode . '.');
            $retryable = $statusCode === 429 || $statusCode >= 500;
            if ($retryable && $attempt < self::MAX_ATTEMPTS - 1) {
                $retryAfter = isset($responseHeaders['retry-after']) ? (int) $responseHeaders['retry-after'] : null;
                $this->backoff($attempt, $retryAfter);
                continue;
            }

            throw new KitApiException($lastError, $statusCode, $errors);
        }

        throw new KitApiException($lastError);
    }

    private function throttle(): void
    {
        $elapsed = microtime(true) - $this->lastRequestAt;
        $remaining = self::REQUEST_INTERVAL_SECONDS - $elapsed;
        if ($remaining > 0) {
            usleep((int) round($remaining * 1_000_000));
        }
        $this->lastRequestAt = microtime(true);
    }

    private function backoff(int $attempt, ?int $retryAfter = null): void
    {
        $seconds = $retryAfter !== null && $retryAfter > 0
            ? min(30, $retryAfter)
            : min(8, 2 ** $attempt) + (random_int(0, 250) / 1000);
        usleep((int) round($seconds * 1_000_000));
    }

    /** @return list<string> */
    private function decodeErrors(string $rawResponse): array
    {
        try {
            $decoded = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded) || !isset($decoded['errors']) || !is_array($decoded['errors'])) {
            return [];
        }

        return array_values(array_filter($decoded['errors'], 'is_string'));
    }
}
