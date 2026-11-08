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
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\UsesClass;
use React\Socket\Connection as Socket;
use React\Socket\Connector;
use React\Socket\SocketServer;

#[CoversClass(Server::class)]
#[CoversClass(ServerFactory::class)]
#[CoversClass(BrowserEndpoint::class)]
#[CoversClass(PublishEndpoint::class)]
#[CoversClass(Sockets::class)]
#[UsesClass(ClientAddress::class)]
#[UsesClass(Counter::class)]
#[UsesClass(Connection::class)]
#[UsesClass(Limits::class)]
#[UsesClass(Seconds::class)]
#[UsesClass(Router::class)]
#[UsesClass(ServerConfig::class)]
#[UsesClass(Timings::class)]
#[UsesClass(Keys::class)]
#[UsesClass(Origin::class)]
#[UsesClass(RequestSigner::class)]
#[UsesClass(SubscriberToken::class)]
#[UsesClass(TokenClaims::class)]
#[UsesClass(WebSocketTransport::class)]
final class ServerTest extends ServerTestCase
{
    private const string A = self::PREFIX . 'a';

    /** A signed publish request, as curl sends it over a kept-alive socket. */
    private function publishRequest(string $data): string
    {
        $body = json_encode(['notices' => [self::notice([self::A], $data)]], \JSON_THROW_ON_ERROR);
        $signature = $this->signer->sign('POST', '/publish', $body, $this->clock->now()->getTimestamp());

        return (
            "POST /publish HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: "
            . \strlen($body)
            . "\r\n"
            . RequestSigner::HEADER
            . ': '
            . $signature
            . "\r\n\r\n"
            . $body
        );
    }

    public function testListensOnBothSidesAndLogsTheAddressesBound(): void
    {
        $server = $this->start();

        self::assertMatchesRegularExpression('#^tcp://127\.0\.0\.1:[1-9]\d*$#', $server->browserAddress());
        self::assertSame('unix://' . $this->directory . '/publish.sock', $server->publishAddress());
        self::assertSame(
            [[
                'info',
                'Hub listening for browsers on {listen}, for the application on {publish_listen}.',
                ['listen' => $server->browserAddress(), 'publish_listen' => $server->publishAddress()],
            ]],
            $this->logs,
        );
    }

    public function testCreatesUnixSocketsWithTheirModeAndRemovesThemOnShutdown(): void
    {
        $umask = umask();
        $browser = $this->directory . '/browser.sock';
        $publish = $this->directory . '/publish.sock';
        $config = new ServerConfig(
            publicUrl: self::PATH,
            topicPrefix: self::PREFIX,
            listen: 'unix://' . $browser,
            publishListen: 'unix://' . $publish,
            socketMode: 0o600,
            timings: self::timings(),
        );
        $this->start($config);

        self::assertSame('socket', filetype($browser));
        self::assertSame(0o600, fileperms($browser) & 0o777);
        self::assertSame(0o600, fileperms($publish) & 0o777);
        self::assertSame($umask, umask());
        $client = $this->subscriber([self::A]);
        $this->server?->shutdown();
        $this->until(static fn(): bool => $client->closed);
        self::assertSame(1001, $client->code);
        self::assertFileDoesNotExist($browser);
        self::assertFileDoesNotExist($publish);
    }

    public function testRemovesOnlyItsOwnSocketsOnShutdown(): void
    {
        $gone = $this->directory . '/browser.sock';
        $replaced = $this->directory . '/publish.sock';
        $server = $this->start($this->config(listen: 'unix://' . $gone));
        unlink($gone);
        unlink($replaced);
        file_put_contents($replaced, 'kept');
        $server->shutdown();

        self::assertSame('kept', file_get_contents($replaced));
    }

    public function testLeavesASymbolicLinkPutInPlaceOfItsSocket(): void
    {
        $path = $this->directory . '/publish.sock';
        $server = $this->start();
        unlink($path);
        $target = stream_socket_server('unix://' . $this->directory . '/target.sock');
        self::assertIsResource($target);
        symlink($this->directory . '/target.sock', $path);
        $server->shutdown();
        fclose($target);

        self::assertTrue(is_link($path));
    }

