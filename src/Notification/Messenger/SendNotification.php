<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Messenger;

/**
 * Notify one user, through a Messenger bus: scalars alone, so that any serializer and transport carries
 * it. Its key makes its handling idempotent: a redelivered message writes no second notification.
 */
final readonly class SendNotification
{
    /**
     * @param array<string, string> $parameters
     * @param string                $at         when it was sent (ATOM), which the notification is dated by
     */
    public function __construct(
        public int $recipient,
        public string $type,
        public array $parameters,
        public ?string $url,
        public string $at,
        public string $key,
    ) {}
}
