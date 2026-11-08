<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Http\HttpServer;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\ConnectionInterface;
use React\Socket\ServerInterface;
use React\Socket\SocketServer;

/**
 * The running hub: both listeners, the shared timer and the signals. On SIGTERM or SIGINT it stops accepting,
 * closes every browser with 1001 (going away) and stops the loop once they are gone, or after
 * `shutdown_timeout` seconds.
 */
final class Server
{
    private const int SIGINT = 2;
    private const int SIGTERM = 15;

    /** @var array<string, ServerInterface> address => listener */
    private array $listeners = [];

    private ?TimerInterface $timer = null;

    private ?\Closure $signal = null;

    private float $nextStats = 0.0;

    private bool $stopping = false;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ServerConfig $config,
        private readonly BrowserEndpoint $browser,
        private readonly Sockets $browserSockets,
        private readonly PublishEndpoint $publish,
        private readonly Router $router,
        private readonly Sockets $publishSockets,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Binds both addresses and arms the timer and the signals.
     *
     * @throws \RuntimeException when an address cannot be bound
     */
    public function start(): void
    {
        $browser = $this->listen($this->config->listen, $this->browser->accept(...));
        try {
            $publish = $this->listen($this->config->publishListen, $this->publish->accept(...));
        } catch (\RuntimeException $error) {
            $this->close();

            throw $error;
        }
        new HttpServer($this->loop, new StreamingRequestMiddleware(), $this->browser)->listen($browser);
        $this->publishServer()->listen($publish);
        $timings = $this->config->timings;
        $this->nextStats = $this->now() + $timings->statsInterval;
        $this->timer = $this->loop->addPeriodicTimer($timings->tickInterval, $this->tick(...));
        $this->signal = $this->shutdown(...);
        try {
            $this->loop->addSignal(self::SIGTERM, $this->signal);
            $this->loop->addSignal(self::SIGINT, $this->signal);
        } catch (\BadMethodCallException) {
            $this->signal = null;
            $this->logger->warning(
                'This event loop handles no signals (install ext-pcntl, ext-ev or ext-event): SIGTERM stops the hub without closing its clients.',
            );
        }
        $this->logger->info('Hub listening for browsers on {listen}, for the application on {publish_listen}.', [
            'listen' => $this->address($browser),
            'publish_listen' => $this->address($publish),
        ]);
    }

    /** Where browsers connect: the address bound, its port resolved. */
    public function browserAddress(): string
    {
        return $this->address($this->listeners[$this->config->listen]);
    }

    /** Where the application publishes: the address bound, its port resolved. */
    public function publishAddress(): string
    {
        return $this->address($this->listeners[$this->config->publishListen]);
    }

