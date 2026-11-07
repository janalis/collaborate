<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification;

/**
 * Whether the pages of the application may hear of their notifications over the hub: a host without a hub, or
 * without a key to sign its tokens with, says no, and a page in no place is then given no bare grant. The bell
 * still shows what was told before the page was loaded.
 */
interface StreamGuardInterface
{
    public function streams(): bool;
}
