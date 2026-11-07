<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A user's choice for one topic of the notifications. Only a choice is stored: without a row, the topic's
 * configured default applies.
 */
#[ORM\Entity]
#[ORM\Table(name: 'collaborate_notification_preference')]
class NotificationPreference
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'recipient_id')]
        public private(set) int $recipient,
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        public private(set) string $topic,
        #[ORM\Column]
        public bool $subscribed,
        #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $updatedAt,
    ) {}
}
