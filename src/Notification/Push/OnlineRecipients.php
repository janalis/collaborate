<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Push;

use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Hub\Transport\PresenceProbeInterface;

/**
 * Whether a page of a user holds the stream of their notifications, as the hub says: such a page was told
 * through the hub, a push would only be dropped by the service worker. A hub that does not answer reads as
 * nobody online: the push is sent, and the service worker still shows it in a page when one is open.
 */
final readonly class OnlineRecipients
{
    public function __construct(
        private PresenceProbeInterface $presence,
        private Topics $topics,
        private bool $enabled = false,
    ) {}

    public function isOnline(int $recipient): bool
    {
        return $this->enabled && $this->presence->listening($this->topics->notifications($recipient));
    }
}
