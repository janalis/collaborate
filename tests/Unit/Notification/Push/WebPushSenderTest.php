<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification\Push;

use Janalis\Collaborate\Notification\Entity\PushSubscription;
use Janalis\Collaborate\Notification\Push\PushResult;
use Janalis\Collaborate\Notification\Push\Vapid;
use Janalis\Collaborate\Notification\Push\WebPushSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(WebPushSender::class)]
#[UsesClass(PushSubscription::class)]
#[UsesClass(Vapid::class)]
final class WebPushSenderTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<array-key, mixed>}> */
    private array $requests = [];

    public function testSignsAndEncryptsAPushToItsEndpoint(): void
    {
        $sender = $this->sender([201]);
        self::assertSame(PushResult::Sent, $sender->send(self::subscription(), '{"id":1}'));
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://fcm.googleapis.com/fcm/send/1', $request['url']);
        self::assertSame('600', $this->header(0, 'TTL'));
        self::assertSame('high', $this->header(0, 'Urgency'));
        self::assertSame('aes128gcm', $this->header(0, 'Content-Encoding'));
        self::assertStringStartsWith('vapid t=', $this->header(0, 'Authorization'));
        self::assertNotSame('', $this->body(0));
        self::assertNotSame('{"id":1}', $this->body(0));
        self::assertSame(10.0, $request['options']['timeout'] ?? null);
        // A push service answers; a redirect would send the push, and the server, elsewhere.
        self::assertSame(0, $request['options']['max_redirects'] ?? null);
    }

    public function testAPushLivesADayUnlessConfiguredOtherwise(): void
    {
        $keys = Vapid::generate();
        $sender = new WebPushSender(
            $keys['publicKey'],
            $keys['privateKey'],
            'mailto:ops@example.org',
            $this->createStub(LoggerInterface::class),
            http: $this->http([201]),
        );
        self::assertSame(PushResult::Sent, $sender->send(self::subscription(), null));
        self::assertSame('86400', $this->header(0, 'TTL'));
        self::assertSame('normal', $this->header(0, 'Urgency'));
    }

    public function testAnEmptyPushCarriesNoBody(): void
    {
        $sender = $this->sender([201]);
        self::assertSame(PushResult::Sent, $sender->send(self::subscription(), null));
        self::assertSame('', $this->body(0));
    }

    public function testAGoneBrowserIsExpiredAndARefusalLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('warning')
            ->with('A push service refused a notification.', self::callback(static fn(array $context): bool => \is_string(
                $context['reason'] ?? null,
            )));
        $sender = $this->sender([410, 404, 500], $logger);
        self::assertSame(PushResult::Expired, $sender->send(self::subscription(), null));
        self::assertSame(PushResult::Expired, $sender->send(self::subscription(), null));
        self::assertSame(PushResult::Failed, $sender->send(self::subscription(), null));
    }

    public function testAKeyTheLibraryCannotUseFailsThePushAlone(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Collaborate could not push a notification.',
                self::callback(
                    static fn(array $context): bool => ($context['exception'] ?? null) instanceof \Throwable,
                ),
            );
        $sender = new WebPushSender('nope', 'nope', 'mailto:a@b.c', $logger);
        self::assertSame(PushResult::Failed, $sender->send(self::subscription(), null));
    }

    public function testItsClientIsMadeAgainAfterAReset(): void
    {
        $sender = $this->sender([201, 201]);
        $sender->send(self::subscription(), null);
        $client = new \ReflectionProperty(WebPushSender::class, 'client');
        // @mago-expect analysis:mixed-assignment a property read by reflection is mixed
        $first = $client->getValue($sender);
        self::assertNotNull($first);
        $sender->send(self::subscription(), null);
        self::assertSame($first, $client->getValue($sender));
        $sender->reset();
        self::assertNull($client->getValue($sender));
    }

    private static function subscription(): PushSubscription
    {
        // The keys of a browser: a P-256 point and 16 random bytes.
        return new PushSubscription(
            1,
            'https://fcm.googleapis.com/fcm/send/1',
            Vapid::generate()['publicKey'],
            Vapid::encode(random_bytes(16)),
            'aes128gcm',
            new \DateTimeImmutable(),
        );
    }

    /** @param list<int> $statuses */
    private function sender(array $statuses, ?LoggerInterface $logger = null): WebPushSender
    {
        $keys = Vapid::generate();

        return new WebPushSender(
            $keys['publicKey'],
            $keys['privateKey'],
            'mailto:ops@example.org',
            $logger ?? $this->createStub(LoggerInterface::class),
            600,
            'high',
            $this->http($statuses),
        );
    }

    /** @param list<int> $statuses */
    private function http(array $statuses): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (
            &$statuses,
        ): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => array_shift($statuses) ?? 500]);
        });
    }

    private function header(int $request, string $name): string
    {
        $headers = $this->requests[$request]['options']['normalized_headers'] ?? [];
        self::assertIsArray($headers);
        $lines = $headers[strtolower($name)] ?? [];
        self::assertIsArray($lines);
        $line = $lines[0] ?? '';
        self::assertIsString($line);

        return substr($line, \strlen($name) + 2);
    }

    private function body(int $request): string
    {
        // @mago-expect analysis:mixed-assignment the options of a request are mixed
        $body = $this->requests[$request]['options']['body'] ?? '';
        if (!$body instanceof \Closure) {
            self::assertIsString($body);

            return $body;
        }
        // The client reads a body it was given as a stream in chunks, an empty one ending at once.
        $read = '';
        while ('' !== ($chunk = $body(16_384))) {
            self::assertIsString($chunk);
            $read .= $chunk;
        }

        return $read;
    }
}
