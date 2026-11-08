<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Transport\Mercure;

use Janalis\Collaborate\Hub\HubSession;
use Janalis\Collaborate\Hub\Notice;
use Janalis\Collaborate\Hub\Transport\Mercure\MercureTransport;
use Janalis\Collaborate\Hub\Transport\PublishFailedException;
use Janalis\Collaborate\Hub\Transport\Subscription;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\RemoteHubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(MercureTransport::class)]
#[CoversClass(PublishFailedException::class)]
#[CoversClass(Subscription::class)]
#[UsesClass(Notice::class)]
#[UsesClass(HubSession::class)]
final class MercureTransportTest extends TestCase
{
    private const string SUBSCRIPTIONS = 'https://hub.test/.well-known/mercure/subscriptions/urn%3At%3Anotifications%3A5';

    public function testPublishesEachNoticeAsAPrivateUpdateInOrder(): void
    {
        $published = [];
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects($this->exactly(2))
            ->method('publish')
            ->willReturnCallback(static function (Update $update) use (&$published): string {
                $published[] = [$update->getTopics(), $update->getData(), $update->isPrivate()];

                return 'id';
            });

        new MercureTransport($hub)->publish([new Notice(['urn:a', 'urn:b'], '{"a":1}'), new Notice('urn:c', '{}')]);

        self::assertSame([[['urn:a', 'urn:b'], '{"a":1}', true], [['urn:c'], '{}', true]], $published);
    }

    public function testAFailedPublishNamesTheNoticeThatFailedAndStopsThere(): void
    {
        $offline = new \RuntimeException('offline');
        $published = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (
            &$published,
            $offline,
        ): string {
            $published[] = $update->getTopics()[0];
            if ('urn:b' === $update->getTopics()[0]) {
                throw $offline;
            }

            return 'id';
        });

