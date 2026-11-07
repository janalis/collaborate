<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Messenger;

use Janalis\Collaborate\Notification\Notifier;

/** Writes the notification a {@see SendNotification} asks for, once whatever its deliveries. */
final readonly class SendNotificationHandler
{
    public function __construct(
        private Notifier $notifier,
    ) {}

    public function __invoke(SendNotification $message): void
    {
        $this->notifier->write(
            $message->recipient,
            $message->type,
            $message->parameters,
            $message->url,
            new \DateTimeImmutable($message->at),
            $message->key,
        );
    }
}
