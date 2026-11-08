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
use Ratchet\RFC6455\Messaging\Frame;

#[CoversClass(BrowserEndpoint::class)]
#[CoversClass(Connection::class)]
#[CoversClass(Counter::class)]
#[UsesClass(ClientAddress::class)]
#[UsesClass(Limits::class)]
#[UsesClass(Seconds::class)]
#[UsesClass(PublishEndpoint::class)]
#[UsesClass(Router::class)]
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
final class BrowserEndpointTest extends ServerTestCase
{
    private const string A = self::PREFIX . 'a';
    private const string B = self::PREFIX . 'b';

    private int $probes = 0;

    public function testSubscribersReceiveTheNoticesOfTheirTopicsVerbatimAndOnce(): void
    {
        $this->start();
        $both = $this->subscriber([self::A, self::B]);
        $other = $this->subscriber([self::B], 2);

        self::assertSame(204, $this->publish([
            self::notice([self::A, self::B], '{"n":1}'),
            self::notice([self::A], '{"n":2}'),
        ]));
        $this->until(static fn(): bool => 2 === \count($both->messages) && 1 === \count($other->messages));
        self::assertSame(['{"n":1}', '{"n":2}'], $both->messages);
        self::assertSame(['{"n":1}'], $other->messages);
    }

    public function testATokenForOneTopicHearsNothingOfAnother(): void
    {
        $this->start();
        $client = $this->subscriber([self::A]);

        self::assertSame(204, $this->publish([self::notice([self::B], '{"n":"b"}')]));
        self::assertSame(204, $this->publish([self::notice([self::A], '{"n":"a"}')]));
        $this->until(static fn(): bool => [] !== $client->messages);
        self::assertSame(['{"n":"a"}'], $client->messages);
    }

    public function testNoticesBeforeTheFirstAcknowledgementNeverReachAConnection(): void
    {
        $this->start();
        $client = $this->client();
        self::assertSame(204, $this->publish([self::notice([self::A])]));
        $client->subscribe(7, $this->token([self::A]));
        $this->until(static fn(): bool => [] !== $client->messages);

        self::assertSame(['{"hub":"subscribed","id":7}'], $client->messages);
    }

    public function testARenewalWithFewerTopicsStopsTheOthersAtOnce(): void
    {
        $this->start();
        $client = $this->subscriber([self::A, self::B]);
        $client->subscribe(2, $this->token([self::A]));
        $this->until(static fn(): bool => [] !== $client->messages);
        self::assertSame(['{"hub":"subscribed","id":2}'], $client->messages);
        $client->messages = [];

        self::assertSame(204, $this->publish([
            self::notice([self::B], '{"n":"b"}'),
            self::notice([self::A], '{"n":"a"}'),
        ]));
        $this->until(static fn(): bool => [] !== $client->messages);
        self::assertSame(['{"n":"a"}'], $client->messages);
    }

    public function testAFailedRenewalKeepsTheSubscription(): void
    {
        $this->start();
        $client = $this->subscriber([self::A]);
        $client->subscribe(2, 'not-a-token');
        $client->subscribe(3, $this->token([self::B], 2));
        $client->subscribe(4, $this->token([self::B], 1, 'https://other.example'));
        $this->until(static fn(): bool => 3 === \count($client->messages));

        self::assertSame(
            [
                '{"hub":"error","id":2,"code":4401}',
                '{"hub":"error","id":3,"code":4401}',
                '{"hub":"error","id":4,"code":4401}',
            ],
            $client->messages,
        );
        self::assertSame(204, $this->publish([self::notice([self::A], '{"n":"a"}')]));
        $this->until(static fn(): bool => 4 === \count($client->messages));
        self::assertSame(['{"n":"a"}'], array_slice($client->messages, 3));
        self::assertFalse($client->closed);
    }

    public function testSubscribesOverTheRateLimitAreRefusedWithoutClosing(): void
    {
        $this->start($this->config(self::limits(maxSubscribes: 2)));
        $client = $this->subscriber([self::A]);
        $client->subscribe(2, $this->token([self::A, self::B]));
        $client->subscribe(3, $this->token([self::A]));
        $this->until(static fn(): bool => 2 === \count($client->messages));

        self::assertSame(['{"hub":"subscribed","id":2}', '{"hub":"error","id":3,"code":4401}'], $client->messages);
        self::assertSame(204, $this->publish([self::notice([self::B], '{"n":"b"}')]));
        $this->until(static fn(): bool => 3 === \count($client->messages));
        self::assertFalse($client->closed);
    }

