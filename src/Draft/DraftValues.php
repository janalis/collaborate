<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

/**
 * Field values compared as the journal stores them: as JSON, whose objects' keys are unordered, since MySQL
 * stores them sorted and MariaDB as written.
 *
 * @internal
 *
 * @phpstan-import-type Field from DraftJournal
 */
final class DraftValues
{
    /** Whether a record value differs from a field's saved baseline. A collection that stores no
     * order reloads in database order whatever order was submitted, so its order is left out.
     *
     * @param Field $field
     */
    public static function differs(array $field, mixed $value): bool
    {
        if (($field['unordered'] ?? false) && \is_array($field['saved']) && \is_array($value)) {
            return !self::same(
                self::withoutOrder($field['type'], $field['saved']),
                self::withoutOrder($field['type'], $value),
            );
        }

        return !self::same($field['saved'], $value);
    }

    /** Whether two values are equal as JSON: the keys of an object are unordered, since MySQL stores them
     * sorted and MariaDB as written.
     */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    /** A value with the keys of each object sorted, the order of a list kept. */
    public static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function withoutOrder(string $type, array $value): array
    {
        if ('collection' === $type) {
            sort($value);
        } else {
            unset($value['order']);
        }

        return $value;
    }
}
