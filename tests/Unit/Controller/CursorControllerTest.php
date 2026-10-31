<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Controller;

use Janalis\Collaborate\Controller\CursorController;
use Janalis\Collaborate\Cursor\CursorEvent;
use Janalis\Collaborate\Cursor\CursorPosition;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Admission;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(CursorController::class)]
#[UsesClass(CursorEvent::class)]
#[UsesClass(CursorPosition::class)]
#[UsesClass(HubGuard::class)]
#[UsesClass(Admission::class)]
#[UsesClass(HubPublisher::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(PageGrant::class)]
#[UsesClass(Topics::class)]
#[UsesClass(Grant::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
final class CursorControllerTest extends CursorTestCase
{
    public function testPublishesServerIdentityAndStripsUntrustedFields(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with(self::callback(static function (Update $update): bool {
                /** @var array<string, mixed> $data */
                $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);

                return (
                    $update->isPrivate()
                    && str_starts_with(
                        json_encode($update->getTopics(), \JSON_THROW_ON_ERROR),
                        '["urn:collaborate:cursors:',
                    )
                    && 15 === $data['userId']
                    && '<b>Alice</b>' === $data['label']
                    && 'indigo' === $data['color']
                    && null === $data['avatar']
                    && \is_int($data['timestamp'])
                    && !\array_key_exists('grant', $data)
                    && !\array_key_exists('extra', $data)
                    && 'move' === $data['kind']
                    && 'cursors' === $data['channel']
                );
            }))
            ->willReturn('event-id');
        $response = $this->handle([
            'userId' => 99,
            'label' => 'fake',
            'color' => 'red',
            'avatar' => 'https://evil.example/x.png',
            'extra' => 'fake',
        ]);
        self::assertSame(204, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testAllNonMovementEventsMayOmitTheirAnchor(): void
    {
        $published = [];
        foreach (['hide', 'hello', 'heartbeat', 'leave'] as $kind) {
            $published = [...$published, ...$this->publish(['kind' => $kind, 'anchor' => null])];
        }
        self::assertSame(['hide', 'hello', 'heartbeat', 'leave'], array_column($published, 'kind'));
    }

    public function testPublishesTheAvatarAndLabelTheProviderResolves(): void
    {
        $this->collaborators = new class implements CollaboratorProviderInterface {
            public function id(UserInterface $user): ?int
            {
                return 15;
            }

            public function label(UserInterface $user): string
            {
                return 'Alice A.';
            }

            public function avatarUrl(UserInterface $user): ?string
            {
                return '/avatars/alice.png';
            }

            public function authorization(UserInterface $user): array
            {
                return ['ROLE_SUPER_ADMIN'];
            }
        };
        $published = $this->publish(['kind' => 'heartbeat', 'anchor' => null]);
        self::assertSame('/avatars/alice.png', $published[0]['avatar']);
        self::assertSame('Alice A.', $published[0]['label']);
    }

    public function testAColourIsTheUserIdModuloTheConfiguredColours(): void
    {
        self::assertSame('gray', $this->publish([], ['red', 'green', 'blue', 'gray'])[0]['color']);
    }

    public function testRateLimitRejectsBeforePublication(): void
    {
        $this->limiter = new RateLimiterFactory([
            'id' => 'cursor',
            'policy' => 'token_bucket',
            'limit' => 30,
            'rate' => ['interval' => '1 second', 'amount' => 20],
        ], new InMemoryStorage());
        $this->hub = $this->createMock(HubInterface::class);
        $this->limiter->create('15')->consume(30);
        $this->hub->expects($this->never())->method('publish');
        $response = $this->handle();
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
    }

    public function testHubFailureIsLoggedAfterTheResponseAndLaterEventsBackOff(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->hub
            ->expects($this->exactly(2))
            ->method('publish')
            ->willThrowException(new \RuntimeException('unavailable'));
        $this->logger->expects($this->exactly(2))->method('warning')->with('Cursor Mercure publication failed.');
        $clock = new MockClock();
        $publisher = new HubPublisher($this->hub, $this->logger, $clock, 5);
        self::assertSame(204, $this->handle(publisher: $publisher)->getStatusCode());

        $paused = $this->handle(publisher: $publisher);
        self::assertSame(503, $paused->getStatusCode());
        self::assertSame('1', $paused->headers->get('Retry-After'));
        self::assertTrue($paused->headers->hasCacheControlDirective('no-store'));
        self::assertSame('{"error":"Cursor hub unavailable."}', $paused->getContent());

        $clock->sleep(5);
        self::assertSame(204, $this->handle(publisher: $publisher)->getStatusCode());
    }

    public function testPublishesOnlyOnceTheResponseReleasedTheSession(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->hub->expects($this->never())->method('publish');
        self::assertSame(204, $this->handle(terminate: false)->getStatusCode());
    }

    public function testAnswersNotFoundWhenCursorsAreDisabled(): void
    {
        $this->enabled = false;
        $this->signedIn = null;
        $this->expectException(NotFoundHttpException::class);
        $this->handle();
    }

    public function testRejectsRequestsOverTheConfiguredEventSize(): void
    {
        self::assertSame(204, $this->handle(['extra' => str_repeat('x', 4096)], maxEventBytes: 8192)->getStatusCode());
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Cursor event is too large.');
        $this->handle(['extra' => str_repeat('x', 4096)]);
    }
}