    public function testTheRateLimitWindowStartsAgain(): void
    {
        $this->start($this->config(self::limits(maxSubscribes: 1), self::timings()));
        $client = $this->subscriber([self::A]);
        $this->clock->sleep(60);
        $client->subscribe(2, $this->token([self::B]));
        $this->until(static fn(): bool => [] !== $client->messages);

        self::assertSame(['{"hub":"subscribed","id":2}'], $client->messages);
    }

    /** @return iterable<string, array{\Closure(self): string}> */
    public static function badTokens(): iterable
    {
        $now = 1_767_225_600; // the mock clock's
        yield 'tampered' => [static fn(self $test): string => substr_replace($test->token([self::A]), 'x', 3, 1)];
        yield 'expired' => [static fn(self $test): string => $test->token([self::A], now: $now - 300)];
        yield 'issued in the future' => [static fn(self $test): string => $test->token([self::A], now: $now + 6)];
        yield 'over its lifetime' => [
            static fn(self $test): string => new SubscriberToken($test->keys, self::PATH, self::PREFIX, 301, 8)->sign(
                1,
                self::ORIGIN,
                [self::A],
                $now,
            ),
        ];
        yield 'for another hub' => [
            static fn(self $test): string => new SubscriberToken($test->keys, '/other', self::PREFIX, 300, 8)->sign(
                1,
                self::ORIGIN,
                [self::A],
                $now,
            ),
        ];
        yield 'signed with another secret' => [
            static fn(self $test): string => new SubscriberToken(
                new Keys(str_repeat('b', 32)),
                self::PATH,
                self::PREFIX,
                300,
                8,
            )->sign(1, self::ORIGIN, [self::A], $now),
        ];
        yield 'for another origin' => [
            static fn(self $test): string => $test->token([self::A], 1, 'https://evil.example'),
        ];
    }

