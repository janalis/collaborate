<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The signed-in user of the current request and what they are granted: Symfony's security helper in an
 * application ({@see SymfonyCurrentUser}), the current account under Drupal.
 */
interface CurrentUserInterface
{
    /** The signed-in user, null for a visitor. */
    public function getUser(): ?UserInterface;

    /**
     * Whether the signed-in user holds an attribute: the collaboration role (`collaborate.role`), or
     * `IS_AUTHENTICATED_REMEMBERED` for any signed-in session.
     */
    public function isGranted(string $attribute): bool;
}
