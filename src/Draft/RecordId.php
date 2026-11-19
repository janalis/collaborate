<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

/** The string an edit page addresses a record by, whatever type its identifier column maps to. */
final class RecordId
{
    /**
     * An integer, a string or a Stringable identifier (a UUID or ULID `Uid`, a value object) as a string; null
     * for any other value. A caller that may meet an entity as a key (a derived identity) refuses it first: its
     * `__toString()` is a label, not an id.
     */
    public static function string(mixed $id): ?string
    {
        return \is_int($id) || \is_string($id) || $id instanceof \Stringable ? (string) $id : null;
    }
}
