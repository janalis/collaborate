<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Server;

use Janalis\Collaborate\Cursor\CursorEvent;
use Janalis\Collaborate\Cursor\CursorEvents;
use Janalis\Collaborate\Cursor\CursorIdentity;
use Janalis\Collaborate\Cursor\CursorPosition;
use Janalis\Collaborate\Hub\Server\BrowserEndpoint;
use Janalis\Collaborate\Hub\Server\ClientAddress;
use Janalis\Collaborate\Hub\Server\Connection;
use Janalis\Collaborate\Hub\Server\Counter;
use Janalis\Collaborate\Hub\Server\Cursors;
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

/** Cursor events a page sends over its socket, relayed to its room at once. */
#[CoversClass(BrowserEndpoint::class)]
#[CoversClass(Connection::class)]
#[CoversClass(Cursors::class)]
#[UsesClass(ClientAddress::class)]
#[UsesClass(Counter::class)]
#[UsesClass(Limits::class)]
#[UsesClass(PublishEndpoint::class)]
#[UsesClass(Router::class)]
#[UsesClass(Seconds::class)]
#[UsesClass(Server::class)]
#[UsesClass(ServerConfig::class)]
#[UsesClass(ServerFactory::class)]
#[UsesClass(Sockets::class)]
#[UsesClass(Timings::class)]
#[UsesClass(CursorEvent::class)]
#[UsesClass(CursorEvents::class)]
#[UsesClass(CursorIdentity::class)]
#[UsesClass(CursorPosition::class)]
#[UsesClass(Keys::class)]
#[UsesClass(Origin::class)]
#[UsesClass(RequestSigner::class)]
#[UsesClass(SubscriberToken::class)]
#[UsesClass(TokenClaims::class)]
#[UsesClass(WebSocketTransport::class)]
final class CursorsTest extends ServerTestCase
{
    private const string ROOM = self::PREFIX . 'cursors:room';
    private const string OTHER = self::PREFIX . 'cursors:other';
    private const string SENTINEL = '{"type":"sentinel"}';

    private const array EVENT = [
        'clientId' => 'client-aaaaaaaaaaaa',
        'sequence' => 1,
        'kind' => 'hello',
        'style' => 'mac',
    ];

    public function testRelaysToTheRoomAtOnceStampedWithTheTokenIdentity(): void
    {
        $this->startRelaying();
        $sender = $this->cursorClient(self::ROOM, 1);
        $peer = $this->cursorClient(self::ROOM, 2);
        $listener = $this->subscriber([self::ROOM], 3);
        $elsewhere = $this->cursorClient(self::OTHER, 4);
        $this->clock->sleep(0.25);

        $this->sendCursor(
            $sender,
            self::EVENT + ['userId' => 99, 'label' => 'Mallory', 'color' => 'red', 'avatar' => 'x'],
        );
        $this->until(
            static fn(): bool => [] !== $peer->messages && [] !== $listener->messages && [] !== $sender->messages,
        );
        $expected = new CursorEvents()->notice(
            self::EVENT,
            new CursorIdentity(1, 'User 1', '#000001', 'https://app.example/1.png'),
            1_767_225_600_250,
        );
        self::assertSame([$expected], $peer->messages);
        self::assertSame([$expected], $listener->messages);
        // Its own echo, which the client ignores as Mercure's.
        self::assertSame([$expected], $sender->messages);
        $this->sentinel($sender, self::OTHER, $elsewhere);
    }

    public function testIgnoresAPageWhoseTokenGrantsNoCursorRoom(): void
    {
        $this->startRelaying();
        $sender = $this->subscriber([self::ROOM], 1);
        $peer = $this->cursorClient(self::ROOM, 2);
        $this->sendCursor($sender, self::EVENT);

        $this->sentinel($sender, self::ROOM, $peer);
        self::assertFalse($sender->closed);
    }

    public function testIgnoresCursorEventsWhenTheyAreOff(): void
    {
        $this->start($this->config(timings: self::timings(tickInterval: 100.0)));
        $sender = $this->cursorClient(self::ROOM, 1);
        $peer = $this->cursorClient(self::ROOM, 2);
        $this->sendCursor($sender, self::EVENT);

        $this->sentinel($sender, self::ROOM, $peer);
    }

