<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * The test application's accounts: `alice` (id 15), `bob` (id 16), `carol` (id 18) and `dave` (id 19) are
 * administrators, `reader` (id 17) is not, and `pending` has no id yet.
 *
 * @implements UserProviderInterface<TestUser>
 */
final class TestUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): TestUser
    {
        return match ($identifier) {
            'alice' => new TestUser(15, 'alice', ['ROLE_ADMIN'], 'Alice'),
            'bob' => new TestUser(16, 'bob', ['ROLE_ADMIN'], 'Bob'),
            'reader' => new TestUser(17, 'reader', ['ROLE_USER'], 'Reader'),
            'carol' => new TestUser(18, 'carol', ['ROLE_ADMIN'], 'Carol'),
            'dave' => new TestUser(19, 'dave', ['ROLE_ADMIN'], 'Dave'),
            'pending' => new TestUser(null, 'pending', ['ROLE_ADMIN']),
            default => throw new UserNotFoundException(\sprintf('No test user "%s".', $identifier)),
        };
    }

    public function refreshUser(UserInterface $user): TestUser
    {
        if (!$user instanceof TestUser) {
            throw new UnsupportedUserException(\sprintf('Unsupported user "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return TestUser::class === $class;
    }
}
