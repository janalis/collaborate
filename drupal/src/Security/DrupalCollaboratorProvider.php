<?php

declare(strict_types=1);

namespace Drupal\collaborate\Security;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\user\UserInterface as DrupalAccount;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Who a Drupal account is to the others: its uid, display name and picture, and the permissions it holds. */
final readonly class DrupalCollaboratorProvider implements CollaboratorProviderInterface
{
    public function __construct(
        private EntityTypeManagerInterface $entities,
        private FileUrlGeneratorInterface $files,
    ) {}

    public function id(UserInterface $user): ?int
    {
        $id = $user instanceof DrupalUser ? (int) $user->account->id() : 0;

        return $id > 0 ? $id : null;
    }

    public function label(UserInterface $user): string
    {
        return $user instanceof DrupalUser ? (string) $user->account->getDisplayName() : $user->getUserIdentifier();
    }

    public function avatarUrl(UserInterface $user): ?string
    {
        $account = $this->account($user);
        if (null === $account || !$account->hasField('user_picture') || $account->get('user_picture')->isEmpty()) {
            return null;
        }
        $uri = $account->get('user_picture')->entity?->getFileUri();

        return \is_string($uri) ? $this->files->generateString($uri) : null;
    }

    /**
     * The account's roles: Drupal derives its permissions from them, so a grant issued before a role was taken
     * or given is refused.
     *
     * @return list<string>
     */
    public function authorization(UserInterface $user): array
    {
        return $user->getRoles();
    }

    private function account(UserInterface $user): ?DrupalAccount
    {
        $id = $this->id($user);
        $account = null === $id ? null : $this->entities->getStorage('user')->load($id);

        return $account instanceof DrupalAccount ? $account : null;
    }
}
