<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Controller;

use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Controller\HubController;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Grant as AuthorizationGrant;
use Janalis\Collaborate\Hub\Admission;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\Arrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(HubController::class)]
#[UsesClass(HubGuard::class)]
#[UsesClass(Admission::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(PageGrant::class)]
#[UsesClass(Topics::class)]
#[UsesClass(AuthorizationGrant::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
#[UsesClass(RoleDraftAccessChecker::class)]
final class HubControllerTest extends HubTestCase
{
    private bool $updates = true;

    public function testSessionCredentialsArePrivateScopedAndShortLived(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $factory = $this->createMock(TokenFactoryInterface::class);
        $factory
            ->expects($this->once())
            ->method('create')
            ->with(
                self::callback(static function (array $grants): bool {
                    self::assertInstanceOf(Grant::class, $grants[0]);
                    self::assertSame([Grant::ACTION_SUBSCRIBE], $grants[0]->actions);
                    self::assertIsString($grants[0]->topics[0]);
                    self::assertStringStartsWith('urn:collaborate:cursors:', $grants[0]->topics[0]);

                    return true;
                }),
                self::callback(static function (array $claims): bool {
                    self::assertInstanceOf(\DateTimeImmutable::class, $claims['exp']);

                    return (
                        $claims['exp']->getTimestamp() >= (time() + 299)
                        && $claims['exp']->getTimestamp() <= (time() + 301)
                    );
                }),
            )
            ->willReturn('subscriber-jwt');
        $this->hub->method('getFactory')->willReturn($factory);
        $this->hub->method('getPublicUrl')->willReturn('/.well-known/mercure');
        $this->hub->expects($this->never())->method('publish');
        $response = $this->handle();
        $data = $this->decode($response);
        self::assertSame('/.well-known/mercure', $data['url']);
        self::assertSame('subscriber-jwt', $data['token']);
        self::assertSame(15, $data['userId']);
        self::assertSame([], $data['resend']);
        self::assertSame([], $data['denied']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testTheSubscriptionLifetimeAndPageDraftLimitAreConfigured(): void
    {
        $factory = $this->createMock(TokenFactoryInterface::class);
        $factory
            ->expects($this->once())
            ->method('create')
            ->with(self::anything(), self::callback(static function (array $claims): bool {
                self::assertInstanceOf(\DateTimeImmutable::class, $claims['exp']);

                return (
                    $claims['exp']->getTimestamp() >= (time() + 59)
                    && $claims['exp']->getTimestamp() <= (time() + 61)
                );
            }))
            ->willReturn('jwt');
        $this->hub->method('getFactory')->willReturn($factory);
        $controller = new HubController($this->guard(), $this->drafts, new Topics(), $this->hub, true, 2, 60);
        self::assertSame(
            200,
            $controller($this->request('/cursors/session', ['drafts' => ['a', 'b']]))->getStatusCode(),
        );
        $this->expectException(BadRequestHttpException::class);
        $controller($this->request('/cursors/session', ['drafts' => ['a', 'b', 'c']]));
    }

    public function testByDefaultASessionLastsFiveMinutesSkipsSavedViewsAndProvesEightDrafts(): void
    {
        $expires = [];
        $factory = $this->createStub(TokenFactoryInterface::class);
        $factory
            ->method('create')
            ->willReturnCallback(static function (array $grants, array $claims) use (&$expires): string {
                $expires[] = $claims['exp'] ?? null;

                return json_encode($grants[0] instanceof Grant ? $grants[0]->topics : null, \JSON_THROW_ON_ERROR);
            });
        $this->hub->method('getFactory')->willReturn($factory);
        $controller = new HubController($this->guard(), $this->drafts, new Topics(), $this->hub);

        $before = time();
        $data = $this->decode($controller($this->request('/cursors/session', [
            'updates' => true,
            'drafts' => array_fill(0, 8, 'grant'),
        ])));
        $after = time();
        self::assertIsString($data['token']);
        self::assertCount(1, Arrays::json($data['token']));
        self::assertInstanceOf(\DateTimeImmutable::class, $expires[0]);
        self::assertGreaterThanOrEqual($before + 300, $expires[0]->getTimestamp());
        self::assertLessThanOrEqual($after + 300, $expires[0]->getTimestamp());
        $this->expectException(BadRequestHttpException::class);
        $controller($this->request('/cursors/session', ['drafts' => array_fill(0, 9, 'grant')]));
    }

    public function testAHostWithoutNotificationsSubscribesAPageThatAsksForThemToItsRoomAlone(): void
    {
        $data = $this->sessionTopics(['notifications' => true]);
        self::assertCount(1, $data['topics']);
        self::assertStringStartsWith('urn:collaborate:cursors:', $data['topics'][0]);
    }

    public function testADraftProvedTwiceIsSubscribedOnce(): void
    {
        $first = $this->draftGrant('first');
        $data = $this->sessionTopics(['drafts' => [$first, $first, $this->draftGrant('second')]]);
        self::assertSame(
            [$this->drafts->topic('first'), $this->drafts->topic('second')],
            \array_slice($data['topics'], 1),
        );
        self::assertTrue(array_is_list($data['topics']));
    }

    public function testOneSessionSubscribesToThePageCursorsSavedViewsAndEveryDraftItsGrantsProve(): void
    {
        $verified = $this->draftGrant('verified');
        $this->drafts->verify($verified, $this->user, time());
        $data = $this->sessionTopics([
            'updates' => true,
            'drafts' => [
                explode('.', $verified)[1],
                $this->draftGrant('full'),
                explode('.', $this->draftGrant('forgotten'))[1],
                $this->draftGrant('foreign', 99),
                42,
                $verified,
            ],
        ]);
        self::assertStringStartsWith('urn:collaborate:cursors:', $data['topics'][0]);
        self::assertSame(
            [new Topics()->updates(), $this->drafts->topic('verified'), $this->drafts->topic('full')],
            \array_slice($data['topics'], 1),
        );
        // A signature the server does not know is sent in full again; a grant it refuses is dropped.
        self::assertSame([2], $data['resend']);
        self::assertSame([3, 4], $data['denied']);
    }

    public function testSavedViewsNeedUpdatesAndDraftsNeedSharedDrafts(): void
    {
        $grant = $this->draftGrant('record');
        $this->drafts = $this->draftAccess(false);
        $data = $this->sessionTopics(['updates' => true, 'drafts' => [$grant]]);
        self::assertCount(2, $data['topics']);
        self::assertSame([0], $data['denied']);
        self::assertCount(1, $this->sessionTopics(['updates' => 'yes'])['topics']);
        $this->updates = false;
        self::assertCount(1, $this->sessionTopics(['updates' => true])['topics']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDrafts(): iterable
    {
        yield 'not a list' => [['a' => 'grant']];
        yield 'not an array' => ['grant'];
        yield 'too many' => [array_fill(0, 9, 'grant')];
    }

    #[DataProvider('invalidDrafts')]
    public function testRejectsMalformedSessionDrafts(mixed $drafts): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->handle(['drafts' => $drafts]);
    }

    public function testASessionOverTheRateLimitIsAnswered429(): void
    {
        $this->limiter = new RateLimiterFactory([
            'id' => 'cursor',
            'policy' => 'fixed_window',
            'limit' => 1,
            'interval' => '1 minute',
        ], new InMemoryStorage());
        $this->hub->method('getFactory')->willReturn($this->createStub(TokenFactoryInterface::class));
        self::assertSame(200, $this->handle()->getStatusCode());
        $limited = $this->handle();
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame(['error' => 'Cursor rate limit exceeded.'], $this->decode($limited));
    }

    public function testASessionBodyMayCarryFullDraftGrantsUpToTheDraftRequestLimit(): void
    {
        $this->hub->method('getFactory')->willReturn($this->createStub(TokenFactoryInterface::class));
        self::assertSame(200, $this->handle(['extra' => str_repeat('x', 8192)])->getStatusCode());
        $this->drafts = $this->draftAccess(true, 1024);
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Cursor event is too large.');
        $this->handle(['extra' => str_repeat('x', 1024)]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{topics: list<string>, token: string, resend: list<int>, denied: list<int>}
     */
    private function sessionTopics(array $overrides): array
    {
        $factory = $this->createStub(TokenFactoryInterface::class);
        $factory
            ->method('create')
            ->willReturnCallback(
                /** @param list<Grant> $grants */
                static fn(array $grants): string => json_encode($grants[0]->topics, \JSON_THROW_ON_ERROR),
            );
        $this->hub = $this->createStub(HubInterface::class);
        $this->hub->method('getFactory')->willReturn($factory);
        /** @var array{topics: list<string>, token: string, resend: list<int>, denied: list<int>} $data */
        $data = $this->decode($this->handle($overrides));
        self::assertSame($data['topics'], json_decode($data['token'], true, flags: \JSON_THROW_ON_ERROR));

        return $data;
    }

    /** @param array<string, mixed> $overrides */
    private function handle(array $overrides = []): Response
    {
        $controller = new HubController($this->guard(), $this->drafts, new Topics(), $this->hub, $this->updates);
        $response = $controller($this->request('/cursors/session', $overrides));
        self::assertTrue(
            $this->session?->isStarted(),
            'Security must persist its token before releasing the session lock.',
        );

        return $response;
    }
}
