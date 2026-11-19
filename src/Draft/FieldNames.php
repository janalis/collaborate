<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

/**
 * The names a draft gives what its form holds beyond the form's own controls, which the scripts read too
 * (docs/internals/contract.md): a collection's keys `path[__collection__]`, each entry's membership `path[@key]`, the
 * membership of an entry added later `path[__member__]`, its fields `path[__name__]…`, and a rich-text field's source
 * mode `name[__mode__]`. Every bridge names them through here.
 */
final class FieldNames
{
    /** The placeholder of an entry's key in the fields of an entry added later. */
    public const string PLACEHOLDER = '__name__';

    private const string COLLECTION = '[__collection__]';

    private const string PROTOTYPE = '[__member__]';

    private const string MODE = '[__mode__]';

    /** The keys of a collection, in their order. */
    public static function collection(string $path): string
    {
        return $path . self::COLLECTION;
    }

    /** The collection whose keys a name holds, or null for another name. */
    public static function collectionPath(string $name): ?string
    {
        return str_ends_with($name, self::COLLECTION) ? substr($name, 0, -\strlen(self::COLLECTION)) : null;
    }

    /** Whether an entry of a collection is there, where, and which record it is. */
    public static function member(string $path, string $key): string
    {
        return $path . '[@' . $key . ']';
    }

    /** The membership of an entry added later. */
    public static function prototype(string $path): string
    {
        return $path . self::PROTOTYPE;
    }

    /** The fields of an entry added later sit below this. */
    public static function entryPrototype(string $path): string
    {
        return $path . '[' . self::PLACEHOLDER . ']';
    }

    /** @return array{string, string}|null the collection and key a membership names, or null for another name */
    public static function parseMember(string $name): ?array
    {
        $position = strrpos($name, '[@');

        return false === $position ? null : [substr($name, 0, $position), substr($name, $position + 2, -1)];
    }

    /** The entry a membership names (`items[@3]`: `items[3]`), or null for another name. */
    public static function entryOf(string $member): ?string
    {
        $parsed = self::parseMember($member);

        return null === $parsed ? null : $parsed[0] . '[' . $parsed[1] . ']';
    }

    /** A rich-text field's source mode: shared state, no value of the record. */
    public static function mode(string $name): string
    {
        return $name . self::MODE;
    }

    public static function isMode(string $name): bool
    {
        return str_ends_with($name, self::MODE);
    }

    /**
     * The entries a field may sit in: each prefix of its name that closes a bracket. A compound entry's fields sit
     * below it; a scalar entry's value is the entry itself.
     *
     * @return list<string>
     */
    public static function entryPrefixes(string $name): array
    {
        $prefixes = [];
        $prefix = '';
        foreach (explode(']', $name, -1) as $part) {
            $prefix .= $part . ']';
            $rest = substr($name, \strlen($prefix));
            if ('' === $rest || '[' === $rest[0]) {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }

    /**
     * The memberships that would remove the entries a field sits in: `items[3][title]` is in the entry `items[3]`,
     * which `items[@3]` removes. A prefix of no collection names no membership, and matches none.
     *
     * @return list<string>
     */
    public static function membersAround(string $name): array
    {
        // @infection-ignore-all: preg_replace() answers null on a regex error alone, and this regex is constant.
        return array_map(
            static fn(string $entry): string => (string) preg_replace('/\[([^[\]]*)]$/D', '[@$1]', $entry),
            self::entryPrefixes($name),
        );
    }
}
