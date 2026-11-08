<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

use GuzzleHttp\Psr7\HttpFactory;
use Janalis\Collaborate\Hub\Transport\WebSocket\Origin;
use Janalis\Collaborate\Hub\Transport\WebSocket\SubscriberToken;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\Http\Message\Response;
use React\Socket\ConnectionInterface;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The browser side: the upgrade, checked before it is negotiated, and the subscribe protocol.
 *
 * A browser sends `{"hub":"subscribe","id":n,"token":"…"}` and nothing else; the server answers
 * `{"hub":"subscribed","id":n}` or `{"hub":"error","id":n}`, then sends the notices of its topics, each the
 * published JSON as is. A connection that did not subscribe within the deadline, whose token expired or whose
 * first subscribe failed (after its error reply) is closed with 4401: get a new session. One that breaks the
 * protocol, or whose user has too many connections, is closed with 1008. Nothing a browser sends is echoed back.
 */
final class BrowserEndpoint
{
    public const int CLOSE_SESSION = 4401;

    private readonly ServerNegotiator $negotiator;

    private readonly string $ping;

    /** @var array<int, Connection> */
    private array $connections = [];

    private int $pending = 0;

    /** Cursor events relayed, counted once per connection reached, since the start. */
    public int $relayedCursors = 0;

    /** Cursor events dropped for being invalid, since the start. */
    public int $invalidCursors = 0;

    private readonly Counter $ips;

    private readonly Counter $users;

    private float $nextPing;

    /** Called once the last connection closed after {@see shutdown()}. */
    private ?\Closure $onEmpty = null;

    public function __construct(
        private readonly ServerConfig $config,
        private readonly SubscriberToken $tokens,
        private readonly Router $router,
        private readonly ClientAddress $addresses,
        private readonly Sockets $sockets,
        private readonly ClockInterface $clock,
        /** Whether connections are counted per client IP. */
        private readonly bool $perIp,
        /** Sockets this side holds at most, connections and sockets still sending their request. */
        private readonly int $maxSockets,
        /** Cursor events over sockets; null when off. */
        private readonly ?Cursors $cursors = null,
    ) {
        $this->ips = new Counter();
        $this->users = new Counter();
        $this->negotiator = new ServerNegotiator(new RequestVerifier(), new HttpFactory());
        $this->ping = new Frame('', true, Frame::OP_PING)->getContents();
        $this->nextPing = $this->now() + $config->timings->pingInterval;
    }

    /**
     * A socket the listener accepted: tracked until its request is read, or closed at once when this side holds
     * too many sockets, or its peer too many when it counts per IP.
     */
    public function accept(ConnectionInterface $socket): void
    {
        $peer = $this->perIp ? $this->addresses->peer($socket->getRemoteAddress()) : null;
        if (
            (\count($this->connections) + $this->sockets->count()) >= $this->maxSockets
            || null !== $peer
            && ($this->ips->count($peer) + $this->sockets->waiting($peer)) >= $this->config->limits->maxConnectionsPerIp
        ) {
            $socket->close();

            return;
        }
        $this->sockets->accept($socket, $peer);
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $socket = $this->sockets->claim();
        $limits = $this->config->limits;
        $origin = Origin::normalize($request->getHeaderLine('Origin')) ?? '';
        $ip = $this->perIp ? $this->addresses->resolve($request) : null;
        $status = match (true) {
            $request->getUri()->getPath() !== $this->config->path => 404,
            $request->hasHeader('Transfer-Encoding') || '' !== $request->getHeaderLine('Content-Length') => 400,
            '' === $origin,
            [] !== $this->config->allowedOrigins && !\in_array($origin, $this->config->allowedOrigins, true),
                => 403,
            \count($this->connections) >= $limits->maxConnections, $this->pending >= $limits->maxPending => 503,
            null !== $ip && $this->ips->count($ip) >= $limits->maxConnectionsPerIp => 429,
            default => 101,
        };
        $negotiated = 101 === $status ? $this->negotiator->handshake($request) : null;
        if (null === $negotiated || 101 !== $negotiated->getStatusCode()) {
            return new Response($negotiated?->getStatusCode() ?? $status, ['Connection' => 'close']);
        }
        $this->sockets->release($socket);
        $out = new ThroughStream();
        $in = new ThroughStream();
        $connection = new Connection(
            $socket,
            $out,
            $origin,
            $ip,
            $this->now(),
            $limits->maxBuffer,
            $limits->maxMessageSize,
            $this->message(...),
            $this->control(...),
        );
        $this->connections[$connection->id] = $connection;
        ++$this->pending;
        if (null !== $ip) {
            $this->ips->add($ip);
        }
        $in->on('data', function (string $data) use ($connection): void {
            $connection->receive($data, $this->now());
        });
        $out->on('drain', $connection->drained(...));
        $socket->on('close', function () use ($connection): void {
            $this->forget($connection);
        });

        /** @var array<string, list<string>> $headers header names are strings */
        $headers = $negotiated->withoutHeader('X-Powered-By')->getHeaders();

        return new Response(101, $headers, new CompositeStream($out, $in));
    }

