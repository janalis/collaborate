<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Access;

use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoleDraftAccessChecker::class)]
final class RoleDraftAccessCheckerTest extends TestCase
{
    public function testAdmitsEveryUserTheEndpointsAlreadyAdmitted(): void
    {
        $checker = new RoleDraftAccessChecker();
        self::assertTrue($checker->canEdit(new TestUser(1), DraftAccessCheckerInterface::CREATE, \stdClass::class));
        self::assertTrue($checker->canEdit(
            new TestUser(2, 'bob', []),
            DraftAccessCheckerInterface::UPDATE,
            TestUser::class,
        ));
    }
}
