<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[CoversClass(DraftAccess::class)]
#[UsesClass(Grant::class)]
#[UsesClass(Topics::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
#[UsesClass(RoleDraftAccessChecker::class)]
final class DraftAccessTest extends TestCase
{
    use DraftAccessFactory;

    public function testIssuesAnAccountBoundExpiringCapability(): void
    {
        $access = $this->access();
        $user = $this->user();
        $grant = $access->issue(self::SCOPE, 7, 100, $user->getRoles());
        self::assertSame('record', $access->verify($grant, $user, 101)['id']);
        self::assertSame(36_100, $access->verify($grant, $user, 101)['expires']);
        self::assertSame(7, $access->verify($grant, $user, 101)['userId']);
        self::assertSame('urn:collaborate:draft:record', $access->topic('record'));
        self::assertTrue($access->enabled);
        self::assertSame(2_097_152, $access->maxRequestBytes);
    }

    public function testTheLifetimeIsConfigured(): void
    {
        $access = $this->access(lifetime: 60);
        $grant = $access->issue(self::SCOPE, 7, 100, $this->user()->getRoles());
        self::assertSame(160, $access->verify($grant, $this->user(), 159)['expires']);
        $this->expectException(AccessDeniedHttpException::class);
        $access->verify($grant, $this->user(), 160);
    }

    public function testRejectsDisabledTamperedExpiredOtherAccountAndRevokedPermission(): void
    {
        $access = $this->access();
        $user = $this->user();
        $grant = $access->issue(self::SCOPE, 7, 100, $user->getRoles());
        $cases = [
            [$this->access(false), $grant, $user, 101],
            [$access, $grant . 'x', $user, 101],
            [$access, $grant, $user, 36_100],
            [$access, $grant, new TestUser(8, 'bob', ['ROLE_SUPER_ADMIN']), 101],
            [$access, $grant, new TestUser(7, 'alice', ['ROLE_ADMIN']), 101],
            [$access, 'invalid', $user, 101],
            [$access, '!.' . hash_hmac('sha256', '!', 'secret'), $user, 101],
            [$this->access(checker: $this->refusing()), $grant, $user, 101],
        ];
        foreach ($cases as [$subject, $token, $account, $time]) {
            try {
                $subject->verify($token, $account, $time);
                self::fail('Unauthorized capability was accepted.');
            } catch (AccessDeniedHttpException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheCheckerIsAskedForTheScopesPermissionAndEntity(): void
    {
        $checker = $this->createMock(DraftAccessCheckerInterface::class);
        $checker
            ->expects($this->once())
            ->method('canEdit')
            ->with($this->user(), 'update', \stdClass::class)
            ->willReturn(true);
        $access = $this->access(checker: $checker);
        $access->verify($access->issue(self::SCOPE, 7, 100, $this->user()->getRoles()), $this->user(), 101);
    }

    public function testADraftIdNamesOneRecordOfOneClass(): void
    {
        $access = $this->access();

        self::assertSame(hash('sha256', \stdClass::class . ':7'), $access->draftId(\stdClass::class, '7'));
        self::assertNotSame($access->draftId(\stdClass::class, '7'), $access->draftId(\ArrayObject::class, '7'));
    }
}