    /** @param \Closure(self): string $token */
    #[DataProvider('badTokens')]
    public function testAFailedFirstSubscribeIsAnsweredThenClosedWith4401(#[\SensitiveParameter] \Closure $token): void
    {
        $this->start();
        $client = $this->client();
        $client->subscribe(5, $token($this));
        $this->until(static fn(): bool => $client->closed);

        self::assertSame(['{"hub":"error","id":5,"code":4401}'], $client->messages);
        self::assertSame(4401, $client->code);
    }

    public function testTokensOfThePreviousSecretAreAccepted(): void
    {
        $this->keys = new Keys(self::SECRET . '-new', self::SECRET);
        $this->tokens = new SubscriberToken($this->keys, self::PATH, self::PREFIX, 300, 8);
        $previous = new SubscriberToken(new Keys(self::SECRET), self::PATH, self::PREFIX, 300, 8);
        $this->start();
        $client = $this->client();
        $client->subscribe(1, $previous->sign(1, self::ORIGIN, [self::A], $this->clock->now()->getTimestamp()));
        $this->until(static fn(): bool => [] !== $client->messages);

        self::assertSame(['{"hub":"subscribed","id":1}'], $client->messages);
    }

    public function testATokenIsCheckedAgainstTheOriginOfTheHandshakeNotTheHost(): void
    {
        $this->start();
        // A proxy rewrote Host to the hub's own address: only Origin counts.
        $client = $this->client(['Origin' => 'HTTPS://APP.example:443', 'Host' => 'hub.internal:8090']);
        $client->subscribe(1, $this->token([self::A]));
        $this->until(static fn(): bool => [] !== $client->messages);

        self::assertSame(['{"hub":"subscribed","id":1}'], $client->messages);
    }

    /** @return iterable<string, array{string, string}> */
    public static function protocolViolations(): iterable
    {
        yield 'a client trying to publish' => ['{"topics":["urn:test:a"],"data":"{}"}', ''];
        yield 'another message type' => ['{"hub":"publish","id":1,"token":"x"}', ''];
        yield 'malformed JSON' => ['{"hub":', ''];
        yield 'JSON too deep' => ['{"hub":"subscribe","id":1,"token":["x"]}', ''];
        yield 'an extra key' => ['{"hub":"subscribe","id":1,"token":"x","topics":[]}', ''];
        yield 'a missing key' => ['{"hub":"subscribe","id":1}', ''];
        yield 'a string id' => ['{"hub":"subscribe","id":"1","token":"x"}', ''];
        yield 'a token not a string' => ['{"hub":"subscribe","id":1,"token":1}', ''];
        yield 'a list' => ['["subscribe",1,"x"]', ''];
        yield 'a scalar' => ['"subscribe"', ''];
        yield 'a binary frame' => ['{"hub":"subscribe","id":1,"token":"x"}', 'binary'];
    }

    #[DataProvider('protocolViolations')]
    public function testAnythingButASubscribeClosesWith1008AndIsNeverEchoed(string $message, string $binary): void
    {
        $this->start();
        $client = $this->client();
        $client->socket->send(new Frame($message, true, '' === $binary ? Frame::OP_TEXT : Frame::OP_BINARY));
        $this->until(static fn(): bool => $client->closed);

        self::assertSame([], $client->messages);
        self::assertSame(1008, $client->code);
    }

    public function testAProtocolViolationAfterSubscribingCloses(): void
    {
        $this->start();
        $client = $this->subscriber([self::A]);
        $client->socket->send('{"hub":"unsubscribe"}');
        $this->until(static fn(): bool => $client->closed);

        self::assertSame(1008, $client->code);
    }

    /** @return iterable<string, array{string, int}> */
    public static function frameViolations(): iterable
    {
        yield 'an unmasked frame' => [new Frame('{"hub":"subscribe"}')->getContents(), Frame::CLOSE_PROTOCOL];
        yield 'reserved bits' => [
            (static function (): string {
                $frame = RawClient::masked('{}');
                $frame[0] = \chr(\ord($frame[0]) | 0x40);

                return $frame;
            })(),
            Frame::CLOSE_PROTOCOL,
        ];
        yield 'an oversized message' => [RawClient::masked(str_repeat('x', 1025)), Frame::CLOSE_TOO_BIG];
        yield 'oversized fragments' => [
            RawClient::masked(str_repeat('x', 600), Frame::OP_TEXT, false)
                . RawClient::masked(str_repeat('x', 600), Frame::OP_CONTINUE),
            Frame::CLOSE_TOO_BIG,
        ];
        yield 'invalid UTF-8' => [RawClient::masked("\xff\xfe"), Frame::CLOSE_BAD_PAYLOAD];
        yield 'an unknown opcode' => [RawClient::masked('', 0xB), Frame::CLOSE_PROTOCOL];
    }

    #[DataProvider('frameViolations')]
    public function testFramesBreakingTheProtocolCloseWithTheirCode(string $bytes, int $code): void
    {
        $this->start($this->config(self::limits(maxMessageSize: 1024)));
        $client = $this->upgraded();
        $client->send($bytes . RawClient::masked('{"hub":"subscribe","id":1,"token":"x"}'));
        $this->until(static fn(): bool => $client->closed);

        self::assertSame(RawClient::close($code), $client->received);
    }

    public function testNothingIsReadPastAViolation(): void
    {
        $this->start($this->config(self::limits(maxPending: 1)));
        $client = $this->upgraded();
        $subscribe = json_encode(
            ['hub' => 'subscribe', 'id' => 1, 'token' => $this->token([self::A])],
            \JSON_THROW_ON_ERROR,
        );
        $client->send(RawClient::masked('{"hub":"publish"}') . RawClient::masked($subscribe));
        $this->until(static fn(): bool => $client->closed);

        self::assertSame(RawClient::close(1008), $client->received);
        // It left as a pending connection, freeing its place.
        $this->wait(0.02);
        self::assertStringStartsWith('HTTP/1.1 101 ', $this->handshake(self::upgrade()));
    }

    public function testFragmentsWithinTheLimitMakeOneMessage(): void
    {
        $this->start($this->config(self::limits(maxMessageSize: 1024)));
        $client = $this->upgraded();
        $message = json_encode(
            ['hub' => 'subscribe', 'id' => 3, 'token' => $this->token([self::A])],
            \JSON_THROW_ON_ERROR,
        );
        $client->send(
            RawClient::masked(substr($message, 0, 10), Frame::OP_TEXT, false)
                . RawClient::masked(substr($message, 10), Frame::OP_CONTINUE),
        );
        $this->until(static fn(): bool => '' !== $client->received);

        self::assertSame(new Frame('{"hub":"subscribed","id":3}')->getContents(), $client->received);
    }

    public function testPingsAreAnsweredAndCloseFramesEchoed(): void
    {
        $this->start();
        $client = $this->upgraded();
        $client->send(RawClient::masked('beat', Frame::OP_PING));
        $client->send(RawClient::masked('', Frame::OP_PONG));
        $client->send(RawClient::masked(pack('n', 4000) . 'bye', Frame::OP_CLOSE));
        $client->send(RawClient::masked('beat', Frame::OP_PING));
        $this->until(static fn(): bool => $client->closed);

        self::assertSame(
            new Frame('beat', true, Frame::OP_PONG)->getContents() . RawClient::close(4000),
            $client->received,
        );
    }

    public function testAnEmptyCloseFrameIsAnsweredWith1000(): void
    {
        $this->start();
        $client = $this->upgraded();
        $client->send(RawClient::masked('', Frame::OP_CLOSE));
        $this->until(static fn(): bool => $client->closed);

        self::assertSame(RawClient::close(1000), $client->received);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedHandshakes(): iterable
    {
        yield 'no Origin' => [self::upgrade([]), '403'];
        yield 'an opaque Origin' => [self::upgrade(['Origin' => 'null']), '403'];
        yield 'a POST' => [self::upgrade(['Origin' => self::ORIGIN], 'POST'), '405'];
        yield 'another path' => [self::upgrade(['Origin' => self::ORIGIN], 'GET', '/hub/x'), '404'];
        yield 'a body' => [self::upgrade(['Origin' => self::ORIGIN, 'Content-Length' => '2']) . '{}', '400'];
        yield 'a chunked body' => [
            self::upgrade(['Origin' => self::ORIGIN, 'Transfer-Encoding' => 'chunked']) . "0\r\n\r\n",
            '400',
        ];
        yield 'no WebSocket key' => [self::upgrade(['Origin' => self::ORIGIN, 'Sec-WebSocket-Key' => '']), '400'];
    }

    #[DataProvider('refusedHandshakes')]
    public function testRefusesHandshakes(string $request, string $status): void
    {
        $this->start();

        self::assertStringStartsWith('HTTP/1.1 ' . $status . ' ', $this->handshake($request));
    }

    public function testRefusedHandshakesCloseTheirSocket(): void
    {
        $this->start();
        $client = new RawClient($this->raw());
        $client->send(self::upgrade([]));
        $this->until(static fn(): bool => $client->closed);

        self::assertStringStartsWith('HTTP/1.1 403 ', $client->headers);
        self::assertStringContainsString("\r\nConnection: close\r\n", $client->headers);
    }

    public function testTheUpgradeTellsNothingOfTheServer(): void
    {
        $this->start();
        $client = $this->upgraded();

        self::assertStringNotContainsStringIgnoringCase('X-Powered-By', $client->headers);
        self::assertStringContainsString("\r\nSec-WebSocket-Accept: ", $client->headers);
    }

    public function testAllowedOriginsRestrictTheHandshake(): void
    {
        $this->start($this->config(allowedOrigins: ['https://APP.example']));

        self::assertStringStartsWith(
            'HTTP/1.1 403 ',
            $this->handshake(self::upgrade(['Origin' => 'https://other.example'])),
        );
        self::assertStringStartsWith(
            'HTTP/1.1 101 ',
            $this->handshake(self::upgrade(['Origin' => 'https://app.example:443'])),
        );
    }

    public function testCapsConnections(): void
    {
        $this->start($this->config(self::limits(maxConnections: 2)));
        $this->subscriber([self::A]);
        $this->client();

        self::assertStringStartsWith('HTTP/1.1 503 ', $this->handshake(self::upgrade()));
    }

    public function testCapsPendingConnectionsUntilTheySubscribe(): void
    {
        $this->start($this->config(self::limits(maxPending: 1)));
        $pending = $this->client();

        self::assertStringStartsWith('HTTP/1.1 503 ', $this->handshake(self::upgrade()));
        $pending->subscribe(1, $this->token([self::A]));
        $this->until(static fn(): bool => [] !== $pending->messages);
        self::assertStringStartsWith('HTTP/1.1 101 ', $this->handshake(self::upgrade()));
        $this->wait(0.02);
        // The pending connection that closed freed its place.
        self::assertStringStartsWith('HTTP/1.1 101 ', $this->handshake(self::upgrade()));
    }

    public function testCapsConnectionsPerUser(): void
    {
        $this->start($this->config(self::limits(maxConnectionsPerUser: 1)));
        $first = $this->subscriber([self::A]);
        $second = $this->client();
        $second->subscribe(1, $this->token([self::A]));
        $this->until(static fn(): bool => $second->closed);

        self::assertSame(['{"hub":"error","id":1,"code":1008}'], $second->messages);
        self::assertSame(1008, $second->code);
        $this->subscriber([self::A], 2);
        $first->socket->close();
        $this->until(static fn(): bool => $first->closed);
        $this->wait(0.02);
        $this->subscriber([self::A]);
    }

    public function testCapsConnectionsPerClientIp(): void
    {
        // A trusted proxy list makes the peer a usable client address.
        $this->start($this->config(self::limits(maxConnectionsPerIp: 1), trustedProxies: ['10.0.0.0/8']));
        $first = $this->client();

        // A peer that is no trusted proxy cannot pose as another client: refused as soon as it connects.
        self::assertSame(
            '',
            $this->handshake(self::upgrade(['Origin' => self::ORIGIN, 'X-Forwarded-For' => '198.51.100.9'])),
        );
        $first->socket->close();
        $this->until(static fn(): bool => $first->closed);
        $this->wait(0.02);
        self::assertStringStartsWith('HTTP/1.1 101 ', $this->handshake(self::upgrade()));
    }

    public function testCountsTheClientsOfATrustedProxyApart(): void
    {
        $this->start($this->config(self::limits(maxConnectionsPerIp: 1), trustedProxies: ['127.0.0.1']));
        $this->client(['Origin' => self::ORIGIN, 'X-Forwarded-For' => '198.51.100.1']);
        $this->client(['Origin' => self::ORIGIN, 'X-Forwarded-For' => '198.51.100.2']);

        self::assertStringStartsWith(
            'HTTP/1.1 429 ',
            $this->handshake(self::upgrade(['Origin' => self::ORIGIN, 'X-Forwarded-For' => '198.51.100.1'])),
        );
    }

    public function testCountsConnectionsAndPendingOnesForTheStatistics(): void
    {
        $this->start($this->config(timings: self::timings(statsInterval: 60.0)));
        $this->subscriber([self::A]);
        $this->client();
        $this->client();
        $this->clock->sleep(60);
        $this->until(fn(): bool => 2 === \count($this->logs));

        self::assertSame(['connections' => 3, 'pending' => 2], array_intersect_key($this->logs[1][2], [
            'connections' => 0,
            'pending' => 0,
        ]));
    }

    public function testAConnectionThatDoesNotSubscribeInTimeIsClosedWith4401(): void
    {
        $this->start($this->config(timings: self::timings(subscribeTimeout: 10.0)));
        $client = $this->client();
        $this->clock->sleep(9.9);
        $this->wait(0.03);
        self::assertFalse($client->closed);
        $this->clock->sleep(0.1);
        $this->until(static fn(): bool => $client->closed);

        self::assertSame(4401, $client->code);
        self::assertSame([], $client->messages);
    }

    public function testASubscribedConnectionIsClosedWith4401OnceItsTokenExpires(): void
    {
        $this->start($this->config(timings: self::timings(pingInterval: 1000.0)));
        $client = $this->subscriber([self::A]);
        $this->clock->sleep(299.5);
        $this->wait(0.03);
        self::assertFalse($client->closed);
        $this->clock->sleep(0.5);
        $this->until(static fn(): bool => $client->closed);

        self::assertSame(4401, $client->code);
    }

    public function testNothingIsDeliveredPastExpiryEvenWhenTheTimerLags(): void
    {
        $this->start($this->config(timings: self::timings(tickInterval: 100.0)));
        $client = $this->subscriber([self::A]);
        $this->clock->sleep(300);

        self::assertSame(204, $this->publish([self::notice([self::A])]));
        $this->wait(0.03);
        self::assertSame([], $client->messages);
        self::assertFalse($client->closed);
    }

    public function testPingsAndKeepsTheClientsThatAnswer(): void
    {
        $this->start($this->config(timings: self::timings(pingInterval: 10.0)));
        $client = $this->subscriber([self::A]);
        $pings = 0;
        $client->socket->on('ping', static function () use (&$pings): void {
            ++$pings;
        });
        $this->clock->sleep(9.9);
        $this->wait(0.03);
        self::assertSame(0, $pings);
        $this->clock->sleep(0.1);
        $this->until(static function () use (&$pings): bool {
            return 1 === $pings;
        });
        $this->synchronize($client, 2);
        self::assertSame(1, $pings);
        // Silence starts from what it last sent, not from when it subscribed.
        $this->clock->sleep(19.9);
        $this->until(static function () use (&$pings): bool {
            return 2 === $pings;
        });
        $this->synchronize($client, 3);
        $this->clock->sleep(0.1);
        $this->wait(0.03);
        self::assertFalse($client->closed);
    }

    /**
     * Renews and waits for the acknowledgement: the server read everything the client sent before (its pongs),
     * whatever the scheduling, before the test moves the clock.
     */
    private function synchronize(Client $client, int $id): void
    {
        $client->messages = [];
        $client->subscribe($id, $this->token([self::A]));
        $this->until(static fn(): bool => [] !== $client->messages);
        self::assertSame(['{"hub":"subscribed","id":' . $id . '}'], $client->messages);
    }

    public function testDropsSilentClientsWithoutACloseFrame(): void
    {
        $this->start($this->config(timings: self::timings(pingInterval: 10.0)));
        $client = $this->upgraded();
        $client->subscribe(1, $this->token([self::A]));
        $this->until(static fn(): bool => '' !== $client->received);
        $client->received = '';
        $this->clock->sleep(19.9);
        $this->wait(0.03);
        self::assertFalse($client->closed);
        self::assertSame("\x89\x00", $client->received);
        $this->clock->sleep(0.1);
        $this->until(static fn(): bool => $client->closed);
        self::assertSame("\x89\x00", $client->received);
    }

    public function testAClientThatStopsReadingIsDroppedAndMemoryStaysFlat(): void
    {
        $this->start($this->config(self::limits(maxBuffer: 65_536), listen: $this->unix()));
        $client = $this->stalled();
        $before = memory_get_usage();
        for ($i = 0; $i < 20 && $this->listening(); ++$i) {
            self::assertSame(204, $this->publish(self::bulk($i)));
        }

        self::assertFalse($this->listening());
        self::assertLessThan(4_000_000, memory_get_usage() - $before);
        // Dropped, not closed: no close frame queued behind what it did not read.
        $client->socket->resume();
        $this->until(static fn(): bool => $client->closed);
        self::assertStringEndsNotWith(RawClient::close(1008), $client->received);
    }

    public function testAClientCongestedTooLongIsDropped(): void
    {
        $this->start($this->config(
            self::limits(maxBuffer: 100_000_000),
            self::timings(congestionTimeout: 10.0),
            $this->unix(),
        ));
        $client = $this->stalled();
        self::assertSame(204, $this->publish(self::bulk(0)));
        $this->clock->sleep(9.9);
        $this->wait(0.03);
        self::assertTrue($this->listening());
        $this->clock->sleep(0.1);
        $this->wait(0.03);
        self::assertFalse($this->listening());
        $client->socket->resume();
        $this->until(static fn(): bool => $client->closed);
    }

    public function testACongestedClientThatCatchesUpStays(): void
    {
        $this->start($this->config(
            self::limits(maxBuffer: 100_000_000),
            self::timings(congestionTimeout: 10.0),
            $this->unix(),
        ));
        $client = $this->stalled();
        self::assertSame(204, $this->publish(self::bulk(0)));
        $client->socket->resume();
        $this->until(static fn(): bool => str_ends_with($client->received, str_repeat('x', 8000) . '"}'), 5.0);
        $this->clock->sleep(10);
        $this->wait(0.03);
        self::assertTrue($this->listening());
        self::assertFalse($client->closed);
    }

    public function testAClosingClientThatDoesNotReadIsDroppedAndFreesItsPlace(): void
    {
        $this->start($this->config(
            self::limits(maxConnections: 1, maxBuffer: 100_000_000),
            self::timings(subscribeTimeout: 10.0, congestionTimeout: 1.0),
            $this->unix(),
        ));
        // It never subscribes, floods pings and never reads: its close frame queues behind the pongs.
        $client = $this->upgraded();
        $client->socket->pause();
        $client->send(str_repeat(RawClient::masked(str_repeat('p', 125), Frame::OP_PING), 20_000));
        $this->wait(0.2);
        $this->clock->sleep(10);
        $this->wait(0.03);
        self::assertStringStartsWith('HTTP/1.1 503 ', $this->handshake(self::upgrade()));
        // Closing, it is congested for long: only its close deadline drops it.
        $this->clock->sleep(1.9);
        $this->wait(0.03);
        self::assertStringStartsWith('HTTP/1.1 503 ', $this->handshake(self::upgrade()));
        $this->clock->sleep(0.1);
        $this->wait(0.03);
        self::assertStringStartsWith('HTTP/1.1 101 ', $this->handshake(self::upgrade()));
    }

    public function testAClosingConnectionDoesNotHoldUpTheOthers(): void
    {
        $this->start($this->config(
            self::limits(maxBuffer: 100_000_000),
            self::timings(pingInterval: 11.0, subscribeTimeout: 10.0),
            $this->unix(),
        ));
        $closing = $this->upgraded();
        $closing->socket->pause();
        $closing->send(str_repeat(RawClient::masked(str_repeat('p', 125), Frame::OP_PING), 20_000));
        $client = $this->subscriber([self::A]);
        $pings = 0;
        $client->socket->on('ping', static function () use (&$pings): void {
            ++$pings;
        });
        $this->wait(0.2);
        $this->clock->sleep(10);
        $this->wait(0.03);
        // The first is closing, not dropped yet; the timer still pings the next.
        $this->clock->sleep(1.5);
        $this->until(static function () use (&$pings): bool {
            return 1 === $pings;
        });
    }

    public function testShutdownClosesEveryConnectionWith1001(): void
    {
        $this->start();
        $subscribed = $this->subscriber([self::A]);
        $pending = $this->client();
        $this->server?->shutdown();
        $this->until(static fn(): bool => $subscribed->closed && $pending->closed);

        self::assertSame(1001, $subscribed->code);
        self::assertSame(1001, $pending->code);
    }

    /** 100 notices of 8 KB: distinct bodies, so no request repeats a signature. */
    private static function bulk(int $batch): array
    {
        return array_fill(
            0,
            100,
            self::notice(
                [self::A],
                json_encode(['batch' => $batch, 'x' => str_repeat('x', 8000)], \JSON_THROW_ON_ERROR),
            ),
        );
    }

    /** Whether the publish side says someone listens to topic A. */
    private function listening(): bool
    {
        // A new target each time: the same request in the same second would be a replay.
        $response = $this->request('GET', '/subscriptions?topic=' . rawurlencode(self::A) . '&n=' . ++$this->probes);
        self::assertSame(200, $response->getStatusCode());

        return '{"subscriptions":[]}' !== (string) $response->getBody();
    }

    private function unix(): string
    {
        return 'unix://' . $this->directory . '/browser.sock';
    }

    /** A raw browser subscribed to A that stopped reading. */
    private function stalled(): RawClient
    {
        $client = $this->upgraded();
        $client->subscribe(1, $this->token([self::A]));
        $this->until(static fn(): bool => '' !== $client->received);
        $client->socket->pause();

        return $client;
    }
}
