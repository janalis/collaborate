<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures;

use Symfony\Component\Security\Core\User\UserInterface;

/** A signed-in user of the test application, read by the default collaborator provider through its public `id`. */
final class TestUser implements UserInterface, \Stringable
{
    /** @param list<string> $roles */
    public function __construct(
        public ?int $id = null,
        public string $username = 'alice',
        public array $roles = ['ROLE_ADMIN'],
        public ?string $name = null,
    ) {}

    public function __toString(): string
    {
        return $this->name ?? $this->username;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void {}

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        return '' === $this->username ? 'anonymous' : $this->username;
    }
}
