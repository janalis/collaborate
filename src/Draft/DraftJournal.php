<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * PHP orders opaque editor operations; CKEditor performs their model-level transformations.
 *
 * @phpstan-type SchemaField array{type: string, initial: mixed, schema?: string, formName?: string, unordered?: bool}
 * @phpstan-type Field array{type: string, initial: mixed, version: int|null, value: mixed, saved: mixed, initialVersion?: int, schema?: string, formName?: string, unordered?: bool, savedVersion?: int|null, savedAt?: float, graveyardLength?: int, discardedAt?: int}
 * @phpstan-type Batch array{id: string, field: string, kind: string, version: int, revision: int, userId: int, label: string, operations?: list<array<string, mixed>>, value?: mixed, labels?: array<array-key, string>, fields?: list<string>}
 * @phpstan-type Actor array{userId: int, label: string}
 * @phpstan-type Draft array{id: string, generation: int, revision: int, savedRevision: int, bytes: int, recordId: string|null, updatedAt: string, fields: array<string, Field>, savedBy?: Actor|null}
 * @phpstan-type View array{generation: int, revision: int, savedRevision: int, recordId: string|null, savedBy: Actor|null, fields: array<string, Field>, digest: string, stamp: string, events: list<Batch>, conflicts: array<string, mixed>, rebasedFrom: list<int>}
 */
class DraftJournal implements ResetInterface
{
    /** The conflict a second creation answers, also the key of the warning its form shows. */
    public const string ALREADY_CREATED = 'This draft has already created its record. Open the saved record instead.';

    /** The largest update, in stored UTF-8 bytes. */
    private int $maxUpdateBytes;

    /** The largest journal of one draft, in stored UTF-8 bytes. */
    private int $maxDraftBytes;

    /** Field rows of one draft: a Discard or a compaction empties the journal and keeps them. */
    private int $maxFields;

    /** The longest label a choice event names for one of its ids, and the longest name a draft keeps of its saver. */
    private int $labelLength;

    /** Checks what an event brings to a field against the nesting and label limits. */
    private EventValidator $validator;

    /** Journal bytes past which Join compacts a saved, idle draft. */
    private int $compactBytes;

    /** Field rows past which Join compacts a draft whose removed collection entries it can drop. */
    private int $compactFields;

    /** A page sends its edits within a second: a draft nobody wrote to for this long holds none unsent. */
    private int $compactIdle;

    /** Seconds a Save waits for another Save. */
    private int $lockSeconds;

    /** Appends take milliseconds: a writer other than Save that waits longer is behind a save, and backs off instead of holding a worker. */
    private int $writerLockSeconds;

    private int $cleanDays;

    private int $unsavedDays;

    private JournalStore $store;

    private DraftLocks $locks;

    /**
     * @param string $systemLabel the name of the writes no editor made (a compaction, a reconciliation)
     * @param array{max_update_bytes?: int, max_draft_bytes?: int, max_fields?: int, max_value_depth?: int, label_length?: int, compact?: array{bytes?: int, fields?: int, idle?: int, ...}, locks?: array{save?: int, writer?: int, ...}, purge?: array{clean_days?: int, unsaved_days?: int, ...}, ...} $limits
     *     the `collaborate.drafts` configuration
     * @param int|null $firstGeneration the generation of every new draft instead of a random one
     */
    public function __construct(
        Connection $connection,
        private readonly DraftText $text = new DraftText(),
        private readonly string $systemLabel = 'System',
        array $limits = [],
        private readonly ?int $firstGeneration = null,
    ) {
        $this->maxUpdateBytes = $limits['max_update_bytes'] ?? 262_144;
        $this->maxDraftBytes = $limits['max_draft_bytes'] ?? 10_485_760;
        $this->maxFields = $limits['max_fields'] ?? 10_000;
        $this->labelLength = $limits['label_length'] ?? 255;
        $this->validator = new EventValidator($limits['max_value_depth'] ?? 8, $this->labelLength);
        $this->compactBytes = $limits['compact']['bytes'] ?? 1_048_576;
        $this->compactFields = $limits['compact']['fields'] ?? 5_000;
        $this->compactIdle = $limits['compact']['idle'] ?? 60;
        $this->lockSeconds = $limits['locks']['save'] ?? 10;
        $this->writerLockSeconds = $limits['locks']['writer'] ?? 1;
        $this->cleanDays = $limits['purge']['clean_days'] ?? 7;
        $this->unsavedDays = $limits['purge']['unsaved_days'] ?? 90;
        $this->store = new JournalStore($connection);
        $this->locks = new DraftLocks($connection, $this->store->dialect(...), $this->lockSeconds);
    }

