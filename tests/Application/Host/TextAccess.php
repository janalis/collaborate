<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Host;

use Janalis\Collaborate\Access\RecordDraftAccessCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** A host policy: only the two editors can edit the example's named text records. */
final class TextAccess implements RecordDraftAccessCheckerInterface
{
    public function canEdit(UserInterface $user, string $permission, string $entityClass): bool
    {
        return 'example-text' === $entityClass && 'update' === $permission;
    }

    public function canEditRecord(UserInterface $user, string $permission, string $entityClass, ?string $identity): bool
    {
        return (
            \in_array($user->getUserIdentifier(), ['alice', 'bob'], true)
            && null !== $identity
            && 1 === preg_match('/^[a-z0-9-]{1,64}$/D', $identity)
        );
    }
}
