<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Server;

use Janalis\Collaborate\Hub\Server\Counter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Counter::class)]
final class CounterTest extends TestCase
{
    public function testCountsPerKeyAndForgetsEmptyKeys(): void
    {
        $counter = new Counter();
        self::assertSame(0, $counter->count('192.0.2.1'));

        $counter->add('192.0.2.1');
        $counter->add('192.0.2.1');
        $counter->add(7);
        self::assertSame(2, $counter->count('192.0.2.1'));
        self::assertSame(1, $counter->count(7));
        self::assertSame(2, $counter->keys());

        $counter->remove('192.0.2.1');
        self::assertSame(1, $counter->count('192.0.2.1'));
        self::assertSame(2, $counter->keys());
        $counter->remove('192.0.2.1');
        self::assertSame(0, $counter->count('192.0.2.1'));
        self::assertSame(1, $counter->keys());
    }
}
