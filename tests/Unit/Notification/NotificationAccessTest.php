<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification;

use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Notification\NotificationAccess;
use Janalis\Collaborate\Notification\StreamGuardInterface;
use Janalis\Collaborate\Security\CurrentUserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(NotificationAccess::class)]
final class NotificationAccessTest extends TestCase
{
    public function testNotificationsAreOffUnlessTurnedOn(): void
    {
        $access = new NotificationAccess(
            $this->security(new InMemoryUser('alice', null), true),
            $this->collaborators(),
        );
        self::assertFalse($access->enabled);
        self::assertNull($access->recipient());
    }

    public function testAVisitorOrAUserWithoutTheRoleIsNoRecipient(): void
    {
        // A role granted to visitors still needs a user.
        $visitor = new NotificationAccess($this->security(null, true), $this->collaborators(), true, 'PUBLIC_ACCESS');
        self::assertNull($visitor->recipient());
        $reader = new NotificationAccess(
            $this->security(new InMemoryUser('bob', null), false),
            $this->collaborators(),
            true,
        );
        self::assertNull($reader->recipient());
        $admin = new NotificationAccess(
            $this->security(new InMemoryUser('alice', null), true),
            $this->collaborators(),
            true,
        );
        self::assertSame(15, $admin->recipient());
    }

    public function testThePagesHearOfTheirNotificationsUnlessTheGuardSaysNo(): void
    {
        $security = $this->security(new InMemoryUser('alice', null), true);
        self::assertTrue(new NotificationAccess($security, $this->collaborators(), true)->streams());
        foreach ([true, false] as $streams) {
            $guard = $this->createStub(StreamGuardInterface::class);
            $guard->method('streams')->willReturn($streams);
            self::assertSame(
                $streams,
                new NotificationAccess($security, $this->collaborators(), true, 'ROLE_USER', $guard)->streams(),
            );
        }
    }

    private function security(?InMemoryUser $user, bool $granted): CurrentUserInterface
    {
        $security = $this->createStub(CurrentUserInterface::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturn($granted);

        return $security;
    }

    private function collaborators(): CollaboratorProviderInterface
    {
        $collaborators = $this->createStub(CollaboratorProviderInterface::class);
        $collaborators->method('id')->willReturn(15);

        return $collaborators;
    }
}
