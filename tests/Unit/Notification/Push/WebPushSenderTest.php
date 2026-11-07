<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification\Push;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Janalis\Collaborate\Notification\Entity\PushSubscription;
use Janalis\Collaborate\Notification\Push\PushResult;
use Janalis\Collaborate\Notification\Push\Vapid;
use Janalis\Collaborate\Notification\Push\WebPushSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(WebPushSender::class)]
#[UsesClass(PushSubscription::class)]
#[UsesClass(Vapid::class)]
final class WebPushSenderTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var list<array<array-key, mixed>> */
    private array $options = [];

    public function testSignsAndEncryptsAPushToItsEndpoint(): void
    {
        $sender = $this->sender([new Response(201)]);
        self::assertSame(PushResult::Sent, $sender->send(self::subscription(), '{"id":1}'));
        $request = $this->requests[0];
        self::assertSame('https://fcm.googleapis.com/fcm/send/1', (string) $request->getUri());
        self::assertSame('600', $request->getHeaderLine('TTL'));
        self::assertSame('high', $request->getHeaderLine('Urgency'));
        self::assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        self::assertStringStartsWith('vapid t=', $request->getHeaderLine('Authorization'));
        self::assertNotSame('{"id":1}', (string) $request->getBody());
        self::assertSame(10, $this->options[0]['timeout'] ?? null);
        // A push service answers; a redirect would send the push, and the server, elsewhere.
        self::assertFalse($this->options[0]['allow_redirects'] ?? null);
    }

    public function testAPushLivesADayUnlessConfiguredOtherwise(): void
    {
        $keys = Vapid::generate();
        $sender = new WebPushSender(
            $keys['publicKey'],
            $keys['privateKey'],
            'mailto:ops@example.org',
            $this->createStub(LoggerInterface::class),
            clientOptions: ['handler' => $this->stack([new Response(201)])],
        );
        self::assertSame(PushResult::Sent, $sender->send(self::subscription(), null));
        self::assertSame('86400', $this->requests[0]->getHeaderLine('TTL'));
        self::assertSame('normal', $this->requests[0]->getHeaderLine('Urgency'));
    }

    public function testAnEmptyPushCarriesNoBody(): void
    {
        $sender = $this->sender([new Response(201)]);
        self::assertSame(PushResult::Sent, $sender->send(self::subscription(), null));
        self::assertSame('', (string) $this->requests[0]->getBody());
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
        $sender = $this->sender([new Response(410), new Response(404), new Response(500)], $logger);
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
        $sender = $this->sender([new Response(201), new Response(201)]);
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

    /** @param list<Response> $responses */
    private function sender(array $responses, ?LoggerInterface $logger = null): WebPushSender
    {
        $stack = $this->stack($responses);
        $keys = Vapid::generate();

        return new WebPushSender(
            $keys['publicKey'],
            $keys['privateKey'],
            'mailto:ops@example.org',
            $logger ?? $this->createStub(LoggerInterface::class),
            600,
            'high',
            ['handler' => $stack],
        );
    }

    /** @param list<Response> $responses */
    private function stack(array $responses): HandlerStack
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(fn(callable $handler): callable => function (RequestInterface $request, array $options) use (
            $handler,
        ): mixed {
            $this->requests[] = $request;
            $this->options[] = $options;

            return $handler($request, $options);
        });

        return $stack;
    }
}