    #[RequiresPhpExtension('sockets')]
    public function testAcceptedTcpSocketsSendWithoutDelay(): void
    {
        $listener = new SocketServer('127.0.0.1:0', Server::context(), $this->loop);
        $accepted = null;
        $listener->on('connection', static function (Socket $socket) use (&$accepted): void {
            $accepted = $socket;
        });
        $this->await(new Connector([], $this->loop)->connect((string) $listener->getAddress()));
        $this->until(static function () use (&$accepted): bool {
            return null !== $accepted;
        });
        \assert($accepted instanceof Socket);
        // @mago-expect analysis:mixed-assignment react/socket types its stream in a docblock only
        $stream = $accepted->stream;
        self::assertIsResource($stream);
        $socket = socket_import_stream($stream);
        self::assertInstanceOf(\Socket::class, $socket);

        self::assertGreaterThan(0, socket_get_option($socket, \SOL_TCP, \TCP_NODELAY));
        $listener->close();
    }

    public function testAcceptsABurstOfConnectionsOnAUnixSocket(): void
    {
        $path = $this->directory . '/browser.sock';
        $this->start($this->config(listen: 'unix://' . $path));
        $clients = [];
        // All connect before the loop accepts any: PHP's default backlog of 32 would refuse the rest.
        for ($i = 0; $i < 100; ++$i) {
            // @mago-expect lint:no-error-control-operator a refused connection is the failure this test reports
            $client = @stream_socket_client('unix://' . $path);
            self::assertIsResource($client, 'Connection ' . $i . ' was refused.');
            $clients[] = $client;
        }
        array_map(fclose(...), $clients);
    }

    public function testReplacesTheSocketACrashedServerLeft(): void
    {
        $path = $this->directory . '/publish.sock';
        $stale = stream_socket_server('unix://' . $path);
        self::assertIsResource($stale);
        fclose($stale);
        self::assertSame('socket', filetype($path));
        $this->start();

        self::assertSame(204, $this->publish([self::notice([self::A])]));
    }

    public function testLeavesASocketInUseAlone(): void
    {
        $path = $this->directory . '/publish.sock';
        $live = stream_socket_server('unix://' . $path);
        self::assertIsResource($live);

        try {
            $this->start();
            self::fail('The hub started on a socket in use.');
        } catch (\RuntimeException $error) {
            self::assertSame($path . ' is in use: is the hub running already?', $error->getMessage());
        }
        self::assertSame('socket', filetype($path));
        fclose($live);
    }

    public function testLeavesASymbolicLinkAlone(): void
    {
        $target = $this->directory . '/target';
        file_put_contents($target, 'kept');
        $path = $this->directory . '/publish.sock';
        symlink($target, $path);

        try {
            $this->start();
            self::fail('The hub replaced a symbolic link.');
        } catch (\RuntimeException $error) {
            self::assertSame($path . ' is a symbolic link: the hub does not replace it.', $error->getMessage());
        }
        self::assertTrue(is_link($path));
        self::assertSame('kept', file_get_contents($target));
    }

    public function testLeavesADanglingSymbolicLinkAlone(): void
    {
        $path = $this->directory . '/publish.sock';
        symlink($this->directory . '/nothing', $path);

        $this->expectExceptionMessage('is a symbolic link');
        try {
            $this->start();
        } finally {
            self::assertTrue(is_link($path));
        }
    }

    public function testLeavesAFileAloneAndClosesWhatItBound(): void
    {
        $path = $this->directory . '/publish.sock';
        file_put_contents($path, 'kept');
        $browser = $this->directory . '/browser.sock';

        try {
            $this->start($this->config(listen: 'unix://' . $browser));
            self::fail('The hub replaced a file.');
        } catch (\RuntimeException $error) {
            self::assertSame($path . ' exists and is no socket: the hub does not replace it.', $error->getMessage());
        }
        self::assertSame('kept', file_get_contents($path));
        self::assertFileDoesNotExist($browser);
        $this->server = null;
    }

    public function testArmsAndRemovesTheSignals(): void
    {
        $this->loop = new RecordingLoop();
        $server = $this->start();
        self::assertSame(['add 15', 'add 2'], $this->loop->signals);
        $server->shutdown();
        $server->shutdown();

        self::assertSame(['add 15', 'add 2', 'remove 15', 'remove 2'], $this->loop->signals);
        self::assertSame(1, \count(array_filter($this->logs, static fn(array $log): bool => str_starts_with(
            $log[1],
            'Hub shutting down',
        ))));
    }

