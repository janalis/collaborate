<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification\Push;

use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Notification\Push\OnlineRecipients;
use Janalis\Collaborate\Notification\Push\PushDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

#[CoversClass(OnlineRecipients::class)]
#[CoversClass(PushDispatcher::class)]
#[UsesClass(Topics::class)]
final class OnlineRecipientsTest extends TestCase
{
    public function testNobodyIsOnlineWithoutTheApiAClientOrATokenFactory(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('getFactory')->willReturn($this->createStub(TokenFactoryInterface::class));
        $hub->method('getPublicUrl')->willReturn('https://hub.test/.well-known/mercure');
        // A failure thrown in the client would be caught as a hub that fails: what is asked is counted.
        $asked = new \ArrayObject();
        $client = new MockHttpClient(static function () use ($asked): MockResponse {
            $asked->append(true);

            return new MockResponse('{"subscriptions":["x"]}');
        });
        // Nothing is asked of the hub, so nothing fails.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        self::assertFalse(new OnlineRecipients($hub, new Topics(), $logger, $client, false)->isOnline(1));
        self::assertFalse(new OnlineRecipients($hub, new Topics(), $logger)->isOnline(1));
        $bare = $this->createStub(HubInterface::class);
        $bare->method('getFactory')->willReturn(null);
        self::assertFalse(new OnlineRecipients($bare, new Topics(), $logger, $client, true)->isOnline(1));
        self::assertCount(0, $asked);
        self::assertTrue(new OnlineRecipients($hub, new Topics(), $logger, $client, true)->isOnline(1));
        // Off unless asked: the service worker decides.
        self::assertFalse(new OnlineRecipients($hub, new Topics(), $logger, $client)->isOnline(1));
        self::assertCount(1, $asked);
    }

    public function testAsksThePublicHubAndCountsActiveSubscriptionsAlone(): void
    {
        $factory = $this->createMock(TokenFactoryInterface::class);
        $factory
            ->expects($this->exactly(6))
            ->method('create')
            ->with(self::callback(
                static fn(array $grants): bool => (
                    1 === \count($grants)
                    && ($grants[0] ?? null) instanceof Grant
                    && [Grant::ACTION_SUBSCRIBE] === $grants[0]->actions
                    && [
                        'https://hub.test/.well-known/mercure/subscriptions/urn%3At%3Anotifications%3A5',
                        '/.well-known/mercure/subscriptions{/topic}{/subscriber}',
                    ] === $grants[0]->topics
                ),
            ))
            ->willReturn('jwt');
        $hub = $this->createStub(HubInterface::class);
        $hub->method('getFactory')->willReturn($factory);
        $hub->method('getPublicUrl')->willReturn('https://hub.test/.well-known/mercure/');
        $answers = [
            '{"subscriptions":[{"active":false},{"active":true}]}',
            '{"subscriptions":[{"active":false}]}',
            '{"subscriptions":[]}',
            '{"subscriptions":["x"]}',
            '{}',
            '{"subscriptions":[{}]}',
        ];
        $asked = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$answers,
            &$asked,
        ): MockResponse {
            $asked[] = [$method, $url, $options['headers'] ?? [], $options['timeout'] ?? null];

            return new MockResponse((string) array_shift($answers));
        });
        $online = new OnlineRecipients(
            $hub,
            new Topics('urn:t:'),
            $this->createStub(LoggerInterface::class),
            $client,
            true,
        );
        self::assertTrue($online->isOnline(5));
        self::assertSame('GET', $asked[0][0]);
        self::assertSame(
            'https://hub.test/.well-known/mercure/subscriptions/urn%3At%3Anotifications%3A5',
            $asked[0][1],
        );
        self::assertIsArray($asked[0][2]);
        self::assertContains('Authorization: Bearer jwt', $asked[0][2]);
        // A hub slow to answer is one that does not: the push is sent.
        self::assertSame(2.0, $asked[0][3]);
        self::assertFalse($online->isOnline(5));
        self::assertFalse($online->isOnline(5));
        self::assertTrue($online->isOnline(5));
        self::assertFalse($online->isOnline(5));
        // A subscription that does not say whether it is active is.
        self::assertTrue($online->isOnline(5));
    }

    public function testAHubThatFailsReadsAsNobodyOnlineAndIsLogged(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('getFactory')->willReturn($this->createStub(TokenFactoryInterface::class));
        $hub->method('getPublicUrl')->willReturn('https://hub.test/.well-known/mercure');
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('info')
            ->with(
                'The hub did not say who listens to their notifications.',
                self::callback(
                    static fn(array $context): bool => ($context['exception'] ?? null) instanceof \Throwable,
                ),
            );
        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 500]));
        self::assertFalse(new OnlineRecipients($hub, new Topics(), $logger, $client, true)->isOnline(1));
    }

    public function testPushesAfterTheNoticesOfTheHubWhereverAnOperationEnds(): void
    {
        self::assertSame(
            [
                KernelEvents::TERMINATE => ['flush', -16],
                ConsoleEvents::TERMINATE => ['flush', -16],
                WorkerMessageHandledEvent::class => ['flush', -16],
                WorkerMessageFailedEvent::class => ['flush', -16],
            ],
            PushDispatcher::getSubscribedEvents(),
        );
    }
}
