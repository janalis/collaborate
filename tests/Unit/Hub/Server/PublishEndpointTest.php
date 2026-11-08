<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Server;

use Janalis\Collaborate\Hub\Server\BrowserEndpoint;
use Janalis\Collaborate\Hub\Server\ClientAddress;
use Janalis\Collaborate\Hub\Server\Connection;
use Janalis\Collaborate\Hub\Server\Counter;
use Janalis\Collaborate\Hub\Server\Limits;
use Janalis\Collaborate\Hub\Server\PublishEndpoint;
use Janalis\Collaborate\Hub\Server\Router;
use Janalis\Collaborate\Hub\Server\Seconds;
use Janalis\Collaborate\Hub\Server\Server;
use Janalis\Collaborate\Hub\Server\ServerConfig;
use Janalis\Collaborate\Hub\Server\ServerFactory;
use Janalis\Collaborate\Hub\Server\Sockets;
use Janalis\Collaborate\Hub\Server\Timings;
use Janalis\Collaborate\Hub\Transport\WebSocket\Keys;
use Janalis\Collaborate\Hub\Transport\WebSocket\Origin;
use Janalis\Collaborate\Hub\Transport\WebSocket\RequestSigner;
use Janalis\Collaborate\Hub\Transport\WebSocket\SubscriberToken;
use Janalis\Collaborate\Hub\Transport\WebSocket\TokenClaims;
use Janalis\Collaborate\Hub\Transport\WebSocket\WebSocketTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(PublishEndpoint::class)]
#[CoversClass(Router::class)]
#[UsesClass(BrowserEndpoint::class)]
#[UsesClass(ClientAddress::class)]
#[UsesClass(Counter::class)]
#[UsesClass(Connection::class)]
#[UsesClass(Limits::class)]
#[UsesClass(Seconds::class)]
#[UsesClass(Server::class)]
#[UsesClass(ServerConfig::class)]
#[UsesClass(ServerFactory::class)]
#[UsesClass(Sockets::class)]
#[UsesClass(Timings::class)]
#[UsesClass(Keys::class)]
#[UsesClass(Origin::class)]
#[UsesClass(RequestSigner::class)]
#[UsesClass(SubscriberToken::class)]
#[UsesClass(TokenClaims::class)]
#[UsesClass(WebSocketTransport::class)]
final class PublishEndpointTest extends ServerTestCase
{
    private const string A = self::PREFIX . 'a';

