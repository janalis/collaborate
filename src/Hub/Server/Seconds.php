<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

use Psr\Clock\ClockInterface;

/** The time of a clock in seconds, to the microsecond: what the server's deadlines compare. */
final class Seconds
{
    public static function of(ClockInterface $clock): float
    {
        // @mago-expect analysis:invalid-type-cast 'U.u' formats a number
        return (float) $clock->now()->format('U.u');
    }
}