    /** @param array<string, SchemaField> $fields
     * @return View
     */
    public function join(string $id, array $fields, float $requestedAt = 0.0): array
    {
        $definitions = new FieldDefinitions($fields);
        $this->exclusive($id, function () use ($id, $fields, $definitions): void {
            // A draft recreated after purge() must not reuse a generation a page left open still holds:
            // that page would take the new revisions for ones it has seen. Half the INT range leaves room for ++.
            $this->store->create($id, $this->firstGeneration ?? $this->randomGeneration());
            $draft = $this->store->read($id, true);
            $compacted = false;
            // Rebasing breaks every open editor's model-version chain: peers on the old
            // generation stop, and a page without unsent edits reloads. Only an idle draft
            // without unsaved changes is compacted, so the server loses no edit and no open
            // page loses one it had not sent yet (the saver's own reload follows its save).
            // A draft past COMPACT_FIELDS is compacted only to drop rows: Discard and Save keep them.
            $large = $draft['bytes'] > $this->compactBytes;
            $quietSince = JournalStore::now('-' . $this->compactIdle . ' seconds');
            // @infection-ignore-all: < and <= differ only when the last write falls on this very microsecond.
            $idle = $draft['updatedAt'] < $quietSince;
            if (
                ($large || \count($draft['fields']) > $this->compactFields)
                && $idle
                && null === $draft['recordId']
                && [] === array_filter($draft['fields'], DraftView::unsaved(...))
            ) {
                $rebased = array_map($this->rebase(...), $draft['fields']);
                $removed = DraftView::removedEntries(['fields' => $rebased] + $draft);
                // The old generation can no longer address a removed collection entry: drop it.
                $kept = array_filter(
                    $rebased,
                    static fn(array $field, string $name): bool => (
                        !DraftView::removed($removed, $field['formName'] ?? $name)
                        && !('member' === $field['type'] && false === ($field['value']['alive'] ?? null))
                    ),
                    \ARRAY_FILTER_USE_BOTH,
                );
                if ($large || \count($kept) < \count($draft['fields'])) {
                    // A page still on a replaced generation finds it in the journal: with nothing unsent, it lost nothing.
                    $this->store->rebase($id, $draft['revision'] + 1, $draft['generation']);
                    ++$draft['generation'];
                    ++$draft['revision'];
                    $draft['savedRevision'] = $draft['revision'];
                    $draft['bytes'] = 0;
                    $draft['fields'] = $kept;
                    $compacted = true;
                }
            }
            $added = [];
            foreach ($fields as $name => $field) {
                if (FieldDefinitions::prototype($name)) {
                    continue;
                }
                if (!isset($draft['fields'][$name])) {
                    $added[] = $name;
                    $draft['fields'][$name] = $this->fresh($field);
                }
            }
            $adopted = [];
            foreach ($draft['fields'] as $name => &$state) {
                $definition = $definitions->definition($name);
                if (null !== $definition && ($state['schema'] ?? null) !== ($definition['schema'] ?? null)) {
                    if (DraftView::unsaved($state)) {
                        throw new DraftConflictException(
                            'Collaborate schema changed. Save or discard with a compatible editor before upgrading this draft.',
                        );
                    }
                    $adopted[] = $name;
                    unset($state['schema'], $state['initialVersion'], $state['graveyardLength']);
                    $state['version'] = null;
                    $state['savedVersion'] = null;
                    $state['type'] = $definition['type'];
                    $state['initial'] = $definition['initial'];
                    $state['saved'] = $definition['initial'];
                    $state['value'] = $definition['initial'];
                    $state['savedAt'] = microtime(true);
                    if (isset($definition['formName'])) {
                        $state['formName'] = $definition['formName'];
                    }
                    if (isset($definition['schema'])) {
                        $state['schema'] = $definition['schema'];
                    }
                }
            }
            unset($state);
            // The adopted value already contains the old schema's operations.
            if ([] !== $adopted) {
                $this->store->deleteEvents($id, $adopted);
                $draft['bytes'] = $this->store->size($id);
            }
            // Opening a page writes nothing: open peers keep their replay stamp, and a merely viewed draft still idles.
            if ($compacted || [] !== $added || [] !== $adopted) {
                $this->store->write($id, $draft, $compacted ? null : [...$added, ...$adopted]);
            }
        });

        // The view is read after the lock is released: decoding a long journal must not hold appends off past
        // their wait. One snapshot, as in replay(), so that no event is newer than the revision it carries.
        // @infection-ignore-all: revisions start at 1, so the events after revision 0 and after -1 are the same.
        return $this->store->db()->transactional(fn(): array => $this->visible(
            $this->store->read($id),
            $definitions,
            0,
            $requestedAt,
        ));
    }

