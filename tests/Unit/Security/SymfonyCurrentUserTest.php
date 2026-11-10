<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Security;

use Janalis\Collaborate\Security\SymfonyCurrentUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(SymfonyCurrentUser::class)]
final class SymfonyCurrentUserTest extends TestCase
{
    public function testTellsTheUserAndTheDecisionsOfSymfonysSecurity(): void
    {
        $user = new InMemoryUser('alice', null, ['ROLE_ADMIN']);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $security
            ->method('isGranted')
            ->willReturnCallback(static fn(mixed $attribute): bool => 'ROLE_ADMIN' === $attribute);
        $current = new SymfonyCurrentUser($security);

        self::assertSame($user, $current->getUser());
        self::assertTrue($current->isGranted('ROLE_ADMIN'));
        self::assertFalse($current->isGranted('ROLE_SUPER_ADMIN'));
    }

    public function testAVisitorIsNoUser(): void
    {
        self::assertNull(new SymfonyCurrentUser($this->createStub(Security::class))->getUser());
    }
}
