<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Integration;

use Janalis\Collaborate\Hub\Notice;
use Janalis\Collaborate\Hub\Transport\WebSocket\Keys;
use Janalis\Collaborate\Hub\Transport\WebSocket\RequestSigner;
use Janalis\Collaborate\Hub\Transport\WebSocket\SubscriberToken;
use Janalis\Collaborate\Hub\Transport\WebSocket\WebSocketTransport;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Ratchet\Client\Connector as WebSocketConnector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use React\Socket\ConnectorInterface;
use React\Socket\UnixConnector;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\Process\Process;

/**
 * `collaborate:hub:serve` in a process of its own, as deployed: unix sockets on both sides, a browser subscribed
 * through pawl, the application publishing through the real transport over curl, and SIGTERM ending it with
 * 1001 and exit code 0. The in-process tests (tests/Unit/Hub/Server) cover the rest.
 */
#[CoversNothing]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('curl')]
final class HubServerProcessTest extends TestCase
{
    private const string SECRET = 'a-process-test-secret-of-32-bytes-or-more';
    private const string PREFIX = 'urn:process:';
    private const string ORIGIN = 'https://app.example';

    private string $directory;

    private ?Process $process = null;

    protected function setUp(): void
    {
        // A short path: unix socket paths are limited to about 100 bytes.
        $this->directory = sys_get_temp_dir() . '/hubp-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->process?->stop(0);
        $entries = scandir($this->directory);
        foreach (array_diff(false === $entries ? [] : $entries, ['.', '..']) as $entry) {
            unlink($this->directory . '/' . $entry);
        }
        rmdir($this->directory);
    }

    public function testDeliversAndClosesWith1001OnSigterm(): void
    {
        $browser = $this->directory . '/browser.sock';
        $publish = $this->directory . '/publish.sock';
        $process = new Process([\PHP_BINARY, $this->script($browser, $publish)]);
        $this->process = $process;
        $process->start();
        $deadline = microtime(true) + 10;
        while (
            !(file_exists($browser) && file_exists($publish))
            && $process->isRunning()
            && microtime(true) < $deadline
        ) {
            usleep(10_000);
        }
        self::assertTrue($process->isRunning(), $process->getErrorOutput() . $process->getOutput());
        self::assertFileExists($publish);

        $keys = new Keys(self::SECRET);
        $tokens = new SubscriberToken($keys, '/hub', self::PREFIX, 300, 8);
        $loop = new StreamSelectLoop();
        $messages = [];
        $code = null;
        // @mago-expect analysis:mixed-assignment pawl's connector names no promise type: the next line narrows it
        $socket = $this->await($loop, new WebSocketConnector($loop, self::unix($loop, $browser))(
            'ws://localhost/hub',
            [],
            ['Origin' => self::ORIGIN],
        ));
        self::assertInstanceOf(WebSocket::class, $socket);
        $socket->on('message', static function (MessageInterface $message) use (&$messages): void {
            $messages[] = $message->getPayload();
        });
        $socket->on('close', static function (?int $closed = null) use (&$code): void {
            $code = $closed;
        });
        $socket->send(json_encode(
            [
                'hub' => 'subscribe',
                'id' => 1,
                'token' => $tokens->sign(1, self::ORIGIN, [self::PREFIX . 'a'], time()),
            ],
            \JSON_THROW_ON_ERROR,
        ));
        $this->runUntil($loop, static function () use (&$messages): bool {
            return [] !== $messages;
        });
        self::assertSame(['{"hub":"subscribed","id":1}'], $messages);

        $transport = new WebSocketTransport(
            new CurlHttpClient(),
            new RequestSigner($keys),
            $tokens,
            '/hub',
            'http://localhost',
            $publish,
        );
        self::assertTrue($transport->listening(self::PREFIX . 'a'));
        self::assertFalse($transport->listening(self::PREFIX . 'b'));
        $transport->publish([new Notice([self::PREFIX . 'a'], '{"type":"process"}')]);
        $this->runUntil($loop, static function () use (&$messages): bool {
            return 2 === \count($messages);
        });
        self::assertSame(['{"type":"process"}'], array_slice($messages, 1));

        $process->signal(15);
        $this->runUntil($loop, static function () use (&$code): bool {
            return null !== $code;
        });
        self::assertSame(1001, $code);
        self::assertSame(0, $process->wait(), $process->getErrorOutput());
        self::assertFileDoesNotExist($browser);
        self::assertFileDoesNotExist($publish);
    }

    /** A script running the command as the bundle wires it. */
    private function script(string $browser, string $publish): string
    {
        $script = $this->directory . '/serve.php';
        file_put_contents($script, \sprintf(<<<'PHP'
            <?php
            require %s;

            use Janalis\Collaborate\Command\HubServeCommand;
            use Janalis\Collaborate\Hub\Transport\WebSocket\Keys;
            use Janalis\Collaborate\Hub\Transport\WebSocket\RequestSigner;
            use Janalis\Collaborate\Hub\Transport\WebSocket\SubscriberToken;
            use Symfony\Component\Console\Input\ArrayInput;
            use Symfony\Component\Console\Output\ConsoleOutput;

            $keys = new Keys(%s);
            $command = new HubServeCommand(
                new SubscriberToken($keys, '/hub', %s, 300, 8),
                new RequestSigner($keys),
                %s,
                new Janalis\Collaborate\Cursor\CursorEvents(),
                true,
                4096,
                50,
                ['public_url' => '/hub', 'path' => null, 'listen' => %s, 'publish_listen' => %s, 'socket_mode' => 0660,
                 'allowed_origins' => [], 'trusted_proxies' => [], 'ping_interval' => 25, 'max_connections' => 100,
                 'max_connections_per_ip' => 0, 'max_connections_per_user' => 50, 'max_pending' => 100,
                 'max_message_size' => 16384, 'max_buffer' => 1048576, 'shutdown_timeout' => 5],
            );
            exit($command->run(new ArrayInput([]), new ConsoleOutput()));
            PHP, var_export(
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            true,
        ), var_export(self::SECRET, true), var_export(self::PREFIX, true), var_export(self::PREFIX, true), var_export('unix://' . $browser, true), var_export('unix://' . $publish, true)));

        return $script;
    }

    private static function unix(StreamSelectLoop $loop, string $path): ConnectorInterface
    {
        return new readonly class($path, new UnixConnector($loop)) implements ConnectorInterface {
            public function __construct(
                private string $path,
                private UnixConnector $connector,
            ) {}

            public function connect($uri): PromiseInterface
            {
                return $this->connector->connect('unix://' . $this->path);
            }
        };
    }

    /**
     * @template T
     *
     * @param PromiseInterface<T> $promise
     *
     * @return T
     */
    private function await(StreamSelectLoop $loop, PromiseInterface $promise): mixed
    {
        $settled = false;
        $value = null;
        $promise->then(static function (mixed $result) use (&$settled, &$value): void {
            $settled = true;
            $value = $result;
        }, static function (\Throwable $error): never {
            throw $error;
        });
        $this->runUntil($loop, static function () use (&$settled): bool {
            return $settled;
        });

        return $value;
    }

    /** @param callable(): bool $condition */
    private function runUntil(StreamSelectLoop $loop, callable $condition): void
    {
        $deadline = microtime(true) + 5;
        $timer = $loop->addPeriodicTimer(0.005, static function () use ($loop, $condition, $deadline): void {
            if ($condition() || microtime(true) > $deadline) {
                $loop->stop();
            }
        });
        $loop->run();
        $loop->cancelTimer($timer);
        self::assertTrue($condition(), 'The condition did not hold in time.');
    }
}
