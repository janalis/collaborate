<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Integration;

use PHPUnit\Framework\Assert;

/** Typed reads of the decoded JSON the bundle answers. */
final class Arrays
{
    /**
     * @param array<array-key, mixed> $state
     *
     * @return array<string, mixed> the field `$name` of a draft view
     */
    public static function field(array $state, string $name): array
    {
        /** @var array<string, mixed> */
        return self::array(
            self::array($state['fields'] ?? null)[$name] ?? null,
            \sprintf('The draft has no field "%s".', $name),
        );
    }

    /** @return array<array-key, mixed> */
    public static function array(mixed $value, string $message = ''): array
    {
        Assert::assertIsArray($value, $message);

        return $value;
    }
}
