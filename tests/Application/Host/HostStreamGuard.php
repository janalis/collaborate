<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Host;

use Janalis\Collaborate\Notification\StreamGuardInterface;

/** Whether the library has a hub its pages hear of their notifications on: a test turns it off. */
final class HostStreamGuard implements StreamGuardInterface
{
    public static bool $streams = true;

    public function streams(): bool
    {
        return self::$streams;
    }
}
