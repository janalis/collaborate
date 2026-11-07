<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Host\Push;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The `test` environment's HTTP client answers: the subscriptions API of the hub, saying a topic has a
 * subscriber when a test put it {@see online()}, and failing when a test put it {@see down()}.
 */
final class HubSubscriptions
{
    /** @var list<string> */
    public static array $online = [];

    public static bool $down = false;

    /** @var list<array{url: string, authorization: string}> */
    public static array $asked = [];

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $header = '';
        if (
            \is_array($options['normalized_headers'] ?? null)
            && \is_array($options['normalized_headers']['authorization'] ?? null)
        ) {
            $header = \is_string($options['normalized_headers']['authorization'][0] ?? null)
                ? $options['normalized_headers']['authorization'][0]
                : '';
        }
        self::$asked[] = [
            'url' => $url,
            'authorization' => (string) preg_replace('/^Authorization:\s*/i', '', $header),
        ];
        if (self::$down) {
            return new MockResponse('', ['http_code' => 502]);
        }
        $topic = rawurldecode(substr($url, (int) strrpos($url, '/') + 1));
        $subscriptions = \in_array($topic, self::$online, true)
            ? [['id' => '/.well-known/mercure/subscriptions/x/y', 'topic' => $topic, 'active' => true]]
            : [];

        return new MockResponse(json_encode(['subscriptions' => $subscriptions], \JSON_THROW_ON_ERROR));
    }

    public static function reset(): void
    {
        self::$online = [];
        self::$down = false;
        self::$asked = [];
    }
}
