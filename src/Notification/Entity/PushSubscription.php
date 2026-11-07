<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The web push subscription of one browser: where its push service is reached, and the keys a payload is
 * encrypted with. One device is one row, whoever signed in on it last.
 */
#[ORM\Entity]
#[ORM\Table(name: 'collaborate_push_subscription')]
#[ORM\Index(name: 'collaborate_push_subscription_recipient', columns: ['recipient_id'])]
#[ORM\UniqueConstraint(name: 'collaborate_push_subscription_endpoint', columns: ['endpoint_hash'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public private(set) ?int $id = null;

    /** The SHA-256 of the endpoint: an endpoint is too long a key for a unique index. */
    #[ORM\Column(name: 'endpoint_hash', length: 64, options: ['fixed' => true])]
    public private(set) string $endpointHash;

    #[ORM\Column(name: 'last_used_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(
        #[ORM\Column(name: 'recipient_id')]
        public int $recipient,
        #[ORM\Column(type: 'text')]
        public private(set) string $endpoint,
        #[ORM\Column(length: 255)]
        public string $p256dh,
        #[ORM\Column(length: 255)]
        public string $auth,
        #[ORM\Column(length: 16)]
        public string $encoding,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->endpointHash = self::hash($endpoint);
    }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
