<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Access;

use Symfony\Component\Security\Core\User\UserInterface;

/** Admits every user that holds the bundle's role, which each endpoint already requires. */
final class RoleDraftAccessChecker implements DraftAccessCheckerInterface
{
    public function canEdit(UserInterface $user, string $permission, string $entityClass): bool
    {
        return true;
    }
}
