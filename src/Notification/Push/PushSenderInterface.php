<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Push;

use Janalis\Collaborate\Notification\Entity\PushSubscription;

/** Sends one push to one browser: {@see WebPushSender} over minishlink/web-push, or the host's own. */
interface PushSenderInterface
{
    /** @param string|null $payload JSON the sender encrypts to the browser, or null for an empty push */
    public function send(PushSubscription $subscription, ?string $payload): PushResult;
}
