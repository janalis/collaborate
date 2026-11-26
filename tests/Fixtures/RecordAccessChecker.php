<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures;

use Janalis\Collaborate\Access\RecordDraftAccessCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class RecordAccessChecker implements RecordDraftAccessCheckerInterface
{
    public bool $allowed = true;
    /** @var list<string|null> */
    public array $identities = [];

    public function canEdit(UserInterface $user, string $permission, string $entityClass): bool
    {
        return true;
    }

    public function canEditRecord(UserInterface $user, string $permission, string $entityClass, ?string $identity): bool
    {
        $this->identities[] = $identity;
        return $this->allowed;
    }
}
