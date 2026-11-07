<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Push;

/** What a push service answered. */
enum PushResult
{
    case Sent;

    /** The browser unsubscribed (404, 410): its subscription is removed. */
    case Expired;

    case Failed;
}
