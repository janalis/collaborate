<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Transport\WebSocket;

use Janalis\Collaborate\Hub\HubSession;
use Janalis\Collaborate\Hub\Transport\HubTransportInterface;
use Janalis\Collaborate\Hub\Transport\PresenceProbeInterface;
use Janalis\Collaborate\Hub\Transport\PublishFailedException;
use Janalis\Collaborate\Hub\Transport\Subscription;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The bundle's WebSocket server (`collaborate:hub:serve`): pages subscribe with a {@see SubscriberToken}; the
 * application publishes over the server's private side, every request signed ({@see RequestSigner}), the
 * notices of one request in one call.
 */
final readonly class WebSocketTransport implements HubTransportInterface, PresenceProbeInterface
{
    /** Notices one publish request carries at most: the server refuses more. */
    public const int MAX_NOTICES = 100;

    private string $publishUrl;

    public function __construct(
        private HttpClientInterface $client,
        private RequestSigner $signer,
        private SubscriberToken $tokens,
        private string $publicUrl,
        string $publishUrl,
        private ?string $publishSocket = null,
        private ?LoggerInterface $logger = null,
        private ClockInterface $clock = new NativeClock(),
        /** Whether pages send their cursor events over their socket (`hub.websocket.cursor_events`). */
        private bool $cursorEvents = true,
    ) {
        self::checkPublicUrl($publicUrl);
        $parts = parse_url($publishUrl);
        if (
            !\is_array($parts)
            || !\in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !isset($parts['host'])
            || !\in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['query'])
        ) {
            throw new \InvalidArgumentException(
                'collaborate.hub.websocket.publish_url must be the http(s) root of the server\'s private side, with no path.',
            );
        }
        $this->publishUrl = rtrim($publishUrl, '/');
    }

    /**
     * Browsers connect over TLS unless the server is local: a relative URL follows the page's own scheme.
     *
     * @throws \InvalidArgumentException
     */
    public static function checkPublicUrl(string $url): void
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return;
        }
        $parts = parse_url($url);
        $host = \is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        $scheme = \is_array($parts) ? $parts['scheme'] ?? null : null;
        $local = \in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.localhost');
        if ('' === $host || !('wss' === $scheme || 'ws' === $scheme && $local)) {
            throw new \InvalidArgumentException(
                'collaborate.hub.websocket.public_url must be a path on the application\'s origin or a wss:// URL (ws:// on localhost only).',
            );
        }
    }

    public function publish(array $notices): void
    {
        foreach (array_chunk($notices, self::MAX_NOTICES) as $chunk => $batch) {
            // A notice encodes as its public properties: {"topics":[…],"data":"…"}.
            $body = json_encode(['notices' => $batch], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
            try {
                $status = $this->request('POST', '/publish', $body, 1.0)->getStatusCode();
            } catch (\Throwable $error) {
                throw new PublishFailedException($chunk * self::MAX_NOTICES, $error);
            }
            if (204 !== $status) {
                throw new PublishFailedException(
                    $chunk * self::MAX_NOTICES,
                    new \RuntimeException(\sprintf('The hub answered %d to a publish.', $status)),
                );
            }
        }
    }

    public function subscribe(HubSession $session, Request $request): Subscription
    {
        $token = $this->tokens->sign(
            $session->userId,
            $request->getSchemeAndHttpHost(),
            $session->topics,
            $this->clock->now()->getTimestamp(),
            $this->cursorEvents ? $session->cursorRoom : null,
            $this->cursorEvents ? $session->cursor : null,
        );

        return new Subscription(
            Subscription::WEBSOCKET,
            $this->publicUrl,
            $token,
            $this->cursorEvents && SubscriberToken::claimsCursor($token),
        );
    }

    public function listening(string $topic): bool
    {
        try {
            $data = $this->request('GET', '/subscriptions?topic=' . rawurlencode($topic), '', 2.0)->toArray();
        } catch (\Throwable $error) {
            $this->logger?->info('The hub did not say who listens to a topic.', ['exception' => $error]);
            $data = [];
        }

        return \is_array($data['subscriptions'] ?? null) && [] !== $data['subscriptions'];
    }

    private function request(
        string $method,
        string $target,
        string $body,
        float $timeout,
    ): \Symfony\Contracts\HttpClient\ResponseInterface {
        $options = [
            'headers' =>
                [
                    RequestSigner::HEADER => $this->signer->sign(
                        $method,
                        $target,
                        $body,
                        $this->clock->now()->getTimestamp(),
                    ),
                ] + ('' === $body ? [] : ['Content-Type' => 'application/json']),
            'body' => $body,
            'timeout' => $timeout,
            'max_duration' => $timeout,
            // The signature is for this server alone: a redirect would carry it elsewhere.
            'max_redirects' => 0,
        ];
        if (null !== $this->publishSocket) {
            // CurlHttpClient reads a socket file as CURLOPT_UNIX_SOCKET_PATH.
            $options['bindto'] = $this->publishSocket;
        }

        return $this->client->request($method, $this->publishUrl . $target, $options);
    }
}
