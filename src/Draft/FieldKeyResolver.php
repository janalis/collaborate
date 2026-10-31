<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

/** Every field is shared by all locales: its key is its form name. */
final class FieldKeyResolver implements FieldKeyResolverInterface
{
    public function key(string $ownerClass, string $property, string $formName, bool $new): string
    {
        return $formName;
    }

    public function savedElsewhere(string $ownerClass, string $property, bool $new): bool
    {
        return false;
    }
}
