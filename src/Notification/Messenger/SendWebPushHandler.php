<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Messenger;

use Janalis\Collaborate\Notification\Push\PushDispatcher;

/** Sends the push a {@see SendWebPush} asks for, unless the browser or the notification is gone. */
final readonly class SendWebPushHandler
{
    public function __construct(
        private PushDispatcher $pushes,
    ) {}

    public function __invoke(SendWebPush $message): void
    {
        $this->pushes->sendById($message->subscription, $message->notification);
    }
}
