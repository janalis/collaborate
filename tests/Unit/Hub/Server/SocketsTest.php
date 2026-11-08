<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Server;

use Janalis\Collaborate\Hub\Server\Counter;
use Janalis\Collaborate\Hub\Server\Seconds;
use Janalis\Collaborate\Hub\Server\Sockets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Socket\Connection;
use Symfony\Component\Clock\MockClock;

#[CoversClass(Sockets::class)]
#[UsesClass(Counter::class)]
#[UsesClass(Seconds::class)]
final class SocketsTest extends TestCase
{
    private StreamSelectLoop $loop;

    private MockClock $clock;

    private Sockets $sockets;

    /** @var float the mock clock's start */
    private float $start;

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
        $this->clock = new MockClock('2026-01-01 00:00:00 UTC');
        $this->start = Seconds::of($this->clock);
        $this->sockets = new Sockets($this->clock);
    }

    public function testNoSocketIsClaimedBeforeAnyIsRead(): void
    {
        $this->expectException(\LogicException::class);
        $this->sockets->claim();
    }

    public function testClaimsTheSocketBeingReadAndStopsItsHeaderDeadline(): void
    {
        $first = $this->socket();
        $second = $this->socket();
        $this->sockets->accept($first, '192.0.2.1');
        $this->sockets->accept($second, '192.0.2.1');
        self::assertSame(2, $this->sockets->waiting('192.0.2.1'));
        $first->emit('data', ['GET']);
        $second->emit('data', ['GET']);

        self::assertSame($second, $this->sockets->claim());
        self::assertSame($second, $this->sockets->claim());
        self::assertSame(1, $this->sockets->waiting('192.0.2.1'));
        $this->sockets->expire($this->start + 20, 5.0);
        self::assertTrue($second->isReadable());
        self::assertFalse($first->isReadable());
        self::assertSame(0, $this->sockets->waiting('192.0.2.1'));
        self::assertSame(1, $this->sockets->count());
    }

    public function testClosesTheSocketsThatTookTooLongToSendTheirHeaders(): void
    {
        $late = $this->socket();
        $recent = $this->socket();
        $this->sockets->accept($late);
        $this->clock->sleep(0.5);
        $this->sockets->accept($recent);
        $this->sockets->expire($this->start + 4.9, 5.0);

        self::assertTrue($late->isReadable());
        $this->sockets->expire($this->start + 5.0, 5.0);
        self::assertFalse($late->isReadable());
        self::assertTrue($recent->isReadable());
        self::assertSame(1, $this->sockets->count());
    }

    public function testClosesTheSocketsIdleTooLong(): void
    {
        $idle = $this->socket();
        $busy = $this->socket();
        $this->sockets->accept($idle);
        $this->sockets->accept($busy);
        $this->clock->sleep(20);
        $busy->emit('data', ['POST']);
        $this->sockets->idle($this->start + 29.9, 30.0);

        self::assertTrue($idle->isReadable());
        $this->sockets->idle($this->start + 30.0, 30.0);
        self::assertFalse($idle->isReadable());
        self::assertTrue($busy->isReadable());
        $this->sockets->idle($this->start + 50.0, 30.0);
        self::assertFalse($busy->isReadable());
    }

    public function testReleasedSocketsAreNoLongerTracked(): void
    {
        $released = $this->socket();
        $kept = $this->socket();
        $this->sockets->accept($released, '192.0.2.1');
        $this->sockets->accept($kept);
        $this->sockets->release($released);
        // What a released socket reads does not track it again.
        $released->emit('data', ['frame']);
        $this->sockets->expire($this->start + 100, 5.0);
        $this->sockets->idle($this->start + 100, 5.0);

        self::assertTrue($released->isReadable());
        self::assertSame(0, $this->sockets->waiting('192.0.2.1'));
        self::assertSame(0, $this->sockets->count());
    }

    public function testClosesEveryOpenSocket(): void
    {
        $first = $this->socket();
        $second = $this->socket();
        $this->sockets->accept($first);
        $this->sockets->accept($second);
        $second->emit('data', ['GET']);
        $this->sockets->claim();
        $this->sockets->closeAll();

        self::assertFalse($first->isReadable());
        self::assertFalse($second->isReadable());
        self::assertSame(0, $this->sockets->count());
    }

    private function socket(): Connection
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        return new Connection($pair[0], $this->loop);
    }
}
