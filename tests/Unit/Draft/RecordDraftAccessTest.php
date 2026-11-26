<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\RecordAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[CoversClass(DraftAccess::class)]
#[UsesClass(Grant::class)]
#[UsesClass(RoleDraftAccessChecker::class)]
#[UsesClass(Topics::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
final class RecordDraftAccessTest extends TestCase
{
    use DraftAccessFactory;

    public function testChecksRecordPermissionAgainForCachedSignatureAfterRevocation(): void
    {
        $checker = $this->checker();
        $access = $this->access(checker: $checker);
        $user = $this->user();
        $scope = ['record' => '42', 'id' => $access->draftId(\stdClass::class, '42')] + self::SCOPE;
        $grant = $access->issue($scope, 7, 100, $user->getRoles());
        self::assertSame('42', $access->verify($grant, $user, 101)['record'] ?? null);
        $signature = explode('.', $grant)[1];
        self::assertSame('42', $access->verify($signature, $user, 102)['record'] ?? null);
        self::assertSame(['42', '42'], $checker->identities);
        $checker->allowed = false;
        $this->expectException(AccessDeniedHttpException::class);
        $access->verify($signature, $user, 103);
    }

    public function testRejectsLegacyMissingEmptyAndMismatchedRecordIdentities(): void
    {
        $checker = $this->checker();
        $access = $this->access(checker: $checker);
        $user = $this->user();
        foreach ([
            self::SCOPE,
            ['record' => null] + self::SCOPE,
            ['record' => ''] + self::SCOPE,
            ['record' => '42'] + self::SCOPE,
            ['record' => '42', 'new' => true] + self::SCOPE,
            ['record' => null, 'new' => true] + self::SCOPE,
            ['record' => null, 'new' => true, 'draft' => ''] + self::SCOPE,
        ] as $scope) {
            try {
                $access->verify($access->issue($scope, 7, 100, $user->getRoles()), $user, 101);
                self::fail('A scope without a bound record was accepted.');
            } catch (AccessDeniedHttpException) {
                self::assertSame([], $checker->identities);
            }
        }
    }

    public function testCreatePermissionHasNullRecordAndBoundNewDraft(): void
    {
        $checker = $this->checker();
        $access = $this->access(checker: $checker);
        $user = $this->user();
        $scope =
            [
                'record' => null,
                'new' => true,
                'permission' => 'create',
                'draft' => 'abc',
                'id' => $access->draftId(\stdClass::class, 'new:abc'),
            ] + self::SCOPE;
        self::assertNull(
            $access->verify($access->issue($scope, 7, 100, $user->getRoles()), $user, 101)['record'] ?? null,
        );
        self::assertSame([null], $checker->identities);
    }

    public function testHeadlessScopeRequiresARecordAwareChecker(): void
    {
        $access = $this->access();
        $user = $this->user();
        $this->expectException(AccessDeniedHttpException::class);
        $access->verify($access->issue(['headless' => true] + self::SCOPE, 7, 100, $user->getRoles()), $user, 101);
    }

    private function checker(): RecordAccessChecker
    {
        return new RecordAccessChecker();
    }
}