    /** A page polls every few seconds: a draft nobody wrote to since the row it last read, named by `$stamp`,
     * needs no replay. Every write moves updated_at; a page that takes a new grant sends no stamp, since its
     * conflicts compare the draft with that grant's record.
     */
    public function unchanged(string $id, int $since, string $stamp): bool
    {
        $row = $this->store->stamp($id);

        return null !== $row && $stamp === $row['stamp'] && $since === $row['revision'];
    }

    /** Field states the client already holds, named by `$digest`, are not sent again.
     *
     * @param array<string, SchemaField> $allowed
     *
     * @return View
     */
    public function replay(
        string $id,
        array $allowed,
        int $since,
        float $requestedAt = 0.0,
        ?string $digest = null,
    ): array {
        // One transaction, one snapshot: an append committed between the two reads would
        // otherwise return events newer than the revision, and the client would replay them twice.
        $view = $this->store->db()->transactional(fn(): array => $this->visible(
            $this->store->read($id),
            new FieldDefinitions($allowed),
            $since,
            $requestedAt,
        ));
        if ($digest === $view['digest']) {
            $view['fields'] = [];
        }

        return $view;
    }

    /** @param array<string, SchemaField> $allowed
     * @param array<string, mixed> $event
     *
     * @return Batch
     */
    public function append(string $id, array $allowed, array $event, int $userId, string $label): array
    {
        // Limits count stored UTF-8 bytes, not \uXXXX escapes.
        $size = \strlen(JournalStore::encode($event));
        if ($size > $this->maxUpdateBytes) {
            throw new DraftTooLargeException('Collaborate update is too large.');
        }
        $field = self::stringOrNull($event['field'] ?? null);
        if (null === $field) {
            throw new InvalidDraftEventException('Invalid Collaborate event.');
        }
        $definition = FieldDefinitions::prototype($field) ? null : new FieldDefinitions($allowed)->definition($field);
        if (null === $definition) {
            throw new InvalidDraftEventException(\sprintf('Collaborate field "%s" is not in this form.', $field));
        }
        if (
            !\is_string($event['id'] ?? null)
            || !\is_int($event['generation'] ?? null)
            || !\is_int($event['version'] ?? null)
            || !\is_int($event['seen'] ?? null)
            || !preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $event['id'])
        ) {
            throw new InvalidDraftEventException('Invalid Collaborate event.');
        }

