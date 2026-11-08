<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Server;

use Janalis\Collaborate\Hub\Server\Connection;
use Janalis\Collaborate\Hub\Server\Router;
use Janalis\Collaborate\Hub\Transport\WebSocket\TokenClaims;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use React\Socket\ConnectionInterface;
use React\Stream\ThroughStream;

#[CoversClass(Router::class)]
#[UsesClass(Connection::class)]
#[UsesClass(TokenClaims::class)]
final class RouterTest extends TestCase
{
    /** @var list<Connection> kept alive: their ids stay unique */
    private array $connections = [];

    /** @var array<int, list<string>> frames written, per connection */
    private array $written = [];

    public function testANoticeOfSeveralTopicsReachesEachConnectionOnce(): void
    {
        $router = new Router();
        $both = $this->connection();
        $one = $this->connection();
        $router->subscribe($both, self::claims(['a', 'b']));
        $router->subscribe($one, self::claims(['b']));

        self::assertSame(2, $router->dispatch(['a', 'b'], 'frame', 50.0));
        self::assertSame(['frame'], $this->written[$both->id]);
        self::assertSame(['frame'], $this->written[$one->id]);
        self::assertSame(1, $router->dispatch(['a', 'c'], 'other', 50.0));
        self::assertSame(['frame', 'other'], $this->written[$both->id]);
        self::assertSame(0, $router->dispatch(['c'], 'none', 50.0));
        self::assertSame(100, $both->exp);
    }

    public function testARenewalReplacesTopicsAtOnce(): void
    {
        $router = new Router();
        $connection = $this->connection();
        $router->subscribe($connection, self::claims(['a', 'b'], 100));
        $router->subscribe($connection, self::claims(['a'], 200));

        self::assertSame(200, $connection->exp);
        self::assertSame(0, $router->dispatch(['b'], 'frame', 50.0));
        self::assertFalse($router->listening('b', 50.0));
        self::assertSame(1, $router->topics());
        self::assertSame(1, $router->dispatch(['a'], 'frame', 150.0));
    }

    public function testExpiryIsCheckedAtSend(): void
    {
        $router = new Router();
        $expired = $this->connection();
        $valid = $this->connection();
        $router->subscribe($expired, self::claims(['a'], 100));
        $router->subscribe($valid, self::claims(['a'], 101));

        self::assertSame(1, $router->dispatch(['a'], 'frame', 100.0));
        self::assertSame([], $this->written[$expired->id]);
        self::assertSame(['frame'], $this->written[$valid->id]);
        self::assertSame(2, $router->dispatch(['a'], 'frame', 99.9));
    }

    public function testListeningCountsValidSubscriptionsOnly(): void
    {
        $router = new Router();
        $expired = $this->connection();
        $router->subscribe($expired, self::claims(['a'], 100));

        self::assertTrue($router->listening('a', 99.0));
        self::assertFalse($router->listening('a', 100.0));
        self::assertFalse($router->listening('b', 0.0));

        $router->subscribe($this->connection(), self::claims(['a'], 200));
        self::assertTrue($router->listening('a', 100.0));
    }

    public function testRemovingAConnectionForgetsItsTopics(): void
    {
        $router = new Router();
        $gone = $this->connection();
        $stays = $this->connection();
        $router->subscribe($gone, self::claims(['a', 'b']));
        $router->subscribe($stays, self::claims(['b']));
        $router->remove($gone);
        $router->remove($gone);

        self::assertSame(1, $router->topics());
        self::assertSame(1, $router->dispatch(['a', 'b'], 'frame', 50.0));
        self::assertSame([], $this->written[$gone->id]);
        $router->remove($stays);
        self::assertSame(0, $router->topics());
    }

    private function connection(): Connection
    {
        $out = new ThroughStream();
        $written = [];
        $out->on('data', static function (string $frame) use (&$written): void {
            $written[] = $frame;
        });
        $connection = new Connection(
            self::createStub(ConnectionInterface::class),
            $out,
            'https://app.example',
            null,
            0.0,
            1024,
            1024,
            static function (): void {},
            static function (): void {},
        );
        $this->written[$connection->id] = &$written;
        $this->connections[] = $connection;

        return $connection;
    }

    /** @param non-empty-list<string> $topics */
    private static function claims(array $topics, int $exp = 100): TokenClaims
    {
        return new TokenClaims(1, 'https://app.example', $topics, $exp);
    }
}
