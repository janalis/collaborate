<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

use Janalis\Collaborate\Hub\Transport\WebSocket\RequestSigner;
use Janalis\Collaborate\Hub\Transport\WebSocket\WebSocketTransport;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ratchet\RFC6455\Messaging\Frame;
use React\Http\Message\Response;
use React\Socket\ConnectionInterface;

/**
 * The private side the application talks to, every request but `/health` signed ({@see RequestSigner}) and
 * accepted once:
 * - `POST /publish` `{"notices":[{"topics":[…],"data":"<json>"}]}`: 204, each notice sent as its `data`;
 * - `GET /subscriptions?topic=…`: `{"subscriptions":[{"active":true}]}` when a valid subscription listens to it,
 *   `{"subscriptions":[]}` otherwise (no count, no user);
 * - `GET /health`: a fixed body, for probes.
 */
final class PublishEndpoint
{
    /** Bytes of a request body read at most. */
    public const int MAX_BODY = 1_048_576;

    /** Notices published since the start. */
    public int $published = 0;

    /** Frames written for them. */
    public int $delivered = 0;

    /** @var array<string, float> nonces of the signatures accepted (`n=…`), when */
    private array $seen = [];

    public function __construct(
        private readonly RequestSigner $signer,
        private readonly Router $router,
        private readonly string $topicPrefix,
        private readonly ClockInterface $clock,
        private readonly Sockets $sockets,
        /** Sockets the application may keep open at once. */
        private readonly int $maxSockets,
    ) {}

    /** A socket the listener accepted: tracked, or closed at once when the application holds too many. */
    public function accept(ConnectionInterface $socket): void
    {
        if ($this->sockets->count() >= $this->maxSockets) {
            $socket->close();

            return;
        }
        $this->sockets->accept($socket);
    }

    /**
     * Middleware ahead of the body buffer, which would hand an oversized body over empty: refuses it, and a body
     * of unknown length.
     */
    public function limit(ServerRequestInterface $request, callable $next): mixed
    {
        // Its headers arrived: a keep-alive socket is reaped when idle, no longer by the first headers' deadline.
        $this->sockets->claim();
        $status = match (true) {
            $request->hasHeader('Transfer-Encoding') => 411,
            ($request->getBody()->getSize() ?? 0) > self::MAX_BODY => 413,
            default => null,
        };

        return null === $status ? $next($request) : new Response($status, ['Connection' => 'close']);
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $route = $request->getMethod() . ' ' . $request->getUri()->getPath();
        if ('GET /health' === $route) {
            return new Response(200, ['Content-Type' => 'text/plain'], "ok\n");
        }
        if ('POST /publish' !== $route && 'GET /subscriptions' !== $route) {
            return new Response(404);
        }
        $now = Seconds::of($this->clock);
        $body = (string) $request->getBody();
        $signature = $request->getHeaderLine(RequestSigner::HEADER);
        if (!$this->signer->verify(
            $signature,
            $request->getMethod(),
            $request->getRequestTarget(),
            $body,
            (int) $now,
        )) {
            return new Response(401);
        }
        // Verified, the header is `t=…,n=…,s=…`: a nonce accepted once is a replay, however the rest is spelled.
        $nonce = explode(',', $signature)[1] ?? '';
        if (isset($this->seen[$nonce])) {
            return new Response(401);
        }
        $this->seen[$nonce] = $now;

        return 'GET /subscriptions' === $route ? $this->subscriptions($request, $now) : $this->publish($body, $now);
    }

    /**
     * Forgets the nonces of signatures too old to verify again. A signature verifies while the whole seconds of
     * the clock are within {@see RequestSigner::WINDOW} of its time, so one first seen at the start of its window
     * stays valid until 2 × WINDOW + 1 seconds later: its nonce is kept that long.
     */
    public function prune(float $now): void
    {
        foreach ($this->seen as $nonce => $time) {
            if ($time >= ($now - (2 * RequestSigner::WINDOW) - 1)) {
                continue;
            }
            unset($this->seen[$nonce]);
        }
    }

    private function publish(string $body, float $now): ResponseInterface
    {
        $notices = $this->notices($body);
        if (null === $notices) {
            return new Response(400);
        }
        foreach ($notices as [$topics, $data]) {
            $this->delivered += $this->router->dispatch($topics, new Frame($data)->getContents(), $now);
        }
        $this->published += \count($notices);

        return new Response(204);
    }

    private function subscriptions(ServerRequestInterface $request, float $now): ResponseInterface
    {
        // @mago-expect analysis:mixed-assignment a query parameter is mixed: the next line narrows it
        $topic = $request->getQueryParams()['topic'] ?? null;
        if (!\is_string($topic)) {
            return new Response(400);
        }

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->router->listening($topic, $now) ? '{"subscriptions":[{"active":true}]}' : '{"subscriptions":[]}',
        );
    }

    /** @return list<array{list<string>, string}>|null every notice of a body, or null if any is invalid */
    private function notices(string $body): ?array
    {
        try {
            // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next lines narrow it
            $notices = json_decode($body, true, 5, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next lines narrow it
        $notices = \is_array($notices) ? $notices['notices'] ?? null : null;
        if (
            !\is_array($notices)
            || [] === $notices
            || !array_is_list($notices)
            || \count($notices) > WebSocketTransport::MAX_NOTICES
        ) {
            return null;
        }
        $checked = [];
        // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next lines narrow it
        foreach ($notices as $notice) {
            // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next lines narrow it
            $topics = \is_array($notice) ? $notice['topics'] ?? null : null;
            // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next lines narrow it
            $data = \is_array($notice) ? $notice['data'] ?? null : null;
            if (
                !\is_array($topics)
                || [] === $topics
                || !array_is_list($topics)
                || !\is_string($data)
                || !self::data($data)
            ) {
                return null;
            }
            // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next line narrows it
            foreach ($topics as $topic) {
                if (!\is_string($topic) || !str_starts_with($topic, $this->topicPrefix)) {
                    return null;
                }
            }
            /** @var list<string> $topics */
            $checked[] = [$topics, $data];
        }

        return $checked;
    }

    /** Whether a notice's data is JSON that cannot pass for a control message (no top-level `hub` key). */
    private static function data(string $data): bool
    {
        try {
            // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next line narrows it
            $value = json_decode($data, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return !\is_array($value) || !\array_key_exists('hub', $value);
    }
}
