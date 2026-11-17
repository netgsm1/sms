<?php

declare(strict_types=1);

namespace Netgsm\Sms;

use DateTimeInterface;
use InvalidArgumentException;
use LogicException;
use Netgsm\Sms\Http\CurlTransport;
use Netgsm\Sms\Http\Transport;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * Netgsm SMS API v3 client.
 *
 * API reference: https://api-docs.netgsm.com.tr/
 */
final class NetgsmClient
{
    public const BASE_URL = 'https://ext-api.netgsm.com.tr';

    private readonly string $baseUrl;
    private readonly Transport $transport;

    /** @var array{token: string, renew_at: int}|null */
    private ?array $memoryToken = null;

    /**
     * @param string              $username Netgsm subscriber number.
     * @param string              $password Password of the API sub-user.
     * @param CacheInterface|null $cache    Shared cache for the access token. Strongly recommended under PHP-FPM:
     *                                      without it every PHP process logs in again, and logins are limited
     *                                      to 30 per minute per IP.
     */
    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly ?CacheInterface $cache = null,
        string $baseUrl = self::BASE_URL,
        ?Transport $transport = null,
    ) {
        if ($username === '' || $password === '') {
            throw new InvalidArgumentException('Netgsm username and password must not be empty.');
        }

        $baseUrl = rtrim($baseUrl, '/');
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $isLoopback = in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $isLoopback)) {
            throw new InvalidArgumentException('Netgsm base URL must use https.');
        }

        $this->baseUrl = $baseUrl;
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * Sends SMS to one or more recipients in a single job. POST /sms/v3/send
     *
     * Put all recipients in one call; do not call send() once per recipient.
     * Always check the returned rejectedItems: valid records are sent even when others are rejected.
     *
     * @param list<array{recipient: string, text: string}> $messages Recipient in E.164 format, e.g. +905321234567.
     * @param array{
     *     encoding?: 'DEFAULT'|'TURKISH'|'UNICODE',
     *     iysFilter?: 'NONE'|'BRAND'|'RETAILER'|'BRAND_AND_RETAILER',
     *     startAt?: DateTimeInterface,
     *     stopAt?: DateTimeInterface,
     *     partnerCode?: string,
     *     referenceId?: string,
     *     appName?: string,
     * } $options
     *
     * @return array{jobId: string, status: string, accepted: int, rejected: int, rejectedItems: list<array{index: int, recipient: string, reason: string}>, rejectedItemsTruncated: bool, referenceId: ?string}
     *
     * @throws NetgsmException On a 5xx or network error, mayHaveBeenQueued is true: check report() before resending.
     */
    public function send(string $sender, array $messages, array $options = []): array
    {
        foreach (['startAt', 'stopAt'] as $key) {
            if (isset($options[$key])) {
                $options[$key] = self::formatDate($options[$key]);
            }
        }

        return $this->request('POST', '/sms/v3/send', body: ['sender' => $sender, 'messages' => $messages] + $options, mayQueue: true);
    }

    /**
     * Cancels a job that was returned with status SCHEDULED. POST /sms/v3/cancel
     *
     * @return array{jobId: string, cancelledAt: string}
     */
    public function cancel(string $jobId): array
    {
        return $this->request('POST', '/sms/v3/cancel', body: ['jobId' => $jobId]);
    }

    /**
     * Reads delivery reports, 100 per page. GET /sms/v3/report
     *
     * The same job can be queried once per minute. startAt and stopAt must be given together.
     *
     * @param list<string> $jobIds
     *
     * @return array{count: int, page: int, reports: list<array<string, mixed>>}
     */
    public function report(array $jobIds = [], ?DateTimeInterface $startAt = null, ?DateTimeInterface $stopAt = null, int $page = 0): array
    {
        return $this->request('GET', '/sms/v3/report', query: [
            'jobIds' => $jobIds === [] ? null : implode(',', $jobIds),
            'startAt' => $startAt === null ? null : self::formatDate($startAt),
            'stopAt' => $stopAt === null ? null : self::formatDate($stopAt),
            'page' => $page,
        ]);
    }

    /**
     * Reads incoming SMS. GET /sms/v3/inbox
     *
     * startAt and stopAt must be given together; without them the current month is returned.
     *
     * @return array{count: int, messages: list<array<string, mixed>>, truncated: bool}
     */
    public function inbox(?DateTimeInterface $startAt = null, ?DateTimeInterface $stopAt = null): array
    {
        return $this->request('GET', '/sms/v3/inbox', query: [
            'startAt' => $startAt === null ? null : self::formatDate($startAt),
            'stopAt' => $stopAt === null ? null : self::formatDate($stopAt),
        ]);
    }

    /**
     * Lists the sender names registered to the account. GET /sms/v3/senders
     *
     * @return array{count: int, senders: list<array{name: string, type: string, brandCode: int, usable: bool, reason: ?string}>}
     */
    public function senders(): array
    {
        return $this->request('GET', '/sms/v3/senders');
    }

    /**
     * Reads the blacklist page by page, or checks up to 100 numbers. GET /sms/v3/blacklist
     *
     * @param list<string> $numbers E.164 format, e.g. +905321234567. Empty to list the blacklist.
     *
     * @return array{count: int, page: ?int, number: ?string, numbers: list<string>}
     */
    public function blacklist(array $numbers = [], int $page = 0, int $pageTotal = 200): array
    {
        return $this->request('GET', '/sms/v3/blacklist', query: [
            'number' => $numbers === [] ? null : implode(',', $numbers),
            'page' => $page,
            'pageTotal' => $pageTotal,
        ]);
    }

    /**
     * Adds up to 100 numbers to the blacklist. POST /sms/v3/blacklist
     *
     * @param list<string> $numbers E.164 format, e.g. +905321234567.
     *
     * @return array{added: int, existing: int, addedNumbers: list<string>, existingNumbers: list<string>}
     */
    public function addToBlacklist(array $numbers): array
    {
        return $this->request('POST', '/sms/v3/blacklist', body: ['numbers' => $numbers]);
    }

    /**
     * Removes up to 100 numbers from the blacklist. DELETE /sms/v3/blacklist
     *
     * @param list<string> $numbers E.164 format, e.g. +905321234567.
     *
     * @return array{removed: int, notFound: int, removedNumbers: list<string>, notFoundNumbers: list<string>}
     */
    public function removeFromBlacklist(array $numbers): array
    {
        return $this->request('DELETE', '/sms/v3/blacklist', body: ['numbers' => $numbers]);
    }

    /**
     * Reads remaining packages, campaigns and credit. GET /sms/v3/balance
     *
     * @param int         $stip    1: assets by type, 2: one-line summary, 3: assets and credit.
     * @param string|null $service Limits the list to a service group, e.g. sms or otp.
     *
     * @return array<string, mixed>
     */
    public function balance(int $stip = 1, ?string $service = null): array
    {
        return $this->request('GET', '/sms/v3/balance', query: ['stip' => $stip, 'service' => $service]);
    }

    /**
     * Keeps the password and the access token out of var_dump() and print_r() output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'baseUrl' => $this->baseUrl];
    }

    /**
     * Serializing would write the password in plain text, e.g. into a queue payload.
     * Inject the client where it is used instead of storing it on a serialized object.
     */
    public function __serialize(): array
    {
        throw new LogicException('NetgsmClient cannot be serialized because it holds credentials.');
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('NetgsmClient cannot be unserialized.');
    }

    /**
     * @param array<string, mixed>|null       $body
     * @param array<string, string|int|null> $query
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, array $query = [], bool $mayQueue = false): array
    {
        $url = $this->baseUrl . $path;
        $queryString = http_build_query(array_filter($query, static fn ($v) => $v !== null), '', '&', PHP_QUERY_RFC3986);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }
        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $relogged = false;
        while (true) {
            $token = $this->token();
            $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
            if ($payload !== null) {
                $headers[] = 'Content-Type: application/json';
            }

            $response = $this->transmit($method, $url, $headers, $payload, $mayQueue);

            if ($response['status'] === 401 && !$relogged) {
                $relogged = true;
                $this->forgetToken($token);
                continue;
            }

            return $this->decode($response, $mayQueue);
        }
    }

    private function token(): string
    {
        $cached = $this->cache !== null ? $this->cache->get($this->cacheKey()) : $this->memoryToken;
        if (is_array($cached) && is_string($cached['token'] ?? null) && ($cached['renew_at'] ?? 0) > time()) {
            return $cached['token'];
        }

        $payload = json_encode(['username' => $this->username, 'password' => $this->password, 'scope' => 'sms'], JSON_THROW_ON_ERROR);
        $response = $this->transmit('POST', $this->baseUrl . '/auth/v3/token', ['Accept: application/json', 'Content-Type: application/json'], $payload, false);
        $data = $this->decode($response, false);

        if (!is_string($data['access_token'] ?? null) || !is_int($data['expires_in'] ?? null)) {
            throw new NetgsmException('Netgsm token response is missing access_token or expires_in', $response['status'], 'INVALID_RESPONSE');
        }

        // Renew before expiry so a request never goes out with a token that expires in flight.
        $lifetime = $data['expires_in'];
        $ttl = max(1, $lifetime - min(300, intdiv($lifetime, 10)));
        $entry = ['token' => $data['access_token'], 'renew_at' => time() + $ttl];

        if ($this->cache !== null) {
            $this->cache->set($this->cacheKey(), $entry, $ttl);
        } else {
            $this->memoryToken = $entry;
        }

        return $entry['token'];
    }

    private function forgetToken(#[\SensitiveParameter] string $token): void
    {
        if ($this->cache === null) {
            $this->memoryToken = null;

            return;
        }

        // Another process may already have stored a fresh token; keep it.
        $cached = $this->cache->get($this->cacheKey());
        if (is_array($cached) && ($cached['token'] ?? null) === $token) {
            $this->cache->delete($this->cacheKey());
        }
    }

    private function cacheKey(): string
    {
        return 'netgsm_sms_token_' . hash('sha256', $this->baseUrl . '|' . $this->username);
    }

    /**
     * @param list<string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function transmit(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers,
        #[\SensitiveParameter] ?string $payload,
        bool $mayQueue,
    ): array {
        try {
            return $this->transport->send($method, $url, $headers, $payload);
        } catch (RuntimeException $e) {
            // The transport exception is not chained: its trace holds the transport's arguments, which carry
            // the password or the access token unless that Transport marks them #[\SensitiveParameter].
            throw new NetgsmException('Netgsm request failed: ' . $e->getMessage(), 0, 'NETWORK_ERROR', mayHaveBeenQueued: $mayQueue);
        }
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $response
     *
     * @return array<string, mixed>
     */
    private function decode(#[\SensitiveParameter] array $response, bool $mayQueue): array
    {
        $status = $response['status'];
        $data = json_decode($response['body'], true);

        if ($status >= 200 && $status < 300) {
            if (!is_array($data)) {
                throw new NetgsmException(sprintf('Netgsm API returned %d with a body that is not JSON', $status), $status, 'INVALID_RESPONSE');
            }

            return $data;
        }

        $retryAfter = $response['headers']['retry-after'] ?? null;

        throw NetgsmException::fromResponse(
            $status,
            is_array($data) ? $data : [],
            $retryAfter !== null && ctype_digit($retryAfter) ? (int) $retryAfter : null,
            $mayQueue && $status >= 500,
        );
    }

    private static function formatDate(DateTimeInterface $date): string
    {
        return $date->format(DateTimeInterface::ATOM);
    }
}
