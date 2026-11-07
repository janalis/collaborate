<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification;

/** A user tried to unsubscribe from (or choose for) a topic every user is subscribed to. */
final class MandatoryTopicException extends \DomainException
{
    public function __construct(
        public readonly string $topic,
    ) {
        parent::__construct(\sprintf('Every user is subscribed to the notification topic "%s".', $topic));
    }
}
