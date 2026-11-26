<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Access;

use Symfony\Component\Security\Core\User\UserInterface;

/** Optional record-level policy, checked in addition to the class policy on every draft request. */
interface RecordDraftAccessCheckerInterface extends DraftAccessCheckerInterface
{
    /**
     * The identity comes from the host's signed scope, never from an unsigned request parameter.
     * A null identity names a create operation; existing records always have a non-empty identity.
     *
     * @param self::CREATE|self::UPDATE $permission
     */
    public function canEditRecord(
        UserInterface $user,
        string $permission,
        string $entityClass,
        ?string $identity,
    ): bool;
}
