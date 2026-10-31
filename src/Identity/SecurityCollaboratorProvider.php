<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Identity;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Reads a public `id` property or a `getId()` method, labels a user by its string form (its identifier
 * when it has none) and admits it by its roles. Applications with groups or per-entity permissions
 * provide their own implementation, so a grant lapses when any of them changes.
 */
final class SecurityCollaboratorProvider implements CollaboratorProviderInterface
{
    public function id(UserInterface $user): ?int
    {
        /** @var mixed $id */
        $id = get_object_vars($user)['id'] ?? (method_exists($user, 'getId') ? $user->getId() : null);
        if (\is_int($id)) {
            return $id > 0 ? $id : null;
        }

        return \is_string($id) && ctype_digit($id) && '0' !== ltrim($id, '0') ? (int) $id : null;
    }

    public function label(UserInterface $user): string
    {
        return $user instanceof \Stringable ? (string) $user : $user->getUserIdentifier();
    }

    public function avatarUrl(UserInterface $user): ?string
    {
        return null;
    }

    public function authorization(UserInterface $user): array
    {
        return array_values(array_unique($user->getRoles()));
    }
}
