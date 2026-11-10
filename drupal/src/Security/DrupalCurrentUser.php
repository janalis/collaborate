<?php

declare(strict_types=1);

namespace Drupal\collaborate\Security;

use Drupal\Core\Session\AccountProxyInterface;
use Janalis\Collaborate\Security\CurrentUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The current account, granted the bundle's attributes as Drupal permissions: the module configures the
 * collaboration role as `use collaborate`, notifications' as `receive collaborate notifications`.
 */
final readonly class DrupalCurrentUser implements CurrentUserInterface
{
    /** What the hub asks of any signed-in session. */
    private const string AUTHENTICATED = 'IS_AUTHENTICATED_REMEMBERED';

    public function __construct(
        private AccountProxyInterface $account,
    ) {}

    public function getUser(): ?UserInterface
    {
        return $this->account->isAuthenticated() ? new DrupalUser($this->account->getAccount()) : null;
    }

    public function isGranted(string $attribute): bool
    {
        if (self::AUTHENTICATED === $attribute) {
            return $this->account->isAuthenticated();
        }

        return $this->account->isAuthenticated() && $this->account->hasPermission($attribute);
    }
}