    /** The shared timer: deadlines, expired tokens, congestion, silence, pings. */
    public function tick(float $now): void
    {
        $timings = $this->config->timings;
        $ping = $now >= $this->nextPing;
        if ($ping) {
            $this->nextPing = $now + $timings->pingInterval;
        }
        foreach ($this->connections as $connection) {
            // A closing connection stays until its socket closed: one that does not read its close frame is
            // dropped, whatever else it did.
            if (null !== $connection->closingSince) {
                if (($now - $connection->closingSince) >= $timings->closeTimeout) {
                    $connection->drop();
                }

                continue;
            }
            if (
                $connection->subscribed
                    ? $connection->exp <= $now
                    : ($now - $connection->opened) >= $timings->subscribeTimeout
            ) {
                $connection->close(self::CLOSE_SESSION, $now);
            } elseif (
                null !== $connection->congestedSince
                && ($now - $connection->congestedSince) >= $timings->congestionTimeout
                || ($now - $connection->lastSeen) >= (2 * $timings->pingInterval)
            ) {
                $connection->drop();
            } elseif ($ping) {
                $connection->send($this->ping, $now);
            }
        }
    }

    /** Closes every connection with 1001 (going away); `$onEmpty` runs once none is left. */
    public function shutdown(\Closure $onEmpty): void
    {
        $this->onEmpty = $onEmpty;
        $now = $this->now();
        foreach ($this->connections as $connection) {
            $connection->close(Frame::CLOSE_GOING_AWAY, $now);
        }
        $this->empty();
    }

    public function count(): int
    {
        return \count($this->connections);
    }

    public function pending(): int
    {
        return $this->pending;
    }

    /**
     * A message read after the connection started closing (in the same data as the violation that closed it) is
     * answered by nothing: a closing connection sends nothing and leaves the router once its socket closed.
     */
    private function message(Connection $connection, MessageInterface $message): void
    {
        $now = $this->now();
        $message = $message->isBinary() ? null : self::decode($message->getPayload());
        $event = self::cursorEvent($message);
        if (null !== $event) {
            $this->cursor($connection, $event, $now);

            return;
        }
        $subscribe = self::subscription($message);
        if (null === $subscribe) {
            $connection->close(Frame::CLOSE_POLICY, $now);

            return;
        }
        $this->subscribe($connection, $subscribe[0], $subscribe[1], $now);
    }

    /**
     * Relays a cursor event to the connection's room, stamped with who its token says it is from: dropped when
     * cursors are off, its token grants no room or expired, it comes too fast, is too large or is invalid.
     *
     * @param array<array-key, mixed> $event
     */
    private function cursor(Connection $connection, array $event, float $now): void
    {
        $claims = $connection->claims;
        $cursors = $this->cursors;
        if (
            null === $cursors
            || null === $claims?->cursor
            || $connection->exp <= $now
            || !$connection->allowCursor($now, $cursors->rate())
            || \strlen(json_encode($event, \JSON_THROW_ON_ERROR)) > $cursors->maxEventBytes
        ) {
            return;
        }
        try {
            $notice = $cursors->events->notice($event, $claims->cursor, (int) ($now * 1000));
        } catch (BadRequestHttpException) {
            ++$this->invalidCursors;

            return;
        }
        // @mago-expect analysis:possibly-invalid-argument a token names its cursor room with its cursor identity
        $this->relayedCursors += $this->router->dispatch(
            [$claims->cursorRoom],
            new Frame($notice)->getContents(),
            $now,
        );
    }

