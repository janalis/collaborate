<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Server;

use Janalis\Collaborate\Hub\Server\Seconds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(Seconds::class)]
final class SecondsTest extends TestCase
{
    public function testReadsAClockToTheMicrosecond(): void
    {
        self::assertSame(1_767_225_600.25, Seconds::of(new MockClock('2026-01-01 00:00:00.250000 UTC')));
    }
}