        return $this->exclusive($id, function () use ($id, $event, $field, $size, $userId, $label, $definition): array {
            $draft = $this->store->read($id, true, [
                $field,
                ...FieldNames::membersAround($definition['formName'] ?? $field),
            ]);
            // A retried batch whose response was lost gets its stored result, whatever happened to the draft since.
            $accepted = $this->store->batch($id, $event['id']);
            if (null !== $accepted) {
                if ($accepted['userId'] !== $userId || $accepted['field'] !== $field) {
                    throw new DraftConflictException('Collaborate batch identifier is already used.');
                }

                return $accepted;
            }
            if (null !== $draft['recordId']) {
                throw new DraftConflictException(
                    'This new-record draft is already saved. Continue on the saved record.',
                );
            }
            if ($event['generation'] !== $draft['generation']) {
                throw new DraftConflictException('Collaborate draft was discarded.');
            }
            if (DraftView::removed(DraftView::removedEntries($draft), $definition['formName'] ?? $field)) {
                throw new DraftConflictException('The collection entry was removed.');
            }
            if (!isset($draft['fields'][$field])) {
                // Only an entry added to a collection brings a new field here: Join stored every other one.
                if ($this->store->fieldCount($id) >= $this->maxFields) {
                    throw new DraftTooLargeException('Collaborate draft is full. Save or discard before continuing.');
                }
                $draft['fields'][$field] = $this->fresh($definition);
            }
            $state = &$draft['fields'][$field];
            // The page edited the field as it stood before a Discard it has not replayed yet.
            if (($state['discardedAt'] ?? 0) > $event['seen']) {
                throw new DraftConflictException('Collaborate field was discarded.');
            }
            if (($state['schema'] ?? null) !== ($definition['schema'] ?? null)) {
                throw new DraftConflictException('Collaborate schema changed.');
            }
            $version = $state['version'];
            if (null === $version) {
                $version = $event['version'];
                if ($version < 0 || $version > 100_000) {
                    throw new InvalidDraftEventException('Invalid initial model version.');
                }
                $state['initialVersion'] = $version;
            }
            if ($event['version'] !== $version) {
                throw new DraftConflictException('Collaborate model revision changed.');
            }
            if ('operations' === ($event['kind'] ?? null) && \in_array($state['type'], ['text', 'rich'], true)) {
                $operations = $this->validator->operations($event['operations'] ?? null, $version);
                if ('text' === $state['type']) {
                    $result = $this->text->apply((string) $state['value'], $state['graveyardLength'] ?? 0, $operations);
                    $state['value'] = $result['text'];
                    $state['graveyardLength'] = $result['graveyard'];
                }
                $payload = ['operations' => $operations];
                $state['version'] = $version + \count($operations);
            } elseif (
                'value' === ($event['kind'] ?? null)
                && \array_key_exists('value', $event)
                && \in_array($state['type'], ['value', 'member'], true)
            ) {
                $payload = $this->validator->value($event, $state['type']);
                if ('member' === $state['type']) {
                    // A removed entry is a tombstone; a concurrent reorder cannot resurrect it.
                    if (
                        \is_array($state['value'])
                        && false === ($state['value']['alive'] ?? null)
                        && null !== $state['version']
                    ) {
                        $payload['value'] = $state['value'];
                    }
                }
                $state['value'] = $payload['value'];
                $state['version'] = $version + 1;
            } else {
                throw new InvalidDraftEventException('Unknown Collaborate event kind.');
            }
            $clean = $draft['savedRevision'] === $draft['revision'];
            /** @var Batch $accepted */
            $accepted = $payload
            + [
                'id' => $event['id'],
                'field' => $field,
                'kind' => $event['kind'],
                'version' => $version,
                'revision' => ++$draft['revision'],
                'userId' => $userId,
                'label' => $label,
            ];
            // The journal grows by the batch it stores, with its revision and author: not by what the page sent.
            $stored = JournalStore::encode($accepted);
            if (($draft['bytes'] + \strlen($stored)) > $this->maxDraftBytes) {
                throw new DraftTooLargeException('Collaborate draft is full. Save or discard before continuing.');
            }
            // Source mode is shared UI state, not a persisted form value: switching it leaves the draft saved.
            if (FieldNames::isMode($field)) {
                $state['savedVersion'] = $state['version'];
                if ($clean) {
                    $draft['savedRevision'] = $draft['revision'];
                }
            }
            $this->store->insert($id, $accepted, $stored);
            $draft['bytes'] += \strlen($stored);
            $this->store->write($id, $draft, [$field]);

            return $accepted;
        });
    }

    /** Caller holds {@see lock()} across Symfony validation and editorial persistence.
     * @param array<string, mixed> $current
     */
    public function lockSave(string $id, int $generation, int $revision, array $current, bool $new = false): void
    {
        $draft = $this->store->read($id);
        if ($new && null !== $draft['recordId']) {
            throw new RecordAlreadyCreatedException();
        }
        if ($generation !== $draft['generation'] || $revision !== $draft['revision']) {
            throw new DraftConflictException('The shared draft changed. Synchronize before saving.');
        }
        foreach (array_keys($current) as $name) {
            if (isset($draft['fields'][$name]) && DraftValues::differs($draft['fields'][$name], $current[$name])) {
                throw new DraftConflictException(
                    'The saved record changed outside this draft. Reconcile before saving.',
                );
            }
        }
    }

    /** @param array<string, mixed> $values
     * @param Actor|null $actor the editor whose Save this is
     */
    public function saved(string $id, array $values, ?array $actor = null): void
    {
        $this->guarded($id, fn() => $this->checkpoint($id, $values, $actor));
    }

    /** @param array<string, mixed> $values
     * @param Actor|null $actor
     */
    private function checkpoint(string $id, array $values, ?array $actor): void
    {
        $draft = $this->store->read($id, true);
        $draft['savedBy'] = null === $actor
            ? null
            : ['userId' => $actor['userId'], 'label' => mb_substr($actor['label'], 0, $this->labelLength)];
        foreach (array_keys($values) as $name) {
            if (!isset($draft['fields'][$name])) {
                continue;
            }

            $field = &$draft['fields'][$name];
            if ('text' === $field['type'] && \is_string($values[$name]) && $field['value'] !== $values[$name]) {
                $version = $field['version'] ?? ('' === $field['initial'] ? 1 : 2);
                $operations = $this->text->diff((string) $field['value'], $values[$name], $version);
                $result = $this->text->apply((string) $field['value'], $field['graveyardLength'] ?? 0, $operations);
                $field['initialVersion'] ??= $version;
                $field['version'] = $version + \count($operations);
                $field['value'] = $result['text'];
                $field['graveyardLength'] = $result['graveyard'];
                $draft['bytes'] += $this->store->record($id, [
                    'id' => $this->batchId(),
                    'field' => $name,
                    'kind' => 'operations',
                    'version' => $version,
                    'revision' => ++$draft['revision'],
                    'userId' => 0,
                    'label' => $this->systemLabel,
                    'operations' => $operations,
                ]);
            } elseif (
                'value' === $field['type']
                && !DraftValues::same($field['value'], $values[$name])
                && !FieldNames::isMode($name)
            ) {
                $version = $field['version'] ?? 0;
                $field['value'] = $values[$name];
                $field['version'] = $version + 1;
                $draft['bytes'] += $this->store->record($id, [
                    'id' => $this->batchId(),
                    'field' => $name,
                    'kind' => 'value',
                    'version' => $version,
                    'revision' => ++$draft['revision'],
                    'userId' => 0,
                    'label' => $this->systemLabel,
                    'value' => $values[$name],
                ]);
            }
            unset($field);
            $draft['fields'][$name]['saved'] = $values[$name];
            $draft['fields'][$name]['savedVersion'] = $draft['fields'][$name]['version'];
            $draft['fields'][$name]['savedAt'] = microtime(true);
        }
        $removed = DraftView::removedEntries($draft);
        foreach ($draft['fields'] as $name => &$state) {
            if ('member' === $state['type'] && !\array_key_exists($name, $values)) {
                $member = FieldNames::parseMember($name);
                if (null !== $member && \array_key_exists(FieldNames::collection($member[0]), $values)) {
                    $state['saved'] = ['alive' => false, 'order' => 0, 'recordId' => null];
                    $state['savedVersion'] = $state['version'];
                }
            }
            if (DraftView::removed($removed, $state['formName'] ?? $name)) {
                $state['savedVersion'] = $state['version'];
            }
        }
        unset($state);
        $this->settle($draft);
        $this->store->write($id, $draft);
    }

    public function created(string $id, string $recordId): void
    {
        $this->guarded($id, function () use ($id, $recordId): void {
            $draft = $this->store->read($id, true);
            $draft['recordId'] = $recordId;
            $this->store->write($id, $draft, []);
        });
    }

    /** An explicit editorial choice updates the baseline without silently overwriting a draft.
     * @param array<string, SchemaField> $allowed
     *
     * @return View
     */
    public function reconcile(
        string $id,
        int $generation,
        int $revision,
        array $allowed,
        bool $keep,
        float $requestedAt = 0.0,
        int $userId = 0,
        ?string $label = null,
    ): array {
        return $this->exclusive($id, function () use (
            $id,
            $generation,
            $revision,
            $allowed,
            $keep,
            $requestedAt,
            $userId,
            $label,
        ): array {
            $draft = $this->store->read($id, true);
            if ($generation !== $draft['generation'] || $revision !== $draft['revision']) {
                throw new DraftConflictException('The shared draft changed. Review it before reconciling.');
            }
            $definitions = new FieldDefinitions($allowed);
            $conflicts = DraftView::conflicts(
                $draft,
                DraftView::allowedFields($draft, $definitions),
                $definitions,
                $requestedAt,
            );
            foreach (array_keys($conflicts) as $name) {
                // @infection-ignore-all: any negative version marks the field unsaved, since no version is negative.
                $draft['fields'][$name]['savedVersion'] = -1;
                $draft['fields'][$name]['saved'] = $conflicts[$name];
                $draft['fields'][$name]['savedAt'] = microtime(true);
            }
            // The kept draft now differs from the record: purge() must see it unsaved. The record was
            // saved outside the draft, so no editor's Save holds it any more.
            if ([] !== $conflicts) {
                // @infection-ignore-all: any negative revision marks the draft unsaved, since no revision is negative.
                $draft['savedRevision'] = -1;
                $draft['savedBy'] = null;
            }
            $this->store->write($id, $draft, array_keys($conflicts));

            if (!$keep) {
                return $this->discard($id, $generation, $revision, $allowed, $userId, $label);
            }

            // @infection-ignore-all: revisions start at 1, so the events after revision 0 and after -1 are the same.
            return $this->visible($draft, $definitions, 0);
        });
    }

    /** A plain list stores no key: its entries reopen under the keys of the order its last save recorded.
     *
     * @return array<string, list<string>> collection path => saved entry keys
     */
    public function listKeys(string $id): array
    {
        $keys = [];
        foreach ($this->store->fieldsOfType($id, 'collection') as $name => $field) {
            $path = FieldNames::collectionPath($name);
            if (null === $path || !\is_array($field['saved']) || !array_is_list($field['saved'])) {
                continue;
            }

            $keys[$path] = array_map(strval(...), $field['saved']);
        }

        return $keys;
    }

    /** Stable form keys survive a new collection entry receiving its database identifier.
     * @return array<string, array<string, string>>
     */
    public function aliases(string $id): array
    {
        // A page read before a save under way checkpoints its aliases would key an entry the save created by its
        // record, beside the draft's own key for it, and show it twice.
        $this->locks->waitForSave($id);
        $aliases = [];
        foreach ($this->store->fieldsOfType($id, 'member') as $name => $field) {
            if (!\is_array($field['saved']) || !\is_scalar($field['saved']['recordId'] ?? null)) {
                continue;
            }
            $member = FieldNames::parseMember($name);
            if (null !== $member) {
                $aliases[$member[0]][(string) $field['saved']['recordId']] = $member[1];
            }
        }

        return $aliases;
    }

    /** Resets the fields of one form that hold edits, keeping the generation: a page sharing none of them,
     * such as another locale's, keeps its unsent edits. The journal names the reset fields, so the pages
     * that show one rejoin, and an append made before a page replayed the reset is refused.
     *
     * @param array<string, SchemaField> $allowed
     *
     * @return View
     */
    public function discard(
        string $id,
        int $generation,
        int $revision,
        array $allowed,
        int $userId = 0,
        ?string $label = null,
    ): array {
        return $this->exclusive($id, function () use ($id, $generation, $revision, $allowed, $userId, $label): array {
            $draft = $this->store->read($id, true);
            if ($generation !== $draft['generation'] || $revision !== $draft['revision']) {
                throw new DraftConflictException('The shared draft changed. Synchronize before discarding.');
            }
            $definitions = new FieldDefinitions($allowed);
            // One entry per field, not per event: a long journal holds thousands of batches. A field kept
            // against a saved record holds no edit yet differs from it.
            $journalled = $this->store->journalledFields($id);
            $discarded = array_values(array_filter(
                array_unique([
                    ...$journalled,
                    ...array_keys(array_filter($draft['fields'], DraftView::unsaved(...))),
                ]),
                // Every journalled field has its state row: append and checkpoint write it first.
                static fn(string $field): bool => (
                    isset($draft['fields'][$field])
                    && null !== $definitions->definition($field)
                ),
            ));
            if ([] === $discarded) {
                // @infection-ignore-all: revisions start at 1, so the events after revision 0 and after -1 are the same.
                return $this->visible($draft, $definitions, 0);
            }
            $this->store->deleteEvents($id, $discarded);
            ++$draft['revision'];
            foreach ($discarded as $name) {
                $draft['fields'][$name] =
                    ['discardedAt' => $draft['revision']] + $this->rebase($draft['fields'][$name]);
            }
            $this->store->record($id, [
                'id' => $this->batchId(),
                'field' => '',
                'kind' => 'discard',
                'version' => 0,
                'revision' => $draft['revision'],
                'userId' => $userId,
                'label' => $label ?? $this->systemLabel,
                'fields' => $discarded,
            ]);
            $draft['bytes'] = $this->store->size($id);
            $this->settle($draft);
            $this->store->write($id, $draft, $discarded);

            // @infection-ignore-all: revisions start at 1, so the events after revision 0 and after -1 are the same.
            return $this->visible($draft, $definitions, 0);
        });
    }

    /** Deletes the drafts nobody wrote to for {@see CLEAN_DAYS} days once they hold no unsaved
     * change or have created their record, and any draft idle for {@see UNSAVED_DAYS} days.
     * A draft whose lock is held is in use and kept.
     */
    public function purge(\DateTimeImmutable $now): int
    {
        $clean = $now->modify('-' . $this->cleanDays . ' days')->format('Y-m-d H:i:s.u');
        $unsaved = $now->modify('-' . $this->unsavedDays . ' days')->format('Y-m-d H:i:s.u');
        $purged = 0;
        foreach ($this->store->stale($clean, $unsaved) as $id) {
            try {
                $this->lock($id, 0);
            } catch (DraftBusyException) {
                continue;
            }
            try {
                $purged += (int) $this->store->delete($id, $clean, $unsaved);
            } finally {
                $this->unlock($id);
            }
        }

        return $purged;
    }

    /** Serializes the writers of one draft, a save included ({@see DraftLocks}).
     *
     * @throws DraftBusyException past the wait: another process holds it
     */
    public function lock(string $id, ?int $seconds = null): void
    {
        $this->locks->lock($id, $seconds);
    }

    public function unlock(string $id): void
    {
        $this->locks->unlock($id);
    }

    /** FrankenPHP workers keep their connection, and with it any lock a request left behind. */
    public function reset(): void
    {
        $this->locks->reset();
    }

    /** @template T
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function exclusive(string $id, \Closure $work): mixed
    {
        $this->lock($id, min($this->lockSeconds, $this->writerLockSeconds));
        try {
            return $this->store->db()->transactional($work);
        } finally {
            $this->unlock($id);
        }
    }

    /** Work that a Save does under the lock it holds across validation and persistence ({@see lock()}),
     * and that any other caller does under its own.
     *
     * @template T
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function guarded(string $id, \Closure $work): mixed
    {
        return $this->locks->holds($id) ? $this->store->db()->transactional($work) : $this->exclusive($id, $work);
    }

    /** A field no edit has reached yet: its record value, saved.
     *
     * @param SchemaField $definition
     *
     * @return Field
     */
    private function fresh(array $definition): array
    {
        return $definition
        + [
            'version' => null,
            'value' => $definition['initial'],
            'saved' => $definition['initial'],
            'savedAt' => microtime(true),
        ];
    }

    /** One form saves or discards its own fields: another locale's unsaved edits keep the draft unsaved,
     * so purge() holds it for UNSAVED_DAYS.
     *
     * @param Draft $draft
     */
    private function settle(array &$draft): void
    {
        if ([] === array_filter($draft['fields'], DraftView::unsaved(...))) {
            $draft['savedRevision'] = $draft['revision'];
        }
    }

    /** @param Field $field
     * @return Field
     */
    private function rebase(array $field): array
    {
        $field['initial'] = $field['saved'];
        $field['value'] = $field['saved'];
        $field['version'] = null;
        $field['savedVersion'] = null;
        unset($field['initialVersion'], $field['graveyardLength']);

        return $field;
    }

    /** @param Draft $draft
     *
     * @return View
     */
    private function visible(array $draft, FieldDefinitions $allowed, int $since, float $requestedAt = 0.0): array
    {
        return DraftView::of($draft, $allowed, $this->store->events($draft['id'], $since), $requestedAt);
    }

    /** The identifier of a batch the journal writes itself. */
    private function batchId(): string
    {
        // @infection-ignore-all: any length fits the column and the batch format; no test can tell 15 or 17 random bytes from 16.
        return bin2hex(random_bytes(16));
    }

    /** A generation no page of a purged draft can still hold: half the INT range leaves room for ++. */
    private function randomGeneration(): int
    {
        // @infection-ignore-all: a random draw; no test can tell a bound moved by one, or by a power of two, from this one.
        return random_int(1, 2 ** 30);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