    public function testWarnsWhenTheLoopHandlesNoSignals(): void
    {
        $this->loop = new RecordingLoop(false);
        $server = $this->start();
        $server->shutdown();

        self::assertSame([], $this->loop->signals);
        self::assertSame(
            [
                'This event loop handles no signals (install ext-pcntl, ext-ev or ext-event): SIGTERM stops the hub without closing its clients.',
            ],
            $this->logged('warning'),
        );
    }

    #[RequiresFunction('posix_kill')]
    public function testSigtermShutsDownGracefully(): void
    {
        $this->start();
        $client = $this->subscriber([self::A]);
        posix_kill((int) getmypid(), 15);
        $this->until(static fn(): bool => $client->closed);

        self::assertSame(1001, $client->code);
        self::assertContains('Hub shutting down: closing {count} connection(s).', $this->logged('info'));
    }

    #[RequiresFunction('posix_kill')]
    public function testSigintShutsDownGracefully(): void
    {
        $this->start();
        $client = $this->subscriber([self::A]);
        posix_kill((int) getmypid(), 2);
        $this->until(static fn(): bool => $client->closed);

        self::assertSame(1001, $client->code);
    }

    public function testStopsTheLoopAtOnceWithoutClients(): void
    {
        $server = $this->start($this->config(timings: self::timings(shutdownTimeout: 5.0)));
        $this->raw();
        $server->shutdown();
        $started = microtime(true);
        $this->loop->run();

        self::assertLessThan(0.5, microtime(true) - $started);
        self::assertSame(['count' => 0], $this->logs[1][2]);
    }

    public function testStopsTheLoopOnceTheLastClientClosed(): void
    {
        $server = $this->start($this->config(timings: self::timings(shutdownTimeout: 5.0)));
        $this->subscriber([self::A]);
        $this->subscriber([self::A], 2);
        $server->shutdown();
        $started = microtime(true);
        $this->loop->run();

        self::assertLessThan(0.5, microtime(true) - $started);
        self::assertSame(['count' => 2], $this->logs[1][2]);
        // Its deadline is gone: nothing keeps the loop running.
        $this->loop->run();
        self::assertLessThan(0.5, microtime(true) - $started);
    }

