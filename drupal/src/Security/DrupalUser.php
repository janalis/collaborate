<?php

declare(strict_types=1);

namespace Drupal\collaborate\Security;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** A Drupal account as the bundle's services know a user: its id, its roles. */
final readonly class DrupalUser implements UserInterface
{
    public function __construct(
        public AccountInterface $account,
    ) {}

    /** @return list<string> Drupal's role ids, which no grant compares: the authorization names the permissions. */
    public function getRoles(): array
    {
        return array_values($this->account->getRoles());
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        return (string) $this->account->id() ?: 'anonymous';
    }

    public function eraseCredentials(): void {}
}
