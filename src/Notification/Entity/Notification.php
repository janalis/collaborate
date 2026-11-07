<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Something a user is told: listed under the bell until it is read, and after. The recipient is the id
 * {@see \Janalis\Collaborate\Identity\CollaboratorProviderInterface::id()} gives, with no foreign key: the
 * application may add one to its user table in its migration.
 */
#[ORM\Entity]
#[ORM\Table(name: 'collaborate_notification')]
#[ORM\Index(name: 'collaborate_notification_latest', columns: ['recipient_id', 'created_at'])]
#[ORM\Index(name: 'collaborate_notification_cursor', columns: ['recipient_id', 'id'])]
#[ORM\UniqueConstraint(name: 'collaborate_notification_message', columns: ['message_key'])]
class Notification
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public private(set) ?int $id = null;

    #[ORM\Column(name: 'read_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $readAt = null;

    /**
     * @param string                $topic      the topic of its type when it was written: what its reader subscribed to
     * @param array<string, string> $parameters the values of the placeholders of the sentence of its type
     * @param string|null           $url        where a click on it leads
     * @param string|null           $messageKey the key of the message that wrote it, so that a redelivery writes nothing
     */
    public function __construct(
        #[ORM\Column(name: 'recipient_id')]
        public private(set) int $recipient,
        #[ORM\Column(length: 64)]
        public private(set) string $topic,
        #[ORM\Column(length: 64)]
        public private(set) string $type,
        #[ORM\Column(type: 'json')]
        public private(set) array $parameters,
        #[ORM\Column(length: 2048, nullable: true)]
        public private(set) ?string $url,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public private(set) \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'message_key', length: 36, nullable: true, options: ['fixed' => true])]
        public private(set) ?string $messageKey = null,
    ) {}

    public function isUnread(): bool
    {
        return null === $this->readAt;
    }
}
