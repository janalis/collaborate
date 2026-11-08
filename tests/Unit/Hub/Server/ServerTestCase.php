<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Server;

use Janalis\Collaborate\Hub\Server\Cursors;
use Janalis\Collaborate\Hub\Server\Limits;
use Janalis\Collaborate\Hub\Server\Server;
use Janalis\Collaborate\Hub\Server\ServerConfig;
use Janalis\Collaborate\Hub\Server\ServerFactory;
use Janalis\Collaborate\Hub\Server\Timings;
use Janalis\Collaborate\Hub\Transport\WebSocket\Keys;
use Janalis\Collaborate\Hub\Transport\WebSocket\RequestSigner;
use Janalis\Collaborate\Hub\Transport\WebSocket\SubscriberToken;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Ratchet\Client\Connector as WebSocketConnector;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Socket\UnixConnector;
use Symfony\Component\Clock\MockClock;

/**
 * Runs the hub in this process: its loop, pawl or raw socket clients in the same loop, a temporary directory
 * for its unix sockets, a mock clock for what it decides and real time for its timer.
 */
abstract class ServerTestCase extends TestCase
{
    protected const string SECRET = 'a-test-secret-of-thirty-two-bytes-at-least';
    protected const string PREFIX = 'urn:test:';
    protected const string ORIGIN = 'https://app.example';
    protected const string PATH = '/hub';

    protected LoopInterface $loop;
    protected MockClock $clock;
    protected Keys $keys;
    protected SubscriberToken $tokens;
    protected RequestSigner $signer;
    protected string $directory;
    protected ?Server $server = null;

