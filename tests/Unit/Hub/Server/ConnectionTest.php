<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Server;

use Janalis\Collaborate\Hub\Server\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\Socket\ConnectionInterface;
use React\Stream\ThroughStream;

#[CoversClass(Connection::class)]
final class ConnectionTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    /** @var list<string> */
    private array $received = [];

    private int $lastControl = 0;

    private ThroughStream $out;

    public function testAClientThatStopsReadingIsDroppedOnceTooFarBehind(): void
    {
        $socket = $this->createMock(ConnectionInterface::class);
        $socket->expects($this->once())->method('close');
        $connection = $this->connection($socket, maxBuffer: 10);
        $this->out->pause();

        $connection->send('12345', 7.0);
        self::assertSame(7.0, $connection->congestedSince);
        self::assertSame(0, $connection->backlog);
        $connection->send('12345', 8.0);
        self::assertSame(7.0, $connection->congestedSince);
        self::assertSame(5, $connection->backlog);
        $connection->send('12345', 9.0);
        self::assertSame(10, $connection->backlog);
        self::assertFalse($connection->closing);
        $connection->send('1', 9.0);
        self::assertTrue($connection->closing);
        self::assertSame(['12345', '12345', '12345'], $this->written);
        $connection->send('more', 10.0);
        self::assertSame(3, \count($this->written));
    }

    public function testDrainingEndsTheCongestion(): void
    {
        $connection = $this->connection(maxBuffer: 10);
        $this->out->pause();
        $connection->send('12345', 7.0);
        $connection->send('12345', 7.0);
        $connection->drained();
        $this->out->resume();

        self::assertNull($connection->congestedSince);
        self::assertSame(0, $connection->backlog);
        $connection->send('12345678901', 8.0);
        self::assertNull($connection->congestedSince);
        self::assertSame(0, $connection->backlog);
        self::assertFalse($connection->closing);
    }

    public function testClosingSendsTheCloseFrameOnceThenIgnoresTheClient(): void
    {
        $connection = $this->connection();
        $ended = false;
        $this->out->on('end', static function () use (&$ended): void {
            $ended = true;
        });
        $connection->close(4401, 5.0);
        $connection->close(1000, 6.0);

        self::assertSame(5.0, $connection->closingSince);

        self::assertTrue($ended);
        self::assertSame([Connection::closeFrame(4401)], $this->written);
        self::assertSame("\x88\x02\x11\x31", Connection::closeFrame(4401));
        $connection->receive(self::masked('{"hub":"subscribe"}'), 5.0);
        self::assertSame([], $this->received);
        self::assertSame(1.0, $connection->lastSeen);
    }

    public function testReceivingRecordsWhenAndReadsMessages(): void
    {
        $connection = $this->connection();
        $connection->receive(self::masked('hello'), 5.0);

        self::assertSame(5.0, $connection->lastSeen);
        self::assertSame(['hello'], $this->received);
        self::assertSame(1.0, $connection->opened);
    }

    public function testMessagesAreBoundedInSize(): void
    {
        $connection = $this->connection(maxMessageSize: 4);
        $connection->receive(self::masked('12345'), 5.0);

        self::assertSame([], $this->received);
        self::assertSame(Frame::CLOSE_TOO_BIG, $this->lastControl);
    }

    public function testSubscribesAreRateLimitedPerWindow(): void
    {
        $connection = $this->connection();

        self::assertTrue($connection->allowSubscribe(1.0, 2, 10.0));
        self::assertTrue($connection->allowSubscribe(2.0, 2, 10.0));
        self::assertFalse($connection->allowSubscribe(10.9, 2, 10.0));
        self::assertTrue($connection->allowSubscribe(11.0, 2, 10.0));
        self::assertTrue($connection->allowSubscribe(12.0, 2, 10.0));
        self::assertFalse($connection->allowSubscribe(12.0, 2, 10.0));
    }

    public function testIdsTellOpenConnectionsApart(): void
    {
        $first = $this->connection();
        $second = $this->connection();

        self::assertSame(spl_object_id($first), $first->id);
        self::assertNotSame($first->id, $second->id);
    }

    public function testDroppingClosesTheSocket(): void
    {
        $socket = $this->createMock(ConnectionInterface::class);
        $socket->expects($this->once())->method('close');
        $connection = $this->connection($socket);
        $connection->drop();

        self::assertTrue($connection->closing);
        self::assertSame($socket, $connection->socket);
    }

    private function connection(
        ?ConnectionInterface $socket = null,
        int $maxBuffer = 1024,
        int $maxMessageSize = 1024,
    ): Connection {
        $this->out = new ThroughStream();
        $this->out->on('data', function (string $frame): void {
            $this->written[] = $frame;
        });

        return new Connection(
            $socket ?? self::createStub(ConnectionInterface::class),
            $this->out,
            'https://app.example',
            '192.0.2.1',
            1.0,
            $maxBuffer,
            $maxMessageSize,
            function (Connection $connection, MessageInterface $message): void {
                $this->received[] = $message->getPayload();
            },
            function (Connection $connection, FrameInterface $frame): void {
                $this->lastControl = (\ord($frame->getPayload()[0]) << 8) | \ord($frame->getPayload()[1]);
            },
        );
    }

    private static function masked(string $payload): string
    {
        $frame = new Frame($payload);
        $frame->maskPayload();

        return $frame->getContents();
    }
}