    public function testIgnoresAPageThatDidNotSubscribe(): void
    {
        $this->startRelaying();
        $pending = $this->client();
        $peer = $this->cursorClient(self::ROOM, 2);
        $this->sendCursor($pending, self::EVENT);

        $this->sentinel($pending, self::ROOM, $peer);
        self::assertFalse($pending->closed);
    }

    /** @return iterable<string, array{mixed}> */
    public static function notObjects(): iterable
    {
        yield 'a string' => ['x'];
        yield 'a list' => [[1, 2]];
        yield 'null' => [null];
    }

    #[DataProvider('notObjects')]
    public function testAnEventNotAnObjectClosesWith1008(mixed $event): void
    {
        $this->startRelaying();
        $sender = $this->cursorClient(self::ROOM, 1);
        $sender->socket->send(json_encode(['hub' => 'cursor', 'event' => $event], \JSON_THROW_ON_ERROR));
        $this->until(static fn(): bool => $sender->closed);

        self::assertSame(1008, $sender->code);
    }

    public function testACursorMessageWithMoreKeysClosesWith1008(): void
    {
        $this->startRelaying();
        $sender = $this->cursorClient(self::ROOM, 1);
        $sender->socket->send(json_encode(
            ['hub' => 'cursor', 'event' => self::EVENT, 'id' => 1],
            \JSON_THROW_ON_ERROR,
        ));
        $this->until(static fn(): bool => $sender->closed);

        self::assertSame(1008, $sender->code);
    }

    public function testInvalidEventsAreDroppedAndCounted(): void
    {
        $this->start(
            $this->config(timings: self::timings(statsInterval: 60.0)),
            new Cursors(new CursorEvents(), 4096, 100),
        );
        $sender = $this->cursorClient(self::ROOM, 1);
        $peer = $this->cursorClient(self::ROOM, 2);
        $this->sendCursor($sender, ['kind' => 'teleport'] + self::EVENT);
        $sender->socket->send('{"hub":"cursor","event":{}}');
        $this->sendCursor($sender, self::EVENT);
        $this->sendCursor($sender, ['sequence' => 2] + self::EVENT);

        $this->sentinel($sender, self::ROOM, $peer, 2);
        self::assertFalse($sender->closed);
        $this->clock->sleep(60);
        $this->until(fn(): bool => 2 === \count($this->logs));
        self::assertSame(['cursors' => 4, 'invalid_cursors' => 2], array_intersect_key($this->logs[1][2], [
            'cursors' => 0,
            'invalid_cursors' => 0,
        ]));
    }

    public function testOversizedEventsAreDropped(): void
    {
        $fits = self::EVENT + ['field' => str_repeat('f', 100)];
        $this->startRelaying(new Cursors(new CursorEvents(), \strlen(json_encode($fits, \JSON_THROW_ON_ERROR)), 100));
        $sender = $this->cursorClient(self::ROOM, 1);
        $peer = $this->cursorClient(self::ROOM, 2);
        $this->sendCursor($sender, ['field' => str_repeat('f', 101)] + $fits);
        $this->sendCursor($sender, $fits);

        $this->until(static fn(): bool => [] !== $peer->messages);
        self::assertStringContainsString(str_repeat('f', 100) . '"', $peer->messages[0] ?? '');
        $this->sentinel($sender, self::ROOM, $peer, 1);
    }

    public function testAPageMaySendTwiceTheThrottledRate(): void
    {
        self::assertSame(40.0, new Cursors(new CursorEvents(), 4096, 50)->rate());
        self::assertSame(20.0, new Cursors(new CursorEvents(), 4096, 100)->rate());
    }

