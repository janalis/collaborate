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
        $fields = $state['fields'] ?? null;
        Assert::assertIsArray($fields);
        $field = $fields[$name] ?? null;
        Assert::assertIsArray($field, \sprintf('The draft has no field "%s".', $name));

        /** @var array<string, mixed> */
        return $field;
    }
}
