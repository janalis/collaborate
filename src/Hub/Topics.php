<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub;

/** The Mercure topics of the bundle: one room per page, one per draft, one for saved views and one per user told of their notifications. */
final readonly class Topics
{
    public function __construct(
        private string $prefix = 'urn:collaborate:',
    ) {}

    /** @param string $room the HMAC of a page's identity */
    public function cursors(string $room): string
    {
        return $this->prefix . 'cursors:' . $room;
    }

    public function draft(string $id): string
    {
        return $this->prefix . 'draft:' . $id;
    }

    public function updates(): string
    {
        return $this->prefix . 'updates';
    }

    /** What every page of a user listens to for their notifications: private, granted to that user alone. */
    public function notifications(int $recipient): string
    {
        return $this->prefix . 'notifications:' . $recipient;
    }
}