    /** @var list<array{string, string, array<array-key, mixed>}> level, message, context */
    protected array $logs = [];

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
        $this->clock = new MockClock('2026-01-01 00:00:00');
        $this->keys = new Keys(self::SECRET);
        $this->tokens = new SubscriberToken($this->keys, self::PATH, self::PREFIX, 300, 8);
        $this->signer = new RequestSigner($this->keys);
        $this->directory = sys_get_temp_dir() . '/hub-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->server?->shutdown();
        $this->loop->stop();
        $entries = scandir($this->directory);
        foreach (array_diff(false === $entries ? [] : $entries, ['.', '..']) as $entry) {
            unlink($this->directory . '/' . $entry);
        }
        rmdir($this->directory);
    }

    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $trustedProxies
     */
    protected function config(
        ?Limits $limits = null,
        ?Timings $timings = null,
        string $listen = '127.0.0.1:0',
        ?string $publishListen = null,
        array $allowedOrigins = [],
        array $trustedProxies = [],
    ): ServerConfig {
        return new ServerConfig(
            publicUrl: self::PATH,
            topicPrefix: self::PREFIX,
            listen: $listen,
            publishListen: $publishListen ?? 'unix://' . $this->directory . '/publish.sock',
            allowedOrigins: $allowedOrigins,
            trustedProxies: $trustedProxies,
            limits: $limits ?? self::limits(),
            timings: $timings ?? self::timings(),
        );
    }

    /** Limits small enough for stream_select(): the server would warn of larger ones. */
    protected static function limits(int ...$limits): Limits
    {
        return new Limits(...['maxConnections' => 100, 'maxPending' => 100, ...$limits]);
    }

    protected static function timings(
        float $pingInterval = 100.0,
        float $shutdownTimeout = 1.0,
        float $subscribeTimeout = 100.0,
        float $congestionTimeout = 100.0,
        float $headerTimeout = 100.0,
        float $statsInterval = 10_000.0,
        float $tickInterval = 0.005,
    ): Timings {
        return new Timings(
            pingInterval: $pingInterval,
            shutdownTimeout: $shutdownTimeout,
            subscribeTimeout: $subscribeTimeout,
            congestionTimeout: $congestionTimeout,
            headerTimeout: $headerTimeout,
            tickInterval: $tickInterval,
            statsInterval: $statsInterval,
        );
    }

    protected function start(?ServerConfig $config = null, ?Cursors $cursors = null): Server
    {
        $this->server = ServerFactory::create(
            $this->loop,
            $config ?? $this->config(),
            $this->tokens,
            $this->signer,
            $this->logger(),
            $this->clock,
            $cursors,
        );
        $this->server->start();

        return $this->server;
    }

    protected function logger(): AbstractLogger
    {
        return new class($this->logs) extends AbstractLogger {
            /** @param list<array{string, string, array<array-key, mixed>}> $logs */
            public function __construct(
                private array &$logs,
            ) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [(string) $level, (string) $message, $context];
            }
        };
    }

    /** The messages logged at a level. */
    protected function logged(string $level): array
    {
        return array_values(array_map(
            static fn(array $log): string => $log[1],
            array_filter($this->logs, static fn(array $log): bool => $level === $log[0]),
        ));
    }

    /** A connector reaching an address the server bound: tcp://host:port or unix:///path. */
    protected function connector(string $address): ConnectorInterface
    {
        if (!str_starts_with($address, 'unix://')) {
            return new Connector(['timeout' => 2.0], $this->loop);
        }

        return new readonly class($address, new UnixConnector($this->loop)) implements ConnectorInterface {
            public function __construct(
                private string $address,
                private UnixConnector $connector,
            ) {}

            public function connect($uri): PromiseInterface
            {
                return $this->connector->connect($this->address);
            }
        };
    }

    /** The ws:// URL of the browser side. */
    protected function url(string $path = self::PATH): string
    {
        $address = $this->server?->browserAddress() ?? '';

        return str_starts_with($address, 'unix://')
            ? 'ws://localhost' . $path
            : str_replace('tcp://', 'ws://', $address) . $path;
    }

    /**
     * Connects a browser.
     *
     * @param array<string, string> $headers
     *
     * @return PromiseInterface<WebSocket>
     */
    protected function browser(array $headers = ['Origin' => self::ORIGIN], string $path = self::PATH): PromiseInterface
    {
        $address = $this->server?->browserAddress() ?? '';

        return new WebSocketConnector($this->loop, $this->connector($address))($this->url($path), [], $headers);
    }

    /**
     * A browser connected and listening; its messages and its close code are recorded.
     *
     * @param array<string, string> $headers
     */
    protected function client(array $headers = ['Origin' => self::ORIGIN]): Client
    {
        $socket = $this->await($this->browser($headers));
        \assert($socket instanceof WebSocket);

        return new Client($socket);
    }

    /**
     * A subscriber token of this hub.
     *
     * @param non-empty-list<string> $topics
     */
    protected function token(array $topics, int $user = 1, string $origin = self::ORIGIN, ?int $now = null): string
    {
        return $this->tokens->sign($user, $origin, $topics, $now ?? $this->clock->now()->getTimestamp());
    }

    /**
     * A browser subscribed to topics.
     *
     * @param non-empty-list<string> $topics
     */
    protected function subscriber(array $topics, int $user = 1): Client
    {
        $client = $this->client();
        $client->subscribe(1, $this->token($topics, $user));
        $this->until(static fn(): bool => [] !== $client->messages);
        self::assertSame(['{"hub":"subscribed","id":1}'], $client->messages);
        $client->messages = [];

        return $client;
    }

    /** A raw connection to the browser side. */
    protected function raw(?string $address = null): ConnectionInterface
    {
        $address ??= $this->server?->browserAddress() ?? '';
        $connection = $this->await($this->connector($address)->connect($address));
        \assert($connection instanceof ConnectionInterface);

        return $connection;
    }

    /**
     * A raw browser past its handshake.
     *
     * @param array<string, string> $headers
     */
    protected function upgraded(array $headers = ['Origin' => self::ORIGIN], ?string $address = null): RawClient
    {
        $client = new RawClient($this->raw($address));
        $client->send(self::upgrade($headers));
        $this->until(static fn(): bool => str_contains($client->headers, "\r\n\r\n"));
        self::assertStringStartsWith('HTTP/1.1 101 ', $client->headers);

        return $client;
    }

    /**
     * The bytes of an upgrade request.
     *
     * @param array<string, string> $headers
     */
    protected static function upgrade(
        array $headers = ['Origin' => self::ORIGIN],
        string $method = 'GET',
        string $path = self::PATH,
    ): string {
        $headers += [
            'Host' => 'localhost',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
            'Sec-WebSocket-Version' => '13',
        ];
        $request = $method . ' ' . $path . " HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            $request .= $name . ': ' . $value . "\r\n";
        }

        return $request . "\r\n";
    }

    /** The status line a raw upgrade request gets. */
    protected function handshake(string $request, ?string $address = null): string
    {
        $connection = $this->raw($address);
        $received = '';
        $closed = false;
        $connection->on('data', static function (string $data) use (&$received): void {
            $received .= $data;
        });
        $connection->on('close', static function () use (&$closed): void {
            $closed = true;
        });
        $connection->write($request);
        // A socket refused as it was accepted closes with no response: an empty status line.
        $this->until(static function () use (&$received, &$closed, $connection): bool {
            return $closed || !$connection->isReadable() || str_contains($received, "\r\n");
        });
        $connection->close();

        $line = strstr($received, "\r\n", true);

        return false === $line ? '' : $line;
    }

    /**
     * Sends a signed request to the publish side.
     *
     * @return ResponseInterface
     */
    protected function request(
        string $method,
        string $target,
        string $body = '',
        ?string $signature = null,
        array $headers = [],
    ): ResponseInterface {
        $signature ??= $this->signer->sign($method, $target, $body, $this->clock->now()->getTimestamp());
        $address = $this->server?->publishAddress() ?? '';
        $base = str_starts_with($address, 'unix://') ? 'http://localhost' : str_replace('tcp://', 'http://', $address);
        $browser = new Browser($this->connector($address), $this->loop)->withRejectErrorResponse(false);
        $response = $this->await($browser->request(
            $method,
            $base . $target,
            $headers + [RequestSigner::HEADER => $signature],
            $body,
        ));
        \assert($response instanceof ResponseInterface);

        return $response;
    }

    /** Publishes notices through the signed endpoint; the response status. */
    protected function publish(array $notices): int
    {
        $body = json_encode(['notices' => $notices], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return $this->request('POST', '/publish', $body, null, ['Content-Type' => 'application/json'])->getStatusCode();
    }

    /**
     * Runs the loop until a condition holds; fails after `$timeout` seconds.
     *
     * @param callable(): bool $condition
     */
    protected function until(callable $condition, float $timeout = 2.0): void
    {
        $deadline = microtime(true) + $timeout;
        $timer = $this->loop->addPeriodicTimer(0.001, function () use ($condition, $deadline): void {
            if ($condition() || microtime(true) > $deadline) {
                $this->loop->stop();
            }
        });
        // The hub stops the loop too, once it shut down: run it again until the condition holds or time is up.
        while (!$condition() && microtime(true) <= $deadline) {
            $this->loop->run();
        }
        $this->loop->cancelTimer($timer);
        self::assertTrue($condition(), 'The condition did not hold in time.');
    }

    /** Runs the loop for a while. */
    protected function wait(float $seconds): void
    {
        $this->loop->addTimer($seconds, $this->loop->stop(...));
        $this->loop->run();
    }

    /**
     * Runs the loop until a promise settles; its value, or its reason thrown.
     *
     * @template T
     *
     * @param PromiseInterface<T> $promise
     *
     * @return T
     */
    protected function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $settled = false;
        $value = null;
        $error = null;
        $promise->then(static function (mixed $result) use (&$settled, &$value): void {
            $settled = true;
            $value = $result;
        }, static function (\Throwable $reason) use (&$settled, &$error): void {
            $settled = true;
            $error = $reason;
        });
        $this->until(static function () use (&$settled): bool {
            return $settled;
        }, $timeout);
        if ($error instanceof \Throwable) {
            throw $error;
        }

        return $value;
    }

    /** One notice. */
    protected static function notice(array $topics, string $data = '{"type":"test"}'): array
    {
        return ['topics' => $topics, 'data' => $data];
    }
}
