<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

/** The string an edit page addresses a record by, whatever type its identifier column maps to. */
final class RecordId
{
    /** An integer, a string or a Stringable identifier (a UUID or ULID `Uid`) as a string; null for any other value. */
    public static function string(mixed $id): ?string
    {
        return \is_int($id) || \is_string($id) || $id instanceof \Stringable ? (string) $id : null;
    }
}
