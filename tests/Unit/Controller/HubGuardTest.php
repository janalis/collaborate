<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Controller;

use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Admission;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\TestPlaces;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(HubGuard::class)]
#[CoversClass(Admission::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(PageGrant::class)]
#[UsesClass(Topics::class)]
#[UsesClass(Grant::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
#[UsesClass(RoleDraftAccessChecker::class)]
final class HubGuardTest extends HubTestCase
{
    public function testAdmitsTheUserItsPageRoomAndTheBody(): void
    {
        $admission = $this->guard()->admit($this->request('/cursors/events', ['kind' => 'hello']), 4096);
        self::assertInstanceOf(Admission::class, $admission);
        self::assertSame($this->user, $admission->user);
        self::assertSame(15, $admission->userId);
        self::assertMatchesRegularExpression('/^urn:collaborate:cursors:[a-f0-9]{64}$/', $admission->topic);
        self::assertSame('hello', $admission->data['kind'] ?? null);
        self::assertTrue($this->session?->isStarted());
    }

    /** @return iterable<string, array{array<string, bool>, bool, bool}> */
    public static function refusedCallers(): iterable
    {
        $admitted = ['ROLE_ADMIN' => true, 'IS_AUTHENTICATED_REMEMBERED' => true];
        yield 'without the role' => [['IS_AUTHENTICATED_REMEMBERED' => true], true, true];
        yield 'signed out' => [$admitted, false, true];
        yield 'not remembered' => [['ROLE_ADMIN' => true], true, true];
        yield 'with a forged CSRF token' => [$admitted, true, false];
    }

    /** @param array<string, bool> $granted */
    #[DataProvider('refusedCallers')]
    public function testRefusesCallersThatMayNotCollaborate(array $granted, bool $signedIn, bool $csrf): void
    {
        $this->granted = $granted;
        $this->signedIn = $signedIn ? $this->user : null;
        $this->csrf = $csrf;
        $this->expectException(AccessDeniedException::class);
        $this->guard()->admit($this->request('/cursors/events'), 4096);
    }

    public function testAPlaceIsAskedForAtEveryRequestAndALostOneIsRefused(): void
    {
        $this->places = new TestPlaces();
        $this->pages = new PageGrant('secret', new Topics(), places: $this->places);
        $admission = $this->guard()->admit($this->request('/cursors/events'), 4096);
        self::assertInstanceOf(Admission::class, $admission);
        self::assertSame([[$this->user, ['articles']]], $this->places->asked);
        $this->places->revoked = [['articles']];
        try {
            $this->guard()->admit($this->request('/cursors/events'), 4096);
            self::fail('Admitted a page whose place was revoked.');
        } catch (AccessDeniedHttpException $error) {
            self::assertSame('The page grant names a place the user may no longer enter.', $error->getMessage());
        }
    }

    public function testAPlaceGrantIsRefusedWithoutItsProvider(): void
    {
        $this->pages = new PageGrant('secret', new Topics(), places: new TestPlaces());
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('The page grant names a place the user may no longer enter.');
        $this->guard()->admit($this->request('/cursors/events'), 4096);
    }

    public function testRefusesAUserTheProviderGivesNoId(): void
    {
        $this->user->id = null;
        $this->expectException(AccessDeniedException::class);
        $this->guard()->admit($this->request('/cursors/events'), 4096);
    }

    public function testRefusesAForgedOrMissingPageGrant(): void
    {
        foreach (['forged', null, 7] as $grant) {
            try {
                $this->guard()->admit($this->request('/cursors/events', ['grant' => $grant]), 4096);
                self::fail('Accepted an invalid page grant.');
            } catch (AccessDeniedHttpException $error) {
                self::assertSame('Invalid cursor page grant.', $error->getMessage());
            }
        }
    }

    public function testRefusesABodyOverTheLimitOrNotJson(): void
    {
        $request = $this->request('/cursors/events');
        $limit = \strlen($request->getContent());
        self::assertInstanceOf(Admission::class, $this->guard()->admit($request, $limit));
        try {
            $this->guard()->admit($this->request('/cursors/events', ['extra' => str_repeat('x', 100)]), 100);
            self::fail('Accepted an oversized body.');
        } catch (BadRequestHttpException $error) {
            self::assertSame('Cursor event is too large.', $error->getMessage());
        }
        try {
            $this->guard()->admit($this->request('/cursors/events'), $limit - 1);
            self::fail('Accepted a body one byte over the limit.');
        } catch (BadRequestHttpException $error) {
            self::assertSame('Cursor event is too large.', $error->getMessage());
        }
        $request = $this->request('/cursors/events');
        $request->initialize(content: 'not json');
        // The kernel answers a request exception with 400.
        $this->expectException(JsonException::class);
        $this->guard()->admit($request, 4096);
    }

    public function testTheConfiguredLimiterAnswers429PerUser(): void
    {
        $limiter = new RateLimiterFactory([
            'id' => 'cursor',
            'policy' => 'fixed_window',
            'limit' => 1,
            'interval' => '1 minute',
        ], new InMemoryStorage());
        $this->limiter = $limiter;
        self::assertInstanceOf(Admission::class, $this->guard()->admit($this->request('/cursors/events'), 4096));
        $limited = $this->guard()->admit($this->request('/cursors/events'), 4096);
        self::assertInstanceOf(JsonResponse::class, $limited);
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('1', $limited->headers->get('Retry-After'));
        self::assertTrue($limited->headers->hasCacheControlDirective('no-store'));
        self::assertSame(['error' => 'Cursor rate limit exceeded.'], $this->decode($limited));
        self::assertTrue($limiter->create('16')->consume()->isAccepted());
    }

    public function testWithoutALimiterNothingIsLimited(): void
    {
        for ($request = 0; $request < 50; ++$request) {
            self::assertInstanceOf(Admission::class, $this->guard()->admit($this->request('/cursors/events'), 4096));
        }
    }
}
