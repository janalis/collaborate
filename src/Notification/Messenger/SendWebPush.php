<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Messenger;

/** Push one notification to one browser, through a Messenger bus: both are read again when it is handled. */
final readonly class SendWebPush
{
    public function __construct(
        public int $subscription,
        public int $notification,
    ) {}
}
