<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures;

use PHPUnit\Framework\Assert;

/** Reads typed arrays out of decoded JSON and processed configuration, failing the test on any other shape. */
final class Arrays
{
    /** @return array<array-key, mixed> the array at `$path` inside `$value` */
    public static function at(mixed $value, int|string ...$path): array
    {
        foreach ($path as $key) {
            Assert::assertIsArray($value);
            Assert::assertArrayHasKey($key, $value);
            /** @var mixed $value */
            $value = $value[$key];
        }
        Assert::assertIsArray($value);

        return $value;
    }

    /** @return array<array-key, mixed> */
    public static function json(string $json): array
    {
        return self::at(json_decode($json, true, flags: \JSON_THROW_ON_ERROR));
    }
}