    public function testHealthNeedsNoSignatureAndTellsNothing(): void
    {
        $this->start();
        $this->subscriber([self::A]);
        $response = $this->request('GET', '/health', '', '');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame("ok\n", (string) $response->getBody());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unknown(): iterable
    {
        yield 'another path' => ['GET', '/'];
        yield 'health by POST' => ['POST', '/health'];
        yield 'publish by GET' => ['GET', '/publish'];
        yield 'subscriptions by POST' => ['POST', '/subscriptions'];
    }

    #[DataProvider('unknown')]
    public function testAnythingElseIsNotFound(string $method, string $target): void
    {
        $this->start();

        self::assertSame(404, $this->request($method, $target)->getStatusCode());
    }

    /** @return iterable<string, array{string, string, string, \Closure(self): string}> */
    public static function unauthenticated(): iterable
    {
        $body = '{"notices":[{"topics":["urn:test:a"],"data":"{}"}]}';
        yield 'a publish without signature' => ['POST', '/publish', $body, static fn(): string => ''];
        yield 'subscriptions without signature' => [
            'GET',
            '/subscriptions?topic=urn%3Atest%3Aa',
            '',
            static fn(): string => '',
        ];
        yield 'a signature of another body' => [
            'POST',
            '/publish',
            $body,
            static fn(self $test): string => $test->signer->sign('POST', '/publish', '{}', $test->now()),
        ];
        yield 'a signature of another target' => [
            'GET',
            '/subscriptions?topic=urn%3Atest%3Aa',
            '',
            static fn(self $test): string => $test->signer->sign(
                'GET',
                '/subscriptions?topic=urn%3Atest%3Ab',
                '',
                $test->now(),
            ),
        ];
        yield 'a signature of another method' => [
            'POST',
            '/publish',
            $body,
            static fn(self $test): string => $test->signer->sign('PUT', '/publish', $body, $test->now()),
        ];
        yield 'an outdated signature' => [
            'POST',
            '/publish',
            $body,
            static fn(self $test): string => $test->signer->sign('POST', '/publish', $body, $test->now() - 61),
        ];
        yield 'another secret' => [
            'POST',
            '/publish',
            $body,
            static fn(self $test): string => new RequestSigner(new Keys(str_repeat('z', 32)))->sign(
                'POST',
                '/publish',
                $body,
                $test->now(),
            ),
        ];
    }

    /** @param \Closure(self): string $signature */
    #[DataProvider('unauthenticated')]
    public function testUnauthenticatedRequestsAreRefused(
        string $method,
        string $target,
        string $body,
        \Closure $signature,
    ): void {
        $this->start();
        $client = $this->subscriber([self::A]);

        self::assertSame(401, $this->request($method, $target, $body, $signature($this))->getStatusCode());
        $this->wait(0.02);
        self::assertSame([], $client->messages);
    }

    public function testAReplayIsRefusedForAsLongAsItsSignatureIsValid(): void
    {
        $this->start();
        $body = json_encode(['notices' => [self::notice([self::A])]], \JSON_THROW_ON_ERROR);
        // Signed 60 seconds ahead of the server's clock: still valid two minutes later.
        $signature = $this->signer->sign('POST', '/publish', $body, $this->now() + 60);

        self::assertSame(204, $this->request('POST', '/publish', $body, $signature)->getStatusCode());
        self::assertSame(401, $this->request('POST', '/publish', $body, $signature)->getStatusCode());
        $this->clock->sleep(120);
        $this->wait(0.02);
        self::assertSame(401, $this->request('POST', '/publish', $body, $signature)->getStatusCode());
        $this->clock->sleep(0.001);
        $this->wait(0.02);
        self::assertSame(204, $this->request('POST', '/publish', $body, $signature)->getStatusCode());
    }

    public function testANonceIsAcceptedOnceWhateverItsTime(): void
    {
        $this->start();
        $body = json_encode(['notices' => [self::notice([self::A])]], \JSON_THROW_ON_ERROR);
        $nonce = str_repeat('ab', 16);
        $first = $this->signer->sign('POST', '/publish', $body, $this->now(), $nonce);
        $again = $this->signer->sign('POST', '/publish', $body, $this->now() + 1, $nonce);
        $other = $this->signer->sign('POST', '/publish', $body, $this->now(), str_repeat('cd', 16));

        self::assertSame(204, $this->request('POST', '/publish', $body, $first)->getStatusCode());
        self::assertSame(401, $this->request('POST', '/publish', $body, $again)->getStatusCode());
        self::assertSame(204, $this->request('POST', '/publish', $body, $other)->getStatusCode());
    }

    public function testSignaturesOfThePreviousSecretAreAccepted(): void
    {
        $this->signer = new RequestSigner(new Keys(self::SECRET . '-new', self::SECRET));
        $this->start();
        $body = json_encode(['notices' => [self::notice([self::A])]], \JSON_THROW_ON_ERROR);
        $previous = new RequestSigner(new Keys(self::SECRET));

        self::assertSame(
            204,
            $this->request(
                'POST',
                '/publish',
                $body,
                $previous->sign('POST', '/publish', $body, $this->now()),
            )->getStatusCode(),
        );
    }

    public function testSaysWhetherAValidSubscriptionListens(): void
    {
        $this->start();
        $empty = $this->request('GET', '/subscriptions?topic=' . rawurlencode(self::A));

        self::assertSame(200, $empty->getStatusCode());
        self::assertSame('application/json', $empty->getHeaderLine('Content-Type'));
        self::assertSame('{"subscriptions":[]}', (string) $empty->getBody());
        $this->subscriber([self::A]);
        $this->subscriber([self::A], 2);
        self::assertSame(
            '{"subscriptions":[{"active":true}]}',
            (string) $this->request('GET', '/subscriptions?topic=' . rawurlencode(self::A) . '&x')->getBody(),
        );
    }

    public function testSubscriptionsNeedATopic(): void
    {
        $this->start();

        self::assertSame(400, $this->request('GET', '/subscriptions')->getStatusCode());
        self::assertSame(
            400,
            $this->request('GET', '/subscriptions?topic%5B%5D=' . rawurlencode(self::A))->getStatusCode(),
        );
    }

    public function testPublishesUpToAHundredNotices(): void
    {
        $this->start();
        $client = $this->subscriber([self::A]);
        $notices = array_map(static fn(int $n): array => self::notice([self::A], '{"n":' . $n . '}'), range(1, 100));

        self::assertSame(204, $this->publish($notices));
        $this->until(static fn(): bool => 100 === \count($client->messages));
        self::assertSame('{"n":100}', $client->messages[99]);
    }

    /** @return iterable<string, array{string}> */
    public static function badBodies(): iterable
    {
        $notice = ['topics' => ['urn:test:a'], 'data' => '{}'];
        $body = static fn(mixed $notices): string => json_encode(['notices' => $notices], \JSON_THROW_ON_ERROR);
        yield 'not JSON' => ['{"notices":'];
        yield 'too deep' => [$body([['topics' => ['urn:test:a'], 'data' => '{}', 'x' => [[1]]]])];
        yield 'not an object' => ['[]'];
        yield 'no notices' => ['{}'];
        yield 'no notice' => [$body([])];
        yield 'notices not a list' => [$body(['x' => $notice])];
        yield 'notices not an array' => [$body('x')];
        yield 'over a hundred notices' => [$body(array_fill(0, 101, $notice))];
        yield 'a notice not an object' => [$body(['x'])];
        yield 'no topics' => [$body([['data' => '{}']])];
        yield 'empty topics' => [$body([['topics' => [], 'data' => '{}']])];
        yield 'topics not a list' => [$body([['topics' => ['t' => 'urn:test:a'], 'data' => '{}']])];
        yield 'topics a string' => [$body([['topics' => 'urn:test:a', 'data' => '{}']])];
        yield 'a topic not a string' => [$body([['topics' => [1], 'data' => '{}']])];
        yield 'a topic outside the prefix' => [$body([['topics' => ['urn:test:a', 'urn:other:a'], 'data' => '{}']])];
        yield 'no data' => [$body([['topics' => ['urn:test:a']]])];
        yield 'data not a string' => [$body([['topics' => ['urn:test:a'], 'data' => ['type' => 'x']]])];
        yield 'data not JSON' => [$body([['topics' => ['urn:test:a'], 'data' => '{"type":']])];
        yield 'data posing as a control message' => [$body([[
            'topics' => ['urn:test:a'],
            'data' => '{"hub":"subscribed","id":1}',
        ]])];
        yield 'one bad notice after a good one' => [$body([$notice, ['topics' => ['urn:test:a'], 'data' => 'x']])];
    }

    #[DataProvider('badBodies')]
    public function testRefusesABadBodyWholly(string $body): void
    {
        $this->start();
        $client = $this->subscriber([self::A]);

        self::assertSame(400, $this->request('POST', '/publish', $body)->getStatusCode());
        $this->wait(0.02);
        self::assertSame([], $client->messages);
    }

    /** @return iterable<string, array{string}> */
    public static function data(): iterable
    {
        yield 'an object' => ['{"type":"x","data":{"hub":1}}'];
        yield 'a list' => ['["hub"]'];
        yield 'a scalar' => ['1'];
        yield 'null' => ['null'];
    }

    #[DataProvider('data')]
    public function testDataIsAnyJsonButAControlMessage(string $data): void
    {
        $this->start();
        $client = $this->subscriber([self::A]);

        self::assertSame(204, $this->publish([self::notice([self::A], $data)]));
        $this->until(static fn(): bool => [] !== $client->messages);
        self::assertSame([$data], $client->messages);
    }

    public function testRefusesAnOversizedBodyBeforeReadingIt(): void
    {
        $this->start();
        $response = $this->exchange("POST /publish HTTP/1.1\r\nHost: localhost\r\nContent-Length: 1048577\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 413 ', $response);
        self::assertStringContainsString("\r\nConnection: close\r\n", $response);
    }

    public function testReadsABodyOfTheLargestSize(): void
    {
        $this->start();
        $response = $this->exchange(
            "POST /publish HTTP/1.1\r\nHost: localhost\r\nContent-Length: 1048576\r\n\r\n" . str_repeat('x', 1_048_576),
        );

        self::assertStringStartsWith('HTTP/1.1 401 ', $response);
    }

    public function testRefusesABodyOfUnknownLength(): void
    {
        $this->start();
        $response = $this->exchange(
            "POST /publish HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n",
        );

        self::assertStringStartsWith('HTTP/1.1 411 ', $response);
    }

    /** The current second of the mock clock. */
    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /** The response headers of the publish side to raw bytes. */
    private function exchange(string $request): string
    {
        $address = $this->server?->publishAddress() ?? '';
        $connection = $this->await($this->connector($address)->connect($address));
        \assert($connection instanceof \React\Socket\ConnectionInterface);
        $received = '';
        $connection->on('data', static function (string $data) use (&$received): void {
            $received .= $data;
        });
        $connection->write($request);
        $this->until(static function () use (&$received): bool {
            return str_contains($received, "\r\n\r\n");
        });
        $connection->close();

        return $received;
    }
}
