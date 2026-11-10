<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification;

use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Security\CurrentUserInterface;

/** Whether notifications are on, and who the signed-in user is to them: the recipient id of their rows. */
final readonly class NotificationAccess
{
    public function __construct(
        private CurrentUserInterface $security,
        private CollaboratorProviderInterface $collaborators,
        public bool $enabled = false,
        private string $role = 'ROLE_ADMIN',
        private ?StreamGuardInterface $stream = null,
    ) {}

    /** Whether the pages hear of their notifications over the hub: without a guard, they do. */
    public function streams(): bool
    {
        return $this->stream?->streams() ?? true;
    }

    /** The signed-in user's id, when notifications are on and the user holds their role; null otherwise. */
    public function recipient(): ?int
    {
        if (!$this->enabled) {
            return null;
        }
        $user = $this->security->getUser();
        if (null === $user || !$this->security->isGranted($this->role)) {
            return null;
        }

        return $this->collaborators->id($user);
    }
}