    private function subscribe(Connection $connection, int $id, #[\SensitiveParameter] string $token, float $now): void
    {
        $limits = $this->config->limits;
        $claims = $connection->allowSubscribe($now, $limits->maxSubscribes, $this->config->timings->subscribeWindow)
            ? $this->tokens->verify($token, (int) $now)
            : null;
        $crowded =
            null !== $claims
            && !$connection->subscribed
            && $this->users->count($claims->sub) >= $limits->maxConnectionsPerUser;
        if (
            null === $claims
            || $crowded
            || $claims->origin !== $connection->origin
            || $connection->subscribed && $claims->sub !== $connection->user
        ) {
            // A failed renewal keeps the subscription; a failed first subscribe ends the connection: 1008 (back
            // off) when the user has too many, 4401 (get a new session) otherwise. The reply carries that code: the
            // page settles on it before the close arrives.
            $code = $crowded ? Frame::CLOSE_POLICY : self::CLOSE_SESSION;
            $connection->send(self::reply('error', $id, $code), $now);
            if (!$connection->subscribed) {
                $connection->close($code, $now);
            }

            return;
        }
        if (!$connection->subscribed) {
            $connection->subscribed = true;
            $connection->user = $claims->sub;
            $this->users->add($claims->sub);
            --$this->pending;
        }
        $this->router->subscribe($connection, $claims);
        $connection->send(self::reply('subscribed', $id), $now);
    }

    private function control(Connection $connection, FrameInterface $frame): void
    {
        $now = $this->now();
        match ($frame->getOpcode()) {
            Frame::OP_PING => $connection->send(
                new Frame($frame->getPayload(), true, Frame::OP_PONG)->getContents(),
                $now,
            ),
            Frame::OP_CLOSE => $connection->close(self::code($frame->getPayload()), $now),
            default => null,
        };
    }

    private function forget(Connection $connection): void
    {
        unset($this->connections[$connection->id]);
        $this->router->remove($connection);
        if ($connection->subscribed) {
            $this->users->remove($connection->user);
        } else {
            --$this->pending;
        }
        if (null !== $connection->ip) {
            $this->ips->remove($connection->ip);
        }
        $this->empty();
    }

    private function empty(): void
    {
        if (null !== $this->onEmpty && [] === $this->connections) {
            ($this->onEmpty)();
            $this->onEmpty = null;
        }
    }

    /** @return array<array-key, mixed>|null a message, if it is a JSON object or list */
    private static function decode(string $payload): ?array
    {
        try {
            // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next line narrows it
            $message = json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($message) ? $message : null;
    }

    /**
     * The event of a cursor message: `{"hub":"cursor","event":{…}}`.
     *
     * @param array<array-key, mixed>|null $message
     *
     * @return array<array-key, mixed>|null
     */
    private static function cursorEvent(?array $message): ?array
    {
        if (null === $message || 2 !== \count($message) || 'cursor' !== ($message['hub'] ?? null)) {
            return null;
        }
        // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next line narrows it
        $event = $message['event'] ?? null;

        // A JSON object, `{}` included; anything else is no cursor message.
        return \is_array($event) && ([] === $event || !array_is_list($event)) ? $event : null;
    }

    /**
     * @param array<array-key, mixed>|null $message
     *
     * @return array{int, string}|null
     */
    private static function subscription(?array $message): ?array
    {
        if (
            null === $message
            || 3 !== \count($message)
            || 'subscribe' !== ($message['hub'] ?? null)
            || !\is_int($message['id'] ?? null)
            || !\is_string($message['token'] ?? null)
        ) {
            return null;
        }

        return [$message['id'], $message['token']];
    }

    /** The status code of a close frame: the client's, echoed, or that of the violation it committed. */
    private static function code(string $payload): int
    {
        return (\ord($payload[0]) << 8) | \ord($payload[1]);
    }

    private static function reply(string $hub, int $id, ?int $code = null): string
    {
        $reply = null === $code ? ['hub' => $hub, 'id' => $id] : ['hub' => $hub, 'id' => $id, 'code' => $code];

        return new Frame(json_encode($reply, \JSON_THROW_ON_ERROR))->getContents();
    }

    private function now(): float
    {
        return Seconds::of($this->clock);
    }
}
