<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Access;

use Symfony\Component\Security\Core\User\UserInterface;

/** Whether a user may share the draft of a record, checked when its form renders and on every draft request. */
interface DraftAccessCheckerInterface
{
    public const string CREATE = 'create';
    public const string UPDATE = 'update';

    /**
     * @param self::CREATE|self::UPDATE $permission
     * @param class-string              $entityClass
     */
    public function canEdit(UserInterface $user, string $permission, string $entityClass): bool;
}
