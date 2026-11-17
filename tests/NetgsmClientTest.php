<?php

declare(strict_types=1);

namespace Netgsm\Sms\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Netgsm\Sms\NetgsmClient;
use Netgsm\Sms\NetgsmException;
use PHPUnit\Framework\TestCase;

final class NetgsmClientTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    private function client(?ArrayCache $cache = null): NetgsmClient
    {
        return new NetgsmClient('8500000000', 'secret', $cache, NetgsmClient::BASE_URL, $this->transport);
    }

    private static function accepted(): array
    {
        return ['jobId' => '179309912974612345062827', 'status' => 'QUEUED', 'accepted' => 1, 'rejected' => 0, 'rejectedItems' => [], 'rejectedItemsTruncated' => false, 'referenceId' => null];
    }

    public function testSendLogsInOnceAndPostsJsonWithBearerToken(): void
    {
        $this->transport->queueToken()->queue(202, self::accepted())->queue(202, self::accepted());
        $client = $this->client();

        $client->send('BASLIK', [['recipient' => '+905321234567', 'text' => 'Merhaba']], ['encoding' => 'TURKISH']);
        $result = $client->send('BASLIK', [['recipient' => '+905321234567', 'text' => 'Merhaba']]);

        self::assertSame('179309912974612345062827', $result['jobId']);
        self::assertCount(3, $this->transport->requests);

        $login = $this->transport->requests[0];
        self::assertSame('https://ext-api.netgsm.com.tr/auth/v3/token', $login['url']);
        self::assertSame(['username' => '8500000000', 'password' => 'secret', 'scope' => 'sms'], json_decode($login['body'], true));

        $send = $this->transport->requests[1];
        self::assertSame('POST', $send['method']);
        self::assertSame('https://ext-api.netgsm.com.tr/sms/v3/send', $send['url']);
        self::assertContains('Authorization: Bearer token-1', $send['headers']);
        self::assertSame(
            ['sender' => 'BASLIK', 'messages' => [['recipient' => '+905321234567', 'text' => 'Merhaba']], 'encoding' => 'TURKISH'],
            json_decode($send['body'], true),
        );
        self::assertStringNotContainsString('secret', $send['url'] . $send['body'] . implode("\n", $send['headers']));
    }

    public function testSendFormatsDatesWithOffset(): void
    {
        $this->transport->queueToken()->queue(202, self::accepted());
        $startAt = new DateTimeImmutable('2026-09-10 09:00:00', new DateTimeZone('Europe/Istanbul'));

        $this->client()->send('BASLIK', [['recipient' => '+905321234567', 'text' => 'x']], ['startAt' => $startAt]);

        self::assertSame('2026-09-10T09:00:00+03:00', json_decode($this->transport->requests[1]['body'], true)['startAt']);
    }

    public function testTokenIsSharedThroughCache(): void
    {
        $cache = new ArrayCache();
        $this->transport->queueToken()->queue(200, ['count' => 0, 'senders' => []])->queue(200, ['count' => 0, 'senders' => []]);

        $this->client($cache)->senders();
        $this->client($cache)->senders();

        self::assertCount(3, $this->transport->requests);
        self::assertStringEndsWith('/auth/v3/token', $this->transport->requests[0]['url']);
        self::assertStringEndsWith('/sms/v3/senders', $this->transport->requests[2]['url']);
        self::assertStringNotContainsString('secret', serialize($cache->items));
    }

    public function testExpiredCachedTokenIsRenewed(): void
    {
        $cache = new ArrayCache();
        $this->transport->queueToken('old')->queue(200, [])->queueToken('new')->queue(200, []);
        $client = $this->client($cache);

        $client->senders();
        $key = array_key_first($cache->items);
        $cache->items[$key]['renew_at'] = time() - 1;
        $client->senders();

        self::assertContains('Authorization: Bearer new', $this->transport->requests[3]['headers']);
    }

    public function testUnauthorizedResponseLogsInAgainOnce(): void
    {
        $this->transport
            ->queueToken('stale')
            ->queue(401, ['code' => 'UNAUTHENTICATED', 'message' => 'invalid or expired token'])
            ->queueToken('fresh')
            ->queue(200, ['count' => 0, 'senders' => []]);

        $this->client()->senders();

        self::assertCount(4, $this->transport->requests);
        self::assertContains('Authorization: Bearer fresh', $this->transport->requests[3]['headers']);
    }

    public function testSecondUnauthorizedResponseThrows(): void
    {
        $this->transport
            ->queueToken()
            ->queue(401, ['code' => 'UNAUTHENTICATED'])
            ->queueToken()
            ->queue(401, ['code' => 'UNAUTHENTICATED', 'requestId' => 'abc']);

        $e = $this->catch(fn () => $this->client()->senders());

        self::assertSame(401, $e->status);
        self::assertSame('UNAUTHENTICATED', $e->errorCode);
        self::assertSame('abc', $e->requestId);
    }

    public function testLoginErrorCarriesIdentityFields(): void
    {
        $this->transport->queue(401, ['code' => 'invalid_credentials', 'detail' => 'Kullanıcı adı veya şifre hatalı.', 'request_id' => 'req-1']);

        $e = $this->catch(fn () => $this->client()->senders());

        self::assertSame('invalid_credentials', $e->errorCode);
        self::assertSame('req-1', $e->requestId);
        self::assertStringNotContainsString('secret', $e->getMessage());
        self::assertCount(1, $this->transport->requests);
    }

    public function testSendServerErrorIsNotRetriedAndMayHaveBeenQueued(): void
    {
        $this->transport->queueToken()->queue(503, ['code' => 'SERVICE_UNAVAILABLE']);

        $e = $this->catch(fn () => $this->client()->send('BASLIK', [['recipient' => '+905321234567', 'text' => 'x']]));

        self::assertTrue($e->mayHaveBeenQueued);
        self::assertCount(2, $this->transport->requests);
    }

    public function testSendNetworkErrorMayHaveBeenQueued(): void
    {
        $this->transport->queueToken()->queueFailure();

        $e = $this->catch(fn () => $this->client()->send('BASLIK', [['recipient' => '+905321234567', 'text' => 'x']]));

        self::assertSame(0, $e->status);
        self::assertSame('NETWORK_ERROR', $e->errorCode);
        self::assertTrue($e->mayHaveBeenQueued);
    }

    public function testReadErrorsAreNeverMarkedAsQueued(): void
    {
        $this->transport->queueToken()->queue(503, ['code' => 'SERVICE_UNAVAILABLE'])->queueFailure();
        $client = $this->client();

        self::assertFalse($this->catch(fn () => $client->report())->mayHaveBeenQueued);
        self::assertFalse($this->catch(fn () => $client->report())->mayHaveBeenQueued);
    }

    public function testRateLimitExposesRetryAfter(): void
    {
        $this->transport
            ->queueToken()
            ->queue(429, ['code' => 'RATE_LIMITED', 'message' => 'send rate limit exceeded'], ['retry-after' => '7'])
            ->queue(429, ['code' => 'RATE_LIMITED', 'message' => 'sending is suspended for this account']);
        $client = $this->client();
        $send = fn () => $client->send('BASLIK', [['recipient' => '+905321234567', 'text' => 'x']]);

        self::assertSame(7, $this->catch($send)->retryAfter);
        self::assertNull($this->catch($send)->retryAfter);
    }

    public function testRejectedItemsAndViolationsAreExposed(): void
    {
        $rejected = [['index' => 0, 'recipient' => '05321234567', 'reason' => 'INVALID_RECIPIENT']];
        $violations = [['field' => 'encoding', 'message' => 'must be one of DEFAULT, TURKISH, UNICODE (case sensitive)']];
        $this->transport
            ->queueToken()
            ->queue(422, ['code' => 'NO_DELIVERABLE_MESSAGES', 'message' => 'all 1 messages rejected', 'rejectedItems' => $rejected])
            ->queue(400, ['code' => 'INVALID_REQUEST', 'message' => '1 field(s) rejected', 'violations' => $violations]);
        $client = $this->client();
        $send = fn () => $client->send('BASLIK', [['recipient' => '05321234567', 'text' => 'x']]);

        $e = $this->catch($send);
        self::assertSame('NO_DELIVERABLE_MESSAGES', $e->errorCode);
        self::assertSame($rejected, $e->rejectedItems);
        self::assertFalse($e->mayHaveBeenQueued);

        self::assertSame($violations, $this->catch($send)->violations);
    }

    public function testReportEncodesQuery(): void
    {
        $this->transport->queueToken()->queue(200, ['count' => 0, 'page' => 1, 'reports' => []]);
        $tz = new DateTimeZone('Europe/Istanbul');

        $this->client()->report(['111', '222'], new DateTimeImmutable('2026-08-01 00:00', $tz), new DateTimeImmutable('2026-08-31 23:59', $tz), 1);

        self::assertSame(
            'https://ext-api.netgsm.com.tr/sms/v3/report?jobIds=111%2C222&startAt=2026-08-01T00%3A00%3A00%2B03%3A00&stopAt=2026-08-31T23%3A59%3A00%2B03%3A00&page=1',
            $this->transport->requests[1]['url'],
        );
    }

    public function testBlacklistQueryEncodesPlusSign(): void
    {
        $this->transport->queueToken()->queue(200, ['count' => 0, 'page' => null, 'number' => '+905321234567', 'numbers' => []]);

        $this->client()->blacklist(['+905321234567']);

        self::assertSame('https://ext-api.netgsm.com.tr/sms/v3/blacklist?number=%2B905321234567&page=0&pageTotal=200', $this->transport->requests[1]['url']);
    }

    public function testBlacklistAddAndRemove(): void
    {
        $this->transport
            ->queueToken()
            ->queue(200, ['added' => 1, 'existing' => 0, 'addedNumbers' => ['+905321234567'], 'existingNumbers' => []])
            ->queue(200, ['removed' => 1, 'notFound' => 0, 'removedNumbers' => ['+905321234567'], 'notFoundNumbers' => []]);
        $client = $this->client();

        $client->addToBlacklist(['+905321234567']);
        $client->removeFromBlacklist(['+905321234567']);

        [, $add, $remove] = $this->transport->requests;
        self::assertSame('POST', $add['method']);
        self::assertSame('DELETE', $remove['method']);
        self::assertSame('https://ext-api.netgsm.com.tr/sms/v3/blacklist', $remove['url']);
        self::assertSame(['numbers' => ['+905321234567']], json_decode($remove['body'], true));
    }

    public function testNonJsonSuccessBodyThrows(): void
    {
        $this->transport->queueToken()->queueRaw(200, '<html>');

        self::assertSame('INVALID_RESPONSE', $this->catch(fn () => $this->client()->senders())->errorCode);
    }

    public function testRejectsEmptyCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NetgsmClient('8500000000', '');
    }

    public function testRejectsPlainHttpOutsideLoopback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NetgsmClient('8500000000', 'secret', null, 'http://ext-api.netgsm.com.tr');
    }

    public function testAllowsPlainHttpOnLoopbackForLocalMock(): void
    {
        $this->transport->queueToken()->queue(200, []);
        $client = new NetgsmClient('8500000000', 'secret', null, 'http://127.0.0.1:4010/', $this->transport);

        $client->senders();

        self::assertSame('http://127.0.0.1:4010/sms/v3/senders', $this->transport->requests[1]['url']);
    }

    public function testDebugOutputHidesPassword(): void
    {
        self::assertStringNotContainsString('secret', print_r($this->client(), true));
    }

    private function catch(callable $fn): NetgsmException
    {
        try {
            $fn();
        } catch (NetgsmException $e) {
            return $e;
        }
        self::fail('NetgsmException was not thrown');
    }
}
