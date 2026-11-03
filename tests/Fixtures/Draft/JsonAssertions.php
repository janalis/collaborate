<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use PHPUnit\Framework\Assert;

/** Compares values read back from a JSON column: MySQL returns the keys of an object sorted, MariaDB as
 * written, and neither order means anything, while the order of a list does.
 */
trait JsonAssertions
{
    protected static function assertSameJson(mixed $expected, mixed $actual, string $message = ''): void
    {
        Assert::assertSame(self::sortedKeys($expected), self::sortedKeys($actual), $message);
    }

    private static function sortedKeys(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return array_map(self::sortedKeys(...), $value);
    }
}