    public function testEventsOverTheRateAreDropped(): void
    {
        // A throttle of 100 ms: 20 events a second, in bursts of 20.
        $this->startRelaying(new Cursors(new CursorEvents(), 4096, 100));
        $sender = $this->cursorClient(self::ROOM, 1);
        $peer = $this->cursorClient(self::ROOM, 2);
        for ($sequence = 1; $sequence <= 25; ++$sequence) {
            $this->sendCursor($sender, ['sequence' => $sequence] + self::EVENT);
        }
        $this->sentinel($sender, self::ROOM, $peer, 20);

        $this->clock->sleep(0.1);
        for ($sequence = 26; $sequence <= 30; ++$sequence) {
            $this->sendCursor($sender, ['sequence' => $sequence] + self::EVENT);
        }
        $this->sentinel($sender, self::ROOM, $peer, 2);

        $this->clock->sleep(10);
        for ($sequence = 31; $sequence <= 55; ++$sequence) {
            $this->sendCursor($sender, ['sequence' => $sequence] + self::EVENT);
        }
        $this->sentinel($sender, self::ROOM, $peer, 20);
    }

    public function testARenewalThatLeavesTheRoomStopsRelaying(): void
    {
        $this->startRelaying();
        $sender = $this->cursorClient(self::ROOM, 1);
        $peer = $this->cursorClient(self::ROOM, 2);
        $other = $this->cursorClient(self::OTHER, 3);
        $sender->messages = [];
        $sender->subscribe(2, $this->cursorToken(self::OTHER, 1));
        $this->until(static fn(): bool => [] !== $sender->messages);
        $this->sendCursor($sender, self::EVENT);

        $this->sentinel($sender, self::OTHER, $other, 1);
        $this->sentinel($sender, self::ROOM, $peer);

        $sender->messages = [];
        $sender->subscribe(3, $this->token([self::OTHER], 1));
        $this->until(static fn(): bool => [] !== $sender->messages);
        $this->sendCursor($sender, self::EVENT);
        $this->sentinel($sender, self::OTHER, $other);
    }

    public function testTheEventsOfAnExpiredTokenAreNotRelayed(): void
    {
        $this->startRelaying();
        $sender = $this->cursorClient(self::ROOM, 1);
        $this->clock->sleep(200);
        $peer = $this->cursorClient(self::ROOM, 2);
        $this->clock->sleep(100);
        $this->sendCursor($sender, self::EVENT);

        $this->sentinel($sender, self::ROOM, $peer);
    }

    private function startRelaying(?Cursors $cursors = null): void
    {
        // No timer runs during the tests: nothing on the cursor path waits for one.
        $this->start(
            $this->config(timings: self::timings(tickInterval: 100.0)),
            $cursors ?? new Cursors(new CursorEvents(), 4096, 100),
        );
    }

    private function cursorToken(string $room, int $user): string
    {
        return $this->tokens->sign(
            $user,
            self::ORIGIN,
            [$room],
            $this->clock->now()->getTimestamp(),
            $room,
            new CursorIdentity(
                $user,
                'User ' . $user,
                \sprintf('#%06d', $user),
                'https://app.example/' . $user . '.png',
            ),
        );
    }

    /** A page in a cursor room, subscribed with a token that lets it send cursor events. */
    private function cursorClient(string $room, int $user): Client
    {
        $client = $this->client();
        $client->subscribe(1, $this->cursorToken($room, $user));
        $this->until(static fn(): bool => [] !== $client->messages);
        self::assertSame(['{"hub":"subscribed","id":1}'], $client->messages);
        $client->messages = [];

        return $client;
    }

    /** @param array<string, mixed> $event */
    private function sendCursor(Client $client, array $event): void
    {
        $client->socket->send(json_encode(['hub' => 'cursor', 'event' => $event], \JSON_THROW_ON_ERROR));
    }

    /**
     * Waits until the server read everything a client sent (it answers a ping in order), then publishes a notice
     * to a topic and waits for it: what a listener received before it is all the client's cursor events brought.
     */
    private function sentinel(Client $sender, string $topic, Client $listener, int $before = 0): void
    {
        $pong = false;
        $sender->socket->once('pong', static function () use (&$pong): void {
            $pong = true;
        });
        $sender->socket->send(new Frame('sync', true, Frame::OP_PING));
        $this->until(static function () use (&$pong): bool {
            return $pong;
        });
        $this->clock->sleep(0.001);
        self::assertSame(204, $this->publish([self::notice([$topic], self::SENTINEL)]));
        $this->until(static fn(): bool => \in_array(self::SENTINEL, $listener->messages, true));
        self::assertSame($before, array_search(self::SENTINEL, $listener->messages, true));
        $listener->messages = [];
    }
}