    public function testStopsTheLoopAfterTheShutdownTimeoutAtTheLatest(): void
    {
        $server = $this->start($this->config(
            self::limits(maxBuffer: 100_000_000),
            self::timings(shutdownTimeout: 0.2),
            'unix://' . $this->directory . '/browser.sock',
        ));
        // A client that stopped reading, its socket full: its close frame cannot be written.
        $client = $this->upgraded();
        $client->subscribe(1, $this->token([self::A]));
        $this->until(static fn(): bool => '' !== $client->received);
        $client->socket->pause();
        $data = json_encode(['x' => str_repeat('x', 8000)], \JSON_THROW_ON_ERROR);
        self::assertSame(204, $this->publish(array_fill(0, 100, self::notice([self::A], $data))));
        $server->shutdown();
        $started = microtime(true);
        $this->loop->run();

        self::assertGreaterThanOrEqual(0.19, microtime(true) - $started);
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function testWaitsForTheLastClientUntilTheDeadline(): void
    {
        $server = $this->start($this->config(
            self::limits(maxBuffer: 100_000_000),
            self::timings(shutdownTimeout: 0.3),
            'unix://' . $this->directory . '/browser.sock',
        ));
        $this->subscriber([self::A]);
        $client = $this->upgraded();
        $client->subscribe(1, $this->token([self::A], 2));
        $this->until(static fn(): bool => '' !== $client->received);
        $client->socket->pause();
        $data = json_encode(['x' => str_repeat('x', 8000)], \JSON_THROW_ON_ERROR);
        self::assertSame(204, $this->publish(array_fill(0, 100, self::notice([self::A], $data))));
        $server->shutdown();
        $started = microtime(true);
        $this->loop->run();

        self::assertGreaterThanOrEqual(0.29, microtime(true) - $started);
    }

    public function testShutsDownBeforeItStarted(): void
    {
        $server = ServerFactory::create(
            $this->loop,
            $this->config(),
            $this->tokens,
            $this->signer,
            $this->logger(),
            $this->clock,
        );
        $server->shutdown();
        $started = microtime(true);
        $this->loop->run();

        self::assertLessThan(0.5, microtime(true) - $started);
    }

    public function testClosesSocketsThatDoNotSendTheirHeadersInTime(): void
    {
        $this->start($this->config(timings: self::timings(headerTimeout: 5.0)));
        $silent = new RawClient($this->raw());
        $partial = new RawClient($this->raw());
        $partial->send("GET /hub HTTP/1.1\r\n");
        $publisher = new RawClient($this->raw($this->server?->publishAddress()));
        // A unix connect completes before the server accepts it: let it.
        $this->wait(0.02);
        $this->clock->sleep(4.9);
        $this->wait(0.03);
        self::assertFalse($silent->closed || $partial->closed || $publisher->closed);
        $this->clock->sleep(0.1);
        $this->until(static fn(): bool => $silent->closed && $partial->closed && $publisher->closed);

        $upgraded = $this->upgraded();
        $this->clock->sleep(5.0);
        $this->wait(0.03);
        self::assertFalse($upgraded->closed);
    }

    public function testShutdownClosesTheSocketsOfBothSides(): void
    {
        $server = $this->start();
        $silent = new RawClient($this->raw());
        $publisher = new RawClient($this->raw($server->publishAddress()));
        $this->wait(0.02);
        $server->shutdown();
        $this->until(static fn(): bool => $silent->closed && $publisher->closed);

        self::assertSame('', $silent->received . $silent->headers);
    }

    public function testLogsItsCountersPeriodically(): void
    {
        $this->start($this->config(timings: self::timings(statsInterval: 60.0)));
        $this->subscriber([self::A, self::PREFIX . 'b']);
        $this->client();
        self::assertSame(204, $this->publish([self::notice([self::A]), self::notice([self::PREFIX . 'c'])]));
        self::assertSame(204, $this->publish([self::notice([self::A], '{"n":2}')]));
        $this->clock->sleep(59.9);
        $this->wait(0.03);
        self::assertCount(1, $this->logs);
        $this->clock->sleep(0.1);
        $this->until(fn(): bool => 2 === \count($this->logs));
        $this->wait(0.03);

        self::assertSame(
            [
                'info',
                'Hub: {connections} connection(s), {pending} pending, {topics} topic(s); {published} notice(s) published, {delivered} frame(s) sent; {cursors} cursor frame(s) relayed, {invalid_cursors} invalid cursor event(s).',
                [
                    'connections' => 2,
                    'pending' => 1,
                    'topics' => 2,
                    'published' => 3,
                    'delivered' => 2,
                    'cursors' => 0,
                    'invalid_cursors' => 0,
                ],
            ],
            $this->logs[1],
        );
        self::assertCount(2, $this->logs);
    }

    public function testPerIpLimitsAreOffBehindASingleUntrustedPeer(): void
    {
        $this->start($this->config(self::limits(maxConnectionsPerIp: 1)));
        $this->client();
        $this->client();

        self::assertSame(
            [
                'max_connections_per_ip is off: every client connects through a local proxy. Name it in trusted_proxies to count its clients apart.',
            ],
            $this->logged('warning'),
        );
    }

    public function testRefusesSocketsPastMaxConnectionsAndMaxPending(): void
    {
        $this->start($this->config(self::limits(maxConnections: 1, maxPending: 1)));
        $connected = $this->client();
        $waiting = $this->raw();
        $this->wait(0.02);

        self::assertSame('', $this->handshake(self::upgrade()));
        $waiting->close();
        $this->wait(0.02);
        self::assertStringStartsWith('HTTP/1.1 503 ', $this->handshake(self::upgrade()));
        $connected->socket->close();
        $this->wait(0.02);
        self::assertStringStartsWith('HTTP/1.1 101 ', $this->handshake(self::upgrade()));
    }

    public function testRefusesSocketsOfAPeerPastItsLimit(): void
    {
        $this->start($this->config(self::limits(maxConnectionsPerIp: 2), trustedProxies: ['10.0.0.0/8']));
        $this->client();
        $waiting = $this->raw();
        $this->wait(0.02);

        self::assertSame('', $this->handshake(self::upgrade()));
        $waiting->close();
        $this->wait(0.02);
        self::assertStringStartsWith('HTTP/1.1 101 ', $this->handshake(self::upgrade()));
    }

    public function testRefusesPublishSocketsPastTheirLimit(): void
    {
        $server = $this->start($this->config(self::limits(maxPublishSockets: 1)));
        $open = $this->raw($server->publishAddress());
        $this->wait(0.02);

        self::assertSame('', $this->handshake(
            "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n",
            $server->publishAddress(),
        ));
        $open->close();
        $this->wait(0.02);
        self::assertSame('HTTP/1.1 200 OK', $this->handshake(
            "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n",
            $server->publishAddress(),
        ));
    }

    public function testFitsTheBrowserSocketsInWhatStreamSelectWatches(): void
    {
        $this->start($this->config(self::limits(maxConnections: 1, maxPending: 999, maxPublishSockets: 998)));
        $this->raw();
        $this->raw();
        $this->wait(0.02);

        self::assertSame('', $this->handshake(self::upgrade()));
        self::assertSame(
            [[
                'warning',
                'stream_select() watches {budget} browser sockets at most, under the {sockets} of max_connections and max_pending: install ext-ev or ext-event to hold more.',
                ['budget' => 2, 'sockets' => 1000],
            ]],
            array_values(array_filter($this->logs, static fn(array $log): bool => 'warning' === $log[0])),
        );
    }

    public function testOtherLoopsHoldEveryBrowserSocket(): void
    {
        $this->loop = new RecordingLoop();
        $this->start($this->config(self::limits(maxConnections: 1, maxPending: 999, maxPublishSockets: 998)));
        $this->raw();
        $this->raw();
        $this->wait(0.02);

        self::assertStringStartsWith('HTTP/1.1 101 ', $this->handshake(self::upgrade()));
        self::assertSame([], $this->logged('warning'));
    }

    public function testStreamSelectHoldsWhatFitsWithoutWarning(): void
    {
        $this->start($this->config(self::limits(maxConnections: 1, maxPending: 1, maxPublishSockets: 998)));

        self::assertSame([], $this->logged('warning'));
    }

    public function testKeepsAPublishSocketAliveBetweenRequestsUntilIdle(): void
    {
        $server = $this->start($this->config(timings: self::timings(headerTimeout: 5.0)));
        $client = new RawClient($this->raw($server->publishAddress()));
        $this->wait(0.02);
        $client->send($this->publishRequest('{"n":1}'));
        $this->until(static fn(): bool => str_contains($client->headers, "\r\n\r\n"));
        self::assertStringStartsWith('HTTP/1.1 204 ', $client->headers);

        $this->clock->sleep(6);
        $this->wait(0.03);
        self::assertFalse($client->closed);
        $client->headers = '';
        $client->send($this->publishRequest('{"n":2}'));
        $this->until(static fn(): bool => str_contains($client->headers, "\r\n\r\n"));
        self::assertStringStartsWith('HTTP/1.1 204 ', $client->headers);

        $this->clock->sleep(29.9);
        $this->wait(0.03);
        self::assertFalse($client->closed);
        $this->clock->sleep(0.1);
        $this->until(static fn(): bool => $client->closed);
    }

    public function testCountsPerIpOnAUnixListener(): void
    {
        $this->start($this->config(
            self::limits(maxConnectionsPerIp: 1),
            listen: 'unix://' . $this->directory . '/browser.sock',
        ));
        $this->client(['Origin' => self::ORIGIN, 'X-Forwarded-For' => '198.51.100.1']);
        $this->client(['Origin' => self::ORIGIN, 'X-Forwarded-For' => '198.51.100.2']);
        // A request naming no client is counted under the proxy: the limits still apply.
        $this->client();

        self::assertSame([], $this->logged('warning'));
        self::assertStringStartsWith('HTTP/1.1 429 ', $this->handshake(self::upgrade()));
        self::assertStringStartsWith(
            'HTTP/1.1 429 ',
            $this->handshake(self::upgrade(['Origin' => self::ORIGIN, 'X-Forwarded-For' => '198.51.100.1'])),
        );
    }

    public function testCountsPerIpOnAPublicAddress(): void
    {
        $this->start($this->config(self::limits(maxConnectionsPerIp: 1), listen: '0.0.0.0:0'));
        $this->client();

        self::assertSame([], $this->logged('warning'));
        // Its peer has its one connection: refused as soon as it connects.
        self::assertSame('', $this->handshake(self::upgrade(), str_replace(
            '0.0.0.0',
            '127.0.0.1',
            $this->server?->browserAddress() ?? '',
        )));
    }
}
