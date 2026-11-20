<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Updates;

use Symfony\Component\HttpFoundation\Request;

/**
 * What a save changed, kept on the main request for {@see UpdatesSubscriber} to announce once the response is
 * ready. A bridge records it here: Doctrine's change collector, Drupal's entity hooks, the locked save.
 */
final class SavedChanges
{
    private const string DATA = '_collaborate_data_changed';

    private const string RECORDS = '_collaborate_records_changed';

    private const string COMMITTED = '_collaborate_committed';

    /**
     * The saved views a save may alter, by the names their pages carry (`data-collaborate-page="<name>:index"`):
     * EasyAdmin's entity names, Drupal's entity types.
     */
    public static function data(Request $request, string ...$names): void
    {
        $request->attributes->set(self::DATA, self::strings([...$request->attributes->all(self::DATA), ...$names]));
    }

    /** The drafts of the records a save changed: their edit pages compare their form with the record again. */
    public static function records(Request $request, string ...$drafts): void
    {
        $request->attributes->set(self::RECORDS, self::strings([
            ...$request->attributes->all(self::RECORDS),
            ...$drafts,
        ]));
    }

    /** The draft whose own form the save went through: its editors hear it was saved. */
    public static function committed(Request $request, string $draft): void
    {
        $request->attributes->set(self::COMMITTED, $draft);
    }

    /** @return list<string> the names {@see data()} recorded, each once, in order; empty when it recorded none */
    public static function dataNames(Request $request): array
    {
        return self::strings($request->attributes->all(self::DATA));
    }

    /** Whether a save recorded saved views, none of them named included. */
    public static function hasData(Request $request): bool
    {
        return $request->attributes->has(self::DATA);
    }

    /** @return list<string> */
    public static function recordDrafts(Request $request): array
    {
        return self::strings($request->attributes->all(self::RECORDS));
    }

    public static function committedDraft(Request $request): ?string
    {
        return \is_string($request->attributes->get(self::COMMITTED))
            ? $request->attributes->getString(self::COMMITTED)
            : null;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];
        // @mago-expect analysis:mixed-assignment an attribute holds anything: the next line narrows it
        foreach ($values as $value) {
            if (\is_string($value)) {
                $strings[$value] = $value;
            }
        }

        return array_values($strings);
    }
}
