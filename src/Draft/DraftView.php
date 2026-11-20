<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

/**
 * What a form reads of a draft ({@see DraftJournal}'s View): the fields it may show, the batches it may replay, and
 * the fields whose record changed outside the draft. Also which collection entries a removed member stands for, which
 * the journal checks before it lets an edit in.
 *
 * @phpstan-import-type Field from DraftJournal
 * @phpstan-import-type Batch from DraftJournal
 * @phpstan-import-type Draft from DraftJournal
 * @phpstan-import-type View from DraftJournal
 *
 * @internal the journal's own
 */
final class DraftView
{
    /** @param Draft $draft
     * @param array{list<Batch>, list<int>} $journal the batches since the page's revision, and the generations
     *     compactions replaced ({@see JournalStore::events()})
     *
     * @return View
     */
    public static function of(array $draft, FieldDefinitions $allowed, array $journal, float $requestedAt): array
    {
        $fields = self::allowedFields($draft, $allowed);
        $clean = [] === array_filter($fields, self::unsaved(...));
        [$events, $rebasedFrom] = $journal;

        return [
            'generation' => $draft['generation'],
            'revision' => $draft['revision'],
            'savedRevision' => $clean ? $draft['revision'] : -1,
            'recordId' => $draft['recordId'],
            'savedBy' => $draft['savedBy'] ?? null,
            'conflicts' => self::conflicts($draft, $fields, $allowed, $requestedAt),
            'fields' => $fields,
            'digest' => hash('xxh128', json_encode(DraftValues::canonical($fields), \JSON_THROW_ON_ERROR)),
            'stamp' => $draft['updatedAt'],
            'events' => self::readable($events, $allowed),
            'rebasedFrom' => $rebasedFrom,
        ];
    }

    /** @param Field $field */
    public static function unsaved(array $field): bool
    {
        return $field['version'] !== ($field['savedVersion'] ?? null);
    }

    /** @param Draft $draft
     * @return array<string, Field>
     */
    public static function allowedFields(array $draft, FieldDefinitions $allowed): array
    {
        return array_filter(
            $draft['fields'],
            static fn(string $name): bool => null !== $allowed->definition($name),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    /** The fields whose record, as the grant issued at `$requestedAt` read it, differs from their saved baseline.
     * A removed collection entry's fields describe nothing the record holds.
     *
     * @param Draft                $draft
     * @param array<string, Field> $fields
     *
     * @return array<string, mixed>
     */
    public static function conflicts(array $draft, array $fields, FieldDefinitions $allowed, float $requestedAt): array
    {
        $conflicts = [];
        $removed = self::removedEntries($draft);
        foreach ($fields as $name => $field) {
            $definition = $allowed->definition($name);
            if (
                null !== $definition
                && $requestedAt > ($field['savedAt'] ?? 0.0)
                && DraftValues::differs($field, $definition['initial'])
                && !self::removed($removed, $field['formName'] ?? $name)
            ) {
                $conflicts[$name] = $definition['initial'];
            }
        }

        return $conflicts;
    }

    /**
     * The collection entries a removed member stands for, as a set: rows grow with a draft, so a name is
     * looked up by its own prefixes instead of every row being scanned for each name.
     *
     * @param array{fields: array<string, Field>, ...} $draft
     *
     * @return array<string, string> entry => the member that removed it
     */
    public static function removedEntries(array $draft): array
    {
        $entries = [];
        foreach ($draft['fields'] as $memberName => $member) {
            if (
                'member' !== $member['type']
                || !\is_array($member['value'])
                || true === ($member['value']['alive'] ?? false)
            ) {
                continue;
            }
            $entry = FieldNames::entryOf($memberName);
            if (null === $entry) {
                continue;
            }
            $entries[$entry] = $memberName;
        }

        return $entries;
    }

    /** @param array<string, string> $entries {@see removedEntries()} */
    public static function removed(array $entries, string $name): bool
    {
        foreach (FieldNames::entryPrefixes($name) as $entry) {
            if (isset($entries[$entry])) {
                return true;
            }
        }

        return false;
    }

    /** The events a form may read: its own fields' batches, and the Discards that reset one of its fields,
     * naming those only.
     *
     * @param list<Batch> $events
     *
     * @return list<Batch>
     */
    private static function readable(array $events, FieldDefinitions $allowed): array
    {
        $readable = [];
        foreach ($events as $event) {
            if ('discard' === $event['kind']) {
                $event['fields'] = array_values(array_filter(
                    $event['fields'] ?? [],
                    static fn(string $field): bool => null !== $allowed->definition($field),
                ));
                if ([] === $event['fields']) {
                    continue;
                }
            } elseif (null === $allowed->definition($event['field'])) {
                continue;
            }
            $readable[] = $event;
        }

        return $readable;
    }
}