    /** Stops accepting, says goodbye to every browser, then stops the loop. */
    public function shutdown(): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;
        $this->logger->info('Hub shutting down: closing {count} connection(s).', ['count' => $this->browser->count()]);
        $this->close();
        if (null !== $this->timer) {
            $this->loop->cancelTimer($this->timer);
        }
        if (null !== $this->signal) {
            $this->loop->removeSignal(self::SIGTERM, $this->signal);
            $this->loop->removeSignal(self::SIGINT, $this->signal);
        }
        $this->publishSockets->closeAll();
        $this->browserSockets->closeAll();
        $deadline = $this->loop->addTimer($this->config->timings->shutdownTimeout, $this->loop->stop(...));
        $this->browser->shutdown(function () use ($deadline): void {
            $this->loop->cancelTimer($deadline);
            $this->loop->stop();
        });
    }

    private function tick(): void
    {
        $now = $this->now();
        $timings = $this->config->timings;
        $this->browser->tick($now);
        $this->browserSockets->expire($now, $timings->headerTimeout);
        $this->publishSockets->expire($now, $timings->headerTimeout);
        $this->publishSockets->idle($now, $timings->idleTimeout);
        $this->publish->prune($now);
        if ($now >= $this->nextStats) {
            $this->nextStats = $now + $timings->statsInterval;
            $this->logger->info(
                'Hub: {connections} connection(s), {pending} pending, {topics} topic(s); {published} notice(s) published, {delivered} frame(s) sent; {cursors} cursor frame(s) relayed, {invalid_cursors} invalid cursor event(s).',
                [
                    'connections' => $this->browser->count(),
                    'pending' => $this->browser->pending(),
                    'topics' => $this->router->topics(),
                    'published' => $this->publish->published,
                    'delivered' => $this->publish->delivered,
                    'cursors' => $this->browser->relayedCursors,
                    'invalid_cursors' => $this->browser->invalidCursors,
                ],
            );
        }
    }

    /**
     * The application's side: bodies read up to {@see PublishEndpoint::MAX_BODY}, 64 requests at once.
     *
     * @infection-ignore-all no test sends 64 concurrent requests to tell another concurrency apart
     */
    private function publishServer(): HttpServer
    {
        return new HttpServer(
            $this->loop,
            new StreamingRequestMiddleware(),
            $this->publish->limit(...),
            new LimitConcurrentRequestsMiddleware(64),
            new RequestBodyBufferMiddleware(PublishEndpoint::MAX_BODY),
            $this->publish,
        );
    }

    /** @throws \RuntimeException */
    /** @param \Closure(ConnectionInterface): void $accept */
    private function listen(string $address, \Closure $accept): ServerInterface
    {
        $path = ServerConfig::unixPath($address);
        if (null === $path) {
            $listener = new SocketServer($address, self::context(), $this->loop);
        } else {
            self::clearStale($path);
            $listener = $this->bindUnix($address);
        }
        $listener->on('connection', $accept);

        return $this->listeners[$address] = $listener;
    }

    /**
     * Binds a unix socket under the umask that gives it `socket_mode` as it is created: no chmod after it, which
     * would follow a symbolic link put at its path meanwhile.
     */
    private function bindUnix(string $address): ServerInterface
    {
        $mask = umask(0o777 & ~$this->config->socketMode);
        try {
            return new SocketServer($address, self::context(), $this->loop);
        } finally {
            umask($mask);
        }
    }

    /**
     * How both sides listen. Accepted TCP sockets send each frame at once (no Nagle delay): a cursor move is a
     * small frame no other one follows soon.
     *
     * @internal
     *
     * @return array{tcp: array{backlog: int, tcp_nodelay: bool}, unix: array{backlog: int}}
     */
    public static function context(): array
    {
        return [
            'tcp' => ['backlog' => self::backlog(), 'tcp_nodelay' => true],
            'unix' => ['backlog' => self::backlog()],
        ];
    }

    /**
     * A listen backlog for reconnect storms: PHP's default of 32 refuses the connections a restart brings back at
     * once. The kernel caps it (somaxconn).
     *
     * @infection-ignore-all no test tells a backlog from the next one: the kernel rounds and caps it
     */
    private static function backlog(): int
    {
        return 4096;
    }

    /** Closes the listeners and removes their socket files. */
    private function close(): void
    {
        foreach ($this->listeners as $address => $listener) {
            $listener->close();
            $path = ServerConfig::unixPath($address);
            if (null !== $path && !is_link($path) && file_exists($path) && 'socket' === filetype($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Removes the socket file a crashed server left behind. Anything else at its path — a symbolic link, a
     * file, a socket some process still listens on — is left untouched, and the server does not start.
     *
     * @throws \RuntimeException
     */
    private static function clearStale(string $path): void
    {
        if (is_link($path)) {
            throw new \RuntimeException(\sprintf('%s is a symbolic link: the hub does not replace it.', $path));
        }
        if (!file_exists($path)) {
            return;
        }
        if ('socket' !== filetype($path)) {
            throw new \RuntimeException(\sprintf('%s exists and is no socket: the hub does not replace it.', $path));
        }
        // @mago-expect lint:no-error-control-operator nothing listening is the answer sought, not an error
        $live = @stream_socket_client('unix://' . $path);
        if (false !== $live) {
            fclose($live);

            throw new \RuntimeException(\sprintf('%s is in use: is the hub running already?', $path));
        }
        unlink($path);
    }

    private function address(ServerInterface $listener): string
    {
        return (string) $listener->getAddress();
    }

    private function now(): float
    {
        return Seconds::of($this->clock);
    }
}
