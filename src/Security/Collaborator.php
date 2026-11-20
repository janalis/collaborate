<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Security;

use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The signed-in user as a collaborator: the id their rows, grants and cursors name. The one check of who may take
 * part, which the drafts, the hub, the layer and the notifications all make.
 */
final readonly class Collaborator
{
    private function __construct(
        public UserInterface $user,
        public int $id,
    ) {}

    /**
     * The signed-in user, when the provider gives them an id and they hold `$role`; null for a visitor, a user
     * without an id, or one without the role. A null role asks for no role: the hub admits any signed-in user to
     * their notifications.
     */
    public static function current(
        CurrentUserInterface $security,
        CollaboratorProviderInterface $collaborators,
        ?string $role,
    ): ?self {
        $user = $security->getUser();
        if (null === $user) {
            return null;
        }
        $id = $collaborators->id($user);
        if (null === $id || null !== $role && !$security->isGranted($role)) {
            return null;
        }

        return new self($user, $id);
    }
}
