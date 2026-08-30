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
    private const API_KEY_REQUEST_INTERVAL_SECONDS = 0.55;
    private const OAUTH_REQUEST_INTERVAL_SECONDS = 0.11;
    private const MAX_ATTEMPTS = 4;

    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly ?OAuthService $oauth = null,
    )
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required.');
        }
    }

    public function hasCredentials(): bool
    {
        return $this->credentials->hasApiKey() || $this->hasOAuthCredentials();
    }

    public function hasOAuthCredentials(): bool
    {
        return $this->oauth?->isConnected() ?? $this->credentials->hasOAuthCredentials();
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
    public function getSubscriberStats(int $subscriberId, ?string $emailSentAfter = null): array
    {
        $query = [];
        if ($emailSentAfter !== null && $emailSentAfter !== '') {
            $query['email_sent_after'] = $emailSentAfter;
        }

        return $this->request('GET', '/subscribers/' . $subscriberId . '/stats', $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function createTag(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new KitApiException('Kit tag names cannot be empty.', 422);
        }

        return $this->request('POST', '/tags', [], ['name' => $name]);
    }

    /**
     * @return array<string, mixed>
     */
    public function listTags(?string $after = null): array
    {
        $query = [
            'per_page' => 1000,
        ];
        if ($after !== null && $after !== '') {
            $query['after'] = $after;
        }

        return $this->request('GET', '/tags', $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function listTagSubscribers(int $tagId, ?string $after = null, string $status = 'active', bool $includeTotalCount = false): array
    {
        if ($tagId < 1) {
            throw new KitApiException('Invalid Kit tag ID.', 422);
        }
        if (!in_array($status, ['active', 'inactive', 'bounced', 'complained', 'cancelled', 'all'], true)) {
            throw new KitApiException('Invalid Kit subscriber status.', 422);
        }

        $query = [
            'status' => $status,
            'per_page' => 1000,
            'include_total_count' => $includeTotalCount ? 'true' : 'false',
        ];
        if ($after !== null && $after !== '') {
            $query['after'] = $after;
        }

        return $this->request('GET', '/tags/' . $tagId . '/subscribers', $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function listBroadcasts(?string $status = null, ?string $after = null): array
    {
        $query = [
            'per_page' => 1000,
            'slim' => 'true',
        ];
        if ($status !== null && $status !== '') {
            $query['status'] = $status;
        }
        if ($after !== null && $after !== '') {
            $query['after'] = $after;
        }

        return $this->request('GET', '/broadcasts', $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function getBroadcast(int $broadcastId): array
    {
        if ($broadcastId < 1) {
            throw new KitApiException('Invalid Kit broadcast ID.', 422);
        }

        return $this->request('GET', '/broadcasts/' . $broadcastId);
    }

    public function tagSubscriber(int $tagId, int $subscriberId): void
    {
        if ($tagId < 1 || $subscriberId < 1) {
            throw new KitApiException('Invalid Kit tag or subscriber ID.', 422);
        }

        $this->request(
            'POST',
            '/tags/' . $tagId . '/subscribers/' . $subscriberId,
            [],
            new \stdClass()
        );
    }

    public function unsubscribeSubscriber(int $subscriberId): void
    {
        $this->request('POST', '/subscribers/' . $subscriberId . '/unsubscribe', [], new \stdClass());
    }

    /**
     * Kit processes up to 100 taggings synchronously. Larger bulk requests are asynchronous.
     * Keeping this method capped at 100 makes the local worker's result deterministic.
     *
     * @param list<array{tag_id: int, subscriber_id: int}> $taggings
     * @return array<string, mixed>
     */
    public function bulkTagSubscribers(array $taggings): array
    {
        if ($taggings === [] || count($taggings) > 100) {
            throw new KitApiException('Bulk Kit tagging accepts between 1 and 100 subscribers per request.', 422);
        }
        if (!$this->hasOAuthCredentials() || $this->oauth === null) {
            throw new KitApiException('Connect Kit via OAuth before using bulk tagging.', 422);
        }

        $normalised = [];
        foreach ($taggings as $tagging) {
            $tagId = (int) ($tagging['tag_id'] ?? 0);
            $subscriberId = (int) ($tagging['subscriber_id'] ?? 0);
            if ($tagId < 1 || $subscriberId < 1) {
                throw new KitApiException('Invalid Kit tag or subscriber ID.', 422);
            }
            $normalised[] = ['tag_id' => $tagId, 'subscriber_id' => $subscriberId];
        }

        return $this->request(
            'POST',
            '/bulk/tags/subscribers',
            [],
            ['taggings' => $normalised],
            $this->oauth->accessToken()
        );
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string>|object|null $body
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        array $query = [],
        array|object|null $body = null,
        ?string $bearerToken = null,
    ): array
    {
        if ($bearerToken === null && $this->oauth?->isConnected()) {
            $bearerToken = $this->oauth->accessToken();
        }
        $apiKey = '';
        if ($bearerToken === null || $bearerToken === '') {
            $apiKey = $this->credentials->apiKey();
        }
        if ($bearerToken === '' && $apiKey === '') {
            throw new KitApiException('Connect Kit via OAuth or configure an API key in Settings.', 0);
        }
        if ($bearerToken === null && $apiKey === '') {
            throw new KitApiException('Connect Kit via OAuth or configure an API key in Settings.', 0);
        }
        $usingOAuth = $bearerToken !== null && $bearerToken !== '';

        $url = self::BASE_URL . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $lastError = 'Kit API request failed.';
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $this->throttle($usingOAuth);
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
            if ($usingOAuth) {
                $options[CURLOPT_HTTPHEADER][] = 'Authorization: Bearer ' . $bearerToken;
            } else {
                $options[CURLOPT_HTTPHEADER][] = 'X-Kit-Api-Key: ' . $apiKey;
            }
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            }

            curl_setopt_array($curl, $options);
            $rawResponse = curl_exec($curl);
            $curlError = curl_error($curl);
            $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if ($rawResponse === false) {
                $lastError = 'Network error while contacting Kit: ' . ($curlError ?: 'unknown cURL error');
                if ($method === 'GET' && $attempt < self::MAX_ATTEMPTS - 1) {
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
            $retryable = $statusCode === 429 || ($method === 'GET' && $statusCode >= 500);
            if ($retryable && $attempt < self::MAX_ATTEMPTS - 1) {
                $retryAfter = isset($responseHeaders['retry-after']) ? (int) $responseHeaders['retry-after'] : null;
                $this->backoff($attempt, $retryAfter);
                continue;
            }

            throw new KitApiException($lastError, $statusCode, $errors);
        }

        throw new KitApiException($lastError);
    }

    private function throttle(bool $usingOAuth): void
    {
        $elapsed = microtime(true) - $this->lastRequestAt;
        $interval = $usingOAuth ? self::OAUTH_REQUEST_INTERVAL_SECONDS : self::API_KEY_REQUEST_INTERVAL_SECONDS;
        $remaining = $interval - $elapsed;
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
