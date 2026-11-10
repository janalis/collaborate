<?php

declare(strict_types=1);

namespace Drupal\collaborate\Security;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Whether an account may share the draft of an entity form: the `use collaborate` permission, and for a new
 * record the entity's create access to its bundle. An edit form is rendered only to an account its entity's update
 * access admits; the grant, short-lived and bound to the account's roles, carries that decision to the draft
 * requests. The draft's class is `<entity type>:<bundle>` ({@see \Drupal\collaborate\Bridge\DrupalFormContext}).
 */
final readonly class DrupalAccessChecker implements DraftAccessCheckerInterface
{
    public function __construct(
        private EntityTypeManagerInterface $entities,
    ) {}

    public function canEdit(UserInterface $user, string $permission, string $entityClass): bool
    {
        if (!$user instanceof DrupalUser || !$user->account->hasPermission('use collaborate')) {
            return false;
        }
        [$type, $bundle] = explode(':', $entityClass, 2) + [1 => null];
        if (!$this->entities->hasDefinition($type)) {
            return false;
        }
        if (self::CREATE !== $permission) {
            return true;
        }

        return $this->entities->getAccessControlHandler($type)->createAccess($bundle, $user->account);
    }
}