        try {
            new MercureTransport($hub)->publish([
                new Notice('urn:a', '{}'),
                new Notice('urn:b', '{}'),
                new Notice('urn:c', '{}'),
            ]);
            self::fail('The publish should have failed.');
        } catch (PublishFailedException $error) {
            self::assertSame(1, $error->index);
            self::assertSame($offline, $error->getPrevious());
            self::assertSame('The hub refused a notice: offline', $error->getMessage());
        }
        self::assertSame(['urn:a', 'urn:b'], $published);
    }

    public function testSubscribesThePageToTheTopicsOfItsSessionForTheSubscriptionLifetime(): void
    {
        $claims = [];
        $factory = $this->createMock(TokenFactoryInterface::class);
        $factory
            ->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(static function (array $grants, array $given) use (&$claims): string {
                self::assertCount(1, $grants);
                self::assertInstanceOf(Grant::class, $grants[0]);
                self::assertSame([Grant::ACTION_SUBSCRIBE], $grants[0]->actions);
                self::assertSame(['urn:room', 'urn:draft'], $grants[0]->topics);
                $claims[] = $given;

                return 'subscriber-jwt';
            });
        $hub = $this->createStub(HubInterface::class);
        $hub->method('getFactory')->willReturn($factory);
        $hub->method('getPublicUrl')->willReturn('/.well-known/mercure');
        $session = new HubSession(15, ['urn:room', 'urn:draft']);

        $before = time();
        $subscription = new MercureTransport($hub)->subscribe($session, new Request());
        $short = new MercureTransport($hub, subscriptionTtl: 60)->subscribe($session, new Request());
        $after = time();

        self::assertSame(Subscription::MERCURE, $subscription->transport);
        // A page on Mercure posts its cursor events.
        self::assertFalse($subscription->cursorEvents);
        self::assertSame('/.well-known/mercure', $subscription->url);
        self::assertSame('subscriber-jwt', $subscription->token);
        self::assertSame('subscriber-jwt', $short->token);
        self::assertSame(['exp'], array_keys($claims[0]));
        self::assertInstanceOf(\DateTimeImmutable::class, $claims[0]['exp']);
        self::assertGreaterThanOrEqual($before + 300, $claims[0]['exp']->getTimestamp());
        self::assertLessThanOrEqual($after + 300, $claims[0]['exp']->getTimestamp());
        self::assertInstanceOf(\DateTimeImmutable::class, $claims[1]['exp']);
        self::assertGreaterThanOrEqual($before + 60, $claims[1]['exp']->getTimestamp());
        self::assertLessThanOrEqual($after + 60, $claims[1]['exp']->getTimestamp());
    }

    public function testNobodyListensWithoutAClientOrATokenFactory(): void
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
        self::assertFalse(new MercureTransport($hub, $logger)->listening('urn:t'));
        $bare = $this->createStub(HubInterface::class);
        $bare->method('getFactory')->willReturn(null);
        self::assertFalse(new MercureTransport($bare, $logger, $client)->listening('urn:t'));
        self::assertCount(0, $asked);
        self::assertTrue(new MercureTransport($hub, $logger, $client)->listening('urn:t'));
        self::assertCount(1, $asked);
    }

    public function testAsksThePublicHubAndCountsActiveSubscriptionsAlone(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('getFactory')->willReturn($this->factory(6));
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
        $transport = new MercureTransport($hub, $this->createStub(LoggerInterface::class), $client);
        self::assertTrue($transport->listening('urn:t:notifications:5'));
        self::assertSame('GET', $asked[0][0]);
        self::assertSame(self::SUBSCRIPTIONS, $asked[0][1]);
        self::assertIsArray($asked[0][2]);
        self::assertContains('Authorization: Bearer jwt', $asked[0][2]);
        // A hub slow to answer is one that does not: the push is sent.
        self::assertSame(2.0, $asked[0][3]);
        self::assertFalse($transport->listening('urn:t:notifications:5'));
        self::assertFalse($transport->listening('urn:t:notifications:5'));
        self::assertTrue($transport->listening('urn:t:notifications:5'));
        self::assertFalse($transport->listening('urn:t:notifications:5'));
        // A subscription that does not say whether it is active is.
        self::assertTrue($transport->listening('urn:t:notifications:5'));
    }

    public function testAsksARemoteHubAtItsInternalUrl(): void
    {
        $hub = $this->createStub(RemoteHubInterface::class);
        $hub->method('getFactory')->willReturn($this->factory(1));
        $hub->method('getUrl')->willReturn('https://hub.test/.well-known/mercure');
        $hub->method('getPublicUrl')->willReturn('https://public.test/.well-known/mercure');
        $asked = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$asked): MockResponse {
            $asked[] = $url;

            return new MockResponse('{"subscriptions":[{"active":true}]}');
        });

        self::assertTrue(new MercureTransport($hub, null, $client)->listening('urn:t:notifications:5'));
        self::assertSame([self::SUBSCRIPTIONS], $asked);
    }

    public function testAHubThatFailsReadsAsNobodyListeningAndIsLogged(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('getFactory')->willReturn($this->createStub(TokenFactoryInterface::class));
        $hub->method('getPublicUrl')->willReturn('https://hub.test/.well-known/mercure');
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('info')
            ->with(
                'The hub did not say who listens to a topic.',
                self::callback(
                    static fn(array $context): bool => ($context['exception'] ?? null) instanceof \Throwable,
                ),
            );
        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 500]));
        self::assertFalse(new MercureTransport($hub, $logger, $client)->listening('urn:t'));
        // Without a logger, the failure is not told.
        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 500]));
        self::assertFalse(new MercureTransport($hub, null, $client)->listening('urn:t'));
    }

    private function factory(int $times): TokenFactoryInterface
    {
        $factory = $this->createMock(TokenFactoryInterface::class);
        $factory
            ->expects($this->exactly($times))
            ->method('create')
            ->with(self::callback(
                static fn(array $grants): bool => (
                    1 === \count($grants)
                    && ($grants[0] ?? null) instanceof Grant
                    && [Grant::ACTION_SUBSCRIBE] === $grants[0]->actions
                    && [self::SUBSCRIPTIONS, '/.well-known/mercure/subscriptions{/topic}{/subscriber}']
                    === $grants[0]->topics
                ),
            ))
            ->willReturn('jwt');

        return $factory;
    }
}
