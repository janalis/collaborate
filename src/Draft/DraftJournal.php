<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * PHP orders opaque editor operations; CKEditor performs their model-level transformations.
 *
 * @phpstan-type SchemaField array{type: string, initial: mixed, schema?: string, formName?: string, unordered?: bool}
 * @phpstan-type Field array{type: string, initial: mixed, version: int|null, value: mixed, saved: mixed, initialVersion?: int, schema?: string, formName?: string, unordered?: bool, savedVersion?: int|null, savedAt?: float, graveyardLength?: int, discardedAt?: int}
 * @phpstan-type Batch array{id: string, field: string, kind: string, version: int, revision: int, userId: int, label: string, operations?: list<array<string, mixed>>, value?: mixed, labels?: array<array-key, string>, fields?: list<string>}
 * @phpstan-type Actor array{userId: int, label: string}
 * @phpstan-type Draft array{id: string, generation: int, revision: int, savedRevision: int, bytes: int, recordId: string|null, updatedAt: string, fields: array<string, Field>, savedBy?: Actor|null}
 * @phpstan-type View array{generation: int, revision: int, savedRevision: int, recordId: string|null, savedBy: Actor|null, fields: array<string, Field>, digest: string, stamp: string, events: list<Batch>, conflicts: array<string, mixed>}
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

    /** Nesting a value may hold: the draft view wraps it in a few more levels, which JSON caps at 512. */
    private int $maxValueDepth;

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

    private bool $supported = false;

    /** @var array<string, int> */
    private array $held = [];

    /** @var array<string, string> a collection template's name => the expression its entries' names match */
    private array $expressions = [];

    /**
     * @param string $systemLabel the name of the writes no editor made (a compaction, a reconciliation)
     * @param array{max_update_bytes?: int, max_draft_bytes?: int, max_fields?: int, max_value_depth?: int, label_length?: int, compact?: array{bytes?: int, fields?: int, idle?: int, ...}, locks?: array{save?: int, writer?: int, ...}, purge?: array{clean_days?: int, unsaved_days?: int, ...}, ...} $limits
     *     the `collaborate.drafts` configuration
     * @param int|null $firstGeneration the generation of every new draft instead of a random one
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly DraftText $text = new DraftText(),
        private readonly string $systemLabel = 'System',
        array $limits = [],
        private readonly ?int $firstGeneration = null,
    ) {
        $this->maxUpdateBytes = $limits['max_update_bytes'] ?? 262_144;
        $this->maxDraftBytes = $limits['max_draft_bytes'] ?? 10_485_760;
        $this->maxFields = $limits['max_fields'] ?? 10_000;
        $this->maxValueDepth = $limits['max_value_depth'] ?? 8;
        $this->labelLength = $limits['label_length'] ?? 255;
        $this->compactBytes = $limits['compact']['bytes'] ?? 1_048_576;
        $this->compactFields = $limits['compact']['fields'] ?? 5_000;
        $this->compactIdle = $limits['compact']['idle'] ?? 60;
        $this->lockSeconds = $limits['locks']['save'] ?? 10;
        $this->writerLockSeconds = $limits['locks']['writer'] ?? 1;
        $this->cleanDays = $limits['purge']['clean_days'] ?? 7;
        $this->unsavedDays = $limits['purge']['unsaved_days'] ?? 90;
    }

    /** @param array<string, SchemaField> $fields
     * @return View
     */
    public function join(string $id, array $fields, float $requestedAt = 0.0): array
    {
        return $this->exclusive($id, function () use ($id, $fields, $requestedAt): array {
            // A draft recreated after purge() must not reuse a generation a page left open still holds:
            // that page would take the new revisions for ones it has seen. Half the INT range leaves room for ++.
            $this->db()->executeStatement(
                'INSERT IGNORE INTO collaborate_draft (id, generation, revision, saved_revision, bytes, updated_at) VALUES (?, ?, 0, 0, 0, ?)',
                [$id, $this->firstGeneration ?? random_int(1, 2 ** 30), $this->now()],
            );
            $draft = $this->read($id, true);
            $compacted = false;
            // Rebasing breaks every open editor's model-version chain: peers on the old
            // generation stop, and a page without unsent edits reloads. Only an idle draft
            // without unsaved changes is compacted, so the server loses no edit and no open
            // page loses one it had not sent yet (the saver's own reload follows its save).
            // A draft past COMPACT_FIELDS is compacted only to drop rows: Discard and Save keep them.
            $large = $draft['bytes'] > $this->compactBytes;
            if (
                ($large || \count($draft['fields']) > $this->compactFields)
                && $draft['updatedAt'] < $this->now('-' . $this->compactIdle . ' seconds')
                && null === $draft['recordId']
                && [] === array_filter($draft['fields'], $this->unsaved(...))
            ) {
                $rebased = array_map($this->rebase(...), $draft['fields']);
                $removed = $this->removedEntries(['fields' => $rebased] + $draft);
                // The old generation can no longer address a removed collection entry: drop it.
                $kept = array_filter(
                    $rebased,
                    fn(array $field, string $name): bool => (
                        !$this->removed($removed, $field['formName'] ?? $name)
                        && !('member' === $field['type'] && false === ($field['value']['alive'] ?? null))
                    ),
                    \ARRAY_FILTER_USE_BOTH,
                );
                if ($large || \count($kept) < \count($draft['fields'])) {
                    ++$draft['generation'];
                    ++$draft['revision'];
                    $draft['savedRevision'] = $draft['revision'];
                    $this->db()->delete('collaborate_draft_event', ['draft_id' => $id]);
                    $draft['bytes'] = 0;
                    $draft['fields'] = $kept;
                    $compacted = true;
                }
            }
            $added = [];
            foreach ($fields as $name => $field) {
                if ($this->prototype($name)) {
                    continue;
                }
                if (!isset($draft['fields'][$name])) {
                    $added[] = $name;
                    $draft['fields'][$name] = $this->fresh($field);
                }
            }
            $adopted = [];
            foreach ($draft['fields'] as $name => &$state) {
                $definition = $this->definition($fields, $name);
                if (null !== $definition && ($state['schema'] ?? null) !== ($definition['schema'] ?? null)) {
                    if ($this->unsaved($state)) {
                        throw new ConflictHttpException(
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
                $this->db()->executeStatement(
                    'DELETE FROM collaborate_draft_event WHERE draft_id = ? AND field IN (?)',
                    [$id, $adopted],
                    [1 => ArrayParameterType::STRING],
                );
                $draft['bytes'] = $this->size($id);
            }
            // Opening a page writes nothing: open peers keep their replay stamp, and a merely viewed draft still idles.
            if ($compacted || [] !== $added || [] !== $adopted) {
                $this->write($id, $draft, $compacted ? null : [...$added, ...$adopted]);
            }

            return $this->visible($draft, $fields, 0, $requestedAt);
        });
    }

    /** A page polls every few seconds: a draft nobody wrote to since the row it last read, named by `$stamp`,
     * needs no replay. Every write moves updated_at; a page that takes a new grant sends no stamp, since its
     * conflicts compare the draft with that grant's record.
     */
    public function unchanged(string $id, int $since, string $stamp): bool
    {
        $row = $this->db()->fetchAssociative('SELECT revision, updated_at FROM collaborate_draft WHERE id = ?', [$id]);

        return false !== $row && $stamp === (string) $row['updated_at'] && $since === (int) $row['revision'];
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
        $view = $this->db()->transactional(fn(): array => $this->visible(
            $this->read($id),
            $allowed,
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
        $size = \strlen(json_encode($event, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        if ($size > $this->maxUpdateBytes) {
            throw new HttpException(413, 'Collaborate update is too large.');
        }
        $field = $event['field'] ?? null;
        $definition = \is_string($field) && !$this->prototype($field) ? $this->definition($allowed, $field) : null;
        if (
            !\is_string($field)
            || null === $definition
            || !\is_string($event['id'] ?? null)
            || !\is_int($event['generation'] ?? null)
            || !\is_int($event['version'] ?? null)
            || !\is_int($event['seen'] ?? null)
            || !preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $event['id'])
        ) {
            throw new BadRequestHttpException('Invalid Collaborate event.');
        }

        return $this->exclusive($id, function () use ($id, $event, $field, $size, $userId, $label, $definition): array {
            $draft = $this->read($id, true, $field);
            // A retried batch whose response was lost gets its stored result, whatever happened to the draft since.
            $accepted = $this->batch($id, $event['id']);
            if (null !== $accepted) {
                if ($accepted['userId'] !== $userId || $accepted['field'] !== $field) {
                    throw new ConflictHttpException('Collaborate batch identifier is already used.');
                }

                return $accepted;
            }
            if (null !== $draft['recordId']) {
                throw new ConflictHttpException(
                    'This new-record draft is already saved. Continue on the saved record.',
                );
            }
            if ($event['generation'] !== $draft['generation']) {
                throw new ConflictHttpException('Collaborate draft was discarded.');
            }
            if ($this->removed($this->removedEntries($draft), $definition['formName'] ?? $field)) {
                throw new ConflictHttpException('The collection entry was removed.');
            }
            if (($draft['bytes'] + $size) > $this->maxDraftBytes) {
                throw new HttpException(413, 'Collaborate draft is full. Save or discard before continuing.');
            }
            if (!isset($draft['fields'][$field])) {
                // Only an entry added to a collection brings a new field here: Join stored every other one.
                $rows = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM collaborate_draft_field WHERE draft_id = ?', [
                    $id,
                ]);
                if ($rows >= $this->maxFields) {
                    throw new HttpException(413, 'Collaborate draft is full. Save or discard before continuing.');
                }
                $draft['fields'][$field] = $this->fresh($definition);
            }
            $state = &$draft['fields'][$field];
            // The page edited the field as it stood before a Discard it has not replayed yet.
            if (($state['discardedAt'] ?? 0) > $event['seen']) {
                throw new ConflictHttpException('Collaborate field was discarded.');
            }
            if (($state['schema'] ?? null) !== ($definition['schema'] ?? null)) {
                throw new ConflictHttpException('Collaborate schema changed.');
            }
            $version = $state['version'];
            if (null === $version) {
                $version = $event['version'];
                if ($version < 0 || $version > 100_000) {
                    throw new BadRequestHttpException('Invalid initial model version.');
                }
                $state['initialVersion'] = $version;
            }
            if ($event['version'] !== $version) {
                throw new ConflictHttpException('Collaborate model revision changed.');
            }
            if ('operations' === ($event['kind'] ?? null) && \in_array($state['type'], ['text', 'rich'], true)) {
                $operations = $event['operations'] ?? null;
                if (
                    !\is_array($operations)
                    || [] === $operations
                    || \count($operations) > 2048
                    || !array_is_list($operations)
                ) {
                    throw new BadRequestHttpException('Invalid Collaborate operations.');
                }
                foreach ($operations as $offset => $operation) {
                    if (
                        !\is_array($operation)
                        || ($operation['baseVersion'] ?? null) !== ($version + $offset)
                        || !\is_string($operation['__className'] ?? null)
                    ) {
                        throw new BadRequestHttpException('Invalid Collaborate operation version.');
                    }
                }
                /** @var list<array<string, mixed>> $operations */
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
                if (
                    !\is_scalar($event['value']) && null !== $event['value'] && !\is_array($event['value'])
                    || !$this->shallow($event['value'])
                ) {
                    throw new BadRequestHttpException('Invalid Collaborate value.');
                }
                $payload = ['value' => $event['value']];
                // A choice names what its value shows: a peer's autocomplete holds no option for it.
                if (\array_key_exists('labels', $event)) {
                    if ('value' !== $state['type'] || !$this->labels($event['labels'], $event['value'])) {
                        throw new BadRequestHttpException('Invalid Collaborate labels.');
                    }
                    $payload['labels'] = $event['labels'];
                }
                if ('member' === $state['type']) {
                    if (
                        !\is_array($event['value'])
                        || !\is_bool($event['value']['alive'] ?? null)
                        || !\is_int($event['value']['order'] ?? null) && !\is_float($event['value']['order'] ?? null)
                    ) {
                        throw new BadRequestHttpException('Invalid collection membership.');
                    }
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
                throw new BadRequestHttpException('Unknown Collaborate event kind.');
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
            // Source mode is shared UI state, not a persisted form value: switching it leaves the draft saved.
            if ($this->mode($field)) {
                $state['savedVersion'] = $state['version'];
                if ($clean) {
                    $draft['savedRevision'] = $draft['revision'];
                }
            }
            $draft['bytes'] += $size;
            $this->record($id, $accepted);
            $this->write($id, $draft, [$field]);

            return $accepted;
        });
    }

    /** Caller holds {@see lock()} across Symfony validation and editorial persistence.
     * @param array<string, mixed> $current
     */
    public function lockSave(string $id, int $generation, int $revision, array $current, bool $new = false): void
    {
        $draft = $this->read($id);
        if ($new && null !== $draft['recordId']) {
            throw new ConflictHttpException(self::ALREADY_CREATED);
        }
        if ($generation !== $draft['generation'] || $revision !== $draft['revision']) {
            throw new ConflictHttpException('The shared draft changed. Synchronize before saving.');
        }
        foreach ($current as $name => $value) {
            if (isset($draft['fields'][$name]) && $this->differs($draft['fields'][$name], $value)) {
                throw new ConflictHttpException(
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
        $this->db()->transactional(fn() => $this->checkpoint($id, $values, $actor));
    }

    /** @param array<string, mixed> $values
     * @param Actor|null $actor
     */
    private function checkpoint(string $id, array $values, ?array $actor): void
    {
        $draft = $this->read($id, true);
        $draft['savedBy'] = null === $actor
            ? null
            : ['userId' => $actor['userId'], 'label' => mb_substr($actor['label'], 0, $this->labelLength)];
        foreach ($values as $name => $value) {
            if (!isset($draft['fields'][$name])) {
                continue;
            }

            $field = &$draft['fields'][$name];
            if ('text' === $field['type'] && \is_string($value) && $field['value'] !== $value) {
                $version = $field['version'] ?? ('' === $field['initial'] ? 1 : 2);
                $operations = $this->text->diff((string) $field['value'], $value, $version);
                $result = $this->text->apply((string) $field['value'], $field['graveyardLength'] ?? 0, $operations);
                $field['initialVersion'] ??= $version;
                $field['version'] = $version + \count($operations);
                $field['value'] = $result['text'];
                $field['graveyardLength'] = $result['graveyard'];
                $this->record($id, [
                    'id' => bin2hex(random_bytes(16)),
                    'field' => $name,
                    'kind' => 'operations',
                    'version' => $version,
                    'revision' => ++$draft['revision'],
                    'userId' => 0,
                    'label' => $this->systemLabel,
                    'operations' => $operations,
                ]);
            } elseif ('value' === $field['type'] && $field['value'] !== $value && !$this->mode($name)) {
                $version = $field['version'] ?? 0;
                $field['value'] = $value;
                $field['version'] = $version + 1;
                $this->record($id, [
                    'id' => bin2hex(random_bytes(16)),
                    'field' => $name,
                    'kind' => 'value',
                    'version' => $version,
                    'revision' => ++$draft['revision'],
                    'userId' => 0,
                    'label' => $this->systemLabel,
                    'value' => $value,
                ]);
            }
            unset($field);
            $draft['fields'][$name]['saved'] = $value;
            $draft['fields'][$name]['savedVersion'] = $draft['fields'][$name]['version'];
            $draft['fields'][$name]['savedAt'] = microtime(true);
        }
        $removed = $this->removedEntries($draft);
        foreach ($draft['fields'] as $name => &$field) {
            if ('member' === $field['type'] && !\array_key_exists($name, $values)) {
                $position = strrpos($name, '[@');
                if (
                    false !== $position
                    && \array_key_exists(substr($name, 0, $position) . '[__collection__]', $values)
                ) {
                    $field['saved'] = ['alive' => false, 'order' => 0, 'recordId' => null];
                    $field['savedVersion'] = $field['version'];
                }
            }
            if ($this->removed($removed, $field['formName'] ?? $name)) {
                $field['savedVersion'] = $field['version'];
            }
        }
        unset($field);
        $this->settle($draft);
        $draft['bytes'] = $this->size($id);
        $this->write($id, $draft);
    }

    public function created(string $id, string $recordId): void
    {
        $this->db()->transactional(function () use ($id, $recordId): void {
            $draft = $this->read($id, true);
            $draft['recordId'] = $recordId;
            $this->write($id, $draft, []);
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
            $draft = $this->read($id, true);
            if ($generation !== $draft['generation'] || $revision !== $draft['revision']) {
                throw new ConflictHttpException('The shared draft changed. Review it before reconciling.');
            }
            $conflicts = $this->conflicts($draft, $this->allowedFields($draft, $allowed), $allowed, $requestedAt);
            foreach ($conflicts as $name => $value) {
                $draft['fields'][$name]['savedVersion'] = -1;
                $draft['fields'][$name]['saved'] = $value;
                $draft['fields'][$name]['savedAt'] = microtime(true);
            }
            // The kept draft now differs from the record: purge() must see it unsaved. The record was
            // saved outside the draft, so no editor's Save holds it any more.
            if ([] !== $conflicts) {
                $draft['savedRevision'] = -1;
                $draft['savedBy'] = null;
            }
            $this->write($id, $draft, array_keys($conflicts));

            return $keep
                ? $this->visible($draft, $allowed, 0)
                : $this->discard($id, $generation, $revision, $allowed, $userId, $label);
        });
    }

    /** A plain list stores no key: its entries reopen under the keys of the order its last save recorded.
     *
     * @return array<string, list<string>> collection path => saved entry keys
     */
    public function listKeys(string $id): array
    {
        $keys = [];
        foreach ($this->decodeFields($this->db()->fetchAllAssociative("SELECT name, state FROM collaborate_draft_field WHERE draft_id = ? AND type = 'collection' ORDER BY name", [
            $id,
        ])) as $name => $field) {
            if (
                !(
                    \is_array($field['saved'])
                    && str_ends_with($name, '[__collection__]')
                    && array_is_list($field['saved'])
                )
            ) {
                continue;
            }

            $keys[substr($name, 0, -\strlen('[__collection__]'))] = array_map(strval(...), $field['saved']);
        }

        return $keys;
    }

    /** Stable form keys survive a new collection entry receiving its database identifier.
     * @return array<string, array<string, string>>
     */
    public function aliases(string $id): array
    {
        $aliases = [];
        foreach ($this->members($id) as $name => $field) {
            if (!\is_array($field['saved']) || !\is_scalar($field['saved']['recordId'] ?? null)) {
                continue;
            }
            $position = strrpos($name, '[@');
            if (false !== $position) {
                $aliases[substr($name, 0, $position)][(string) $field['saved']['recordId']] = substr(
                    $name,
                    $position + 2,
                    -1,
                );
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
            $draft = $this->read($id, true);
            if ($generation !== $draft['generation'] || $revision !== $draft['revision']) {
                throw new ConflictHttpException('The shared draft changed. Synchronize before discarding.');
            }
            // One entry per field, not per event: a long journal holds thousands of batches. A field kept
            // against a saved record holds no edit yet differs from it.
            $discarded = array_values(array_filter(
                array_unique([
                    ...array_map(
                        strval(...),
                        $this->db()->fetchFirstColumn("SELECT DISTINCT field FROM collaborate_draft_event WHERE draft_id = ? AND field <> ''", [
                            $id,
                        ]),
                    ),
                    ...array_map(strval(...), array_keys(array_filter($draft['fields'], $this->unsaved(...)))),
                ]),
                // Every journalled field has its state row: append and checkpoint write it first.
                fn(string $field): bool => (
                    isset($draft['fields'][$field])
                    && null !== $this->definition($allowed, $field)
                ),
            ));
            if ([] === $discarded) {
                return $this->visible($draft, $allowed, 0);
            }
            $this->db()->executeStatement(
                'DELETE FROM collaborate_draft_event WHERE draft_id = ? AND field IN (?)',
                [$id, $discarded],
                [1 => ArrayParameterType::STRING],
            );
            ++$draft['revision'];
            foreach ($discarded as $name) {
                $draft['fields'][$name] =
                    ['discardedAt' => $draft['revision']] + $this->rebase($draft['fields'][$name]);
            }
            $this->record($id, [
                'id' => bin2hex(random_bytes(16)),
                'field' => '',
                'kind' => 'discard',
                'version' => 0,
                'revision' => $draft['revision'],
                'userId' => $userId,
                'label' => $label ?? $this->systemLabel,
                'fields' => $discarded,
            ]);
            $draft['bytes'] = $this->size($id);
            $this->settle($draft);
            $this->write($id, $draft, $discarded);

            return $this->visible($draft, $allowed, 0);
        });
    }

    /** Deletes the drafts nobody wrote to for {@see CLEAN_DAYS} days once they hold no unsaved
     * change or have created their record, and any draft idle for {@see UNSAVED_DAYS} days.
     * A draft whose lock is held is in use and kept.
     */
    public function purge(\DateTimeImmutable $now): int
    {
        $condition = '(updated_at < ? AND (saved_revision = revision OR record_id IS NOT NULL)) OR updated_at < ?';
        $parameters = [
            $now->modify('-' . $this->cleanDays . ' days')->format('Y-m-d H:i:s.u'),
            $now->modify('-' . $this->unsavedDays . ' days')->format('Y-m-d H:i:s.u'),
        ];
        $purged = 0;
        foreach ($this->db()->fetchFirstColumn(
            'SELECT id FROM collaborate_draft WHERE ' . $condition,
            $parameters,
        ) as $id) {
            $id = (string) $id;
            try {
                $this->lock($id, 0);
            } catch (ServiceUnavailableHttpException) {
                continue;
            }
            try {
                $purged += $this->db()->transactional(function () use ($id, $condition, $parameters): int {
                    if (
                        0 === $this->db()->executeStatement('DELETE FROM collaborate_draft WHERE id = ? AND ('
                        . $condition
                        . ')', [$id, ...$parameters])
                    ) {
                        return 0;
                    }
                    $this->db()->delete('collaborate_draft_field', ['draft_id' => $id]);
                    $this->db()->delete('collaborate_draft_event', ['draft_id' => $id]);

                    return 1;
                });
            } finally {
                $this->unlock($id);
            }
        }

        return $purged;
    }

    /** Serializes the writers of one draft, a save included. A named lock is not
     * transactional: EasyAdmin's flush commits before its postFlush side effects run.
     */
    public function lock(string $id, ?int $seconds = null): void
    {
        $acquired = $this->db()->fetchOne('SELECT GET_LOCK(?, ?)', [
            $this->lockName($id),
            $seconds ?? $this->lockSeconds,
        ]);
        if (1 !== (int) $acquired) {
            throw new ServiceUnavailableHttpException(1, 'Collaborate draft is busy.');
        }
        $this->held[$id] = ($this->held[$id] ?? 0) + 1;
    }

    public function unlock(string $id): void
    {
        if (!isset($this->held[$id])) {
            return;
        }
        $this->db()->fetchOne('SELECT RELEASE_LOCK(?)', [$this->lockName($id)]);
        if (0 === --$this->held[$id]) {
            unset($this->held[$id]);
        }
    }

    /** FrankenPHP workers keep their connection, and with it any lock a request left behind. */
    public function reset(): void
    {
        foreach (array_keys($this->held) as $id) {
            while (isset($this->held[$id])) {
                $this->unlock($id);
            }
        }
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
            return $this->db()->transactional($work);
        } finally {
            $this->unlock($id);
        }
    }

    /** The SQL is MariaDB's and MySQL's: named locks, INSERT IGNORE, ON DUPLICATE KEY UPDATE and FOR UPDATE. */
    private function db(): Connection
    {
        if (!$this->supported) {
            $platform = $this->connection->getDatabasePlatform();
            if (!$platform instanceof AbstractMySQLPlatform) {
                throw new \LogicException(\sprintf(
                    'The shared-draft journal needs a MariaDB or MySQL connection; %s is not supported.',
                    $platform::class,
                ));
            }
            $this->supported = true;
        }

        return $this->connection;
    }

    /** Named locks are global to the MariaDB server, and a record's draft id is the same in every database;
     * the hashed database name keeps environments and test instances apart under the 64-character cap.
     */
    private function lockName(string $id): string
    {
        return 'collaborate:' . substr(hash('xxh128', (string) $this->db()->getDatabase()), 0, 16) . ':' . md5($id);
    }

    /** Whether a record value differs from a field's saved baseline. A collection that stores no
     * order reloads in database order whatever order was submitted, so its order is left out.
     *
     * @param Field $field
     */
    private function differs(array $field, mixed $value): bool
    {
        if (($field['unordered'] ?? false) && \is_array($field['saved']) && \is_array($value)) {
            return $this->withoutOrder($field['type'], $field['saved']) !== $this->withoutOrder($field['type'], $value);
        }

        return $field['saved'] !== $value;
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function withoutOrder(string $type, array $value): array
    {
        if ('collection' === $type) {
            sort($value);
        } else {
            unset($value['order']);
        }

        return $value;
    }

    /** @param Field $field */
    private function unsaved(array $field): bool
    {
        return $field['version'] !== ($field['savedVersion'] ?? null);
    }

    /** Whether a value encodes within MAX_VALUE_DEPTH levels. */
    private function shallow(mixed $value): bool
    {
        try {
            json_encode($value, \JSON_THROW_ON_ERROR, $this->maxValueDepth);
        } catch (\JsonException) {
            return false;
        }

        return true;
    }

    /** Labels name some of the ids a choice holds, each in at most LABEL_LENGTH characters. */
    private function labels(mixed $labels, mixed $value): bool
    {
        if (!\is_array($labels)) {
            return false;
        }
        $ids = array_map(strval(...), array_filter(\is_array($value) ? $value : [$value], \is_scalar(...)));

        return array_all(
            $labels,
            fn(mixed $label, int|string $id): bool => (
                \is_string($label)
                && mb_strlen($label) <= $this->labelLength
                && \in_array((string) $id, $ids, true)
            ),
        );
    }

    private function mode(string $name): bool
    {
        return str_ends_with($name, '[__mode__]');
    }

    /** One form saves or discards its own fields: another locale's unsaved edits keep the draft unsaved,
     * so purge() holds it for UNSAVED_DAYS.
     *
     * @param Draft $draft
     */
    private function settle(array &$draft): void
    {
        if ([] === array_filter($draft['fields'], $this->unsaved(...))) {
            $draft['savedRevision'] = $draft['revision'];
        }
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

    /** An append reads its own field and the collection members that may have removed it, not the whole draft.
     *
     * @return Draft
     */
    private function read(string $id, bool $lock = false, ?string $field = null): array
    {
        $row = $this->db()->fetchAssociative(
            'SELECT generation, revision, saved_revision, bytes, record_id, saved_by, saved_by_label, updated_at FROM collaborate_draft WHERE id = ?'
            . ($lock ? ' FOR UPDATE' : ''),
            [$id],
        );
        if (false === $row) {
            throw new ConflictHttpException('Collaborate draft does not exist. Join it first.');
        }
        $states = null === $field
            ? $this->db()->fetchAllAssociative('SELECT name, state FROM collaborate_draft_field WHERE draft_id = ? ORDER BY name', [
                $id,
            ])
            : $this->db()->fetchAllAssociative("SELECT name, state FROM collaborate_draft_field WHERE draft_id = ? AND (name = ? OR type = 'member') ORDER BY name", [
                $id,
                $field,
            ]);

        return [
            'id' => $id,
            'generation' => (int) $row['generation'],
            'revision' => (int) $row['revision'],
            'savedRevision' => (int) $row['saved_revision'],
            'bytes' => (int) $row['bytes'],
            'recordId' => null === $row['record_id'] ? null : (string) $row['record_id'],
            'savedBy' => null === $row['saved_by']
                ? null
                : ['userId' => (int) $row['saved_by'], 'label' => (string) $row['saved_by_label']],
            'updatedAt' => (string) $row['updated_at'],
            'fields' => $this->decodeFields($states),
        ];
    }

    /** @return array<string, Field> */
    private function members(string $id): array
    {
        return $this->decodeFields($this->db()->fetchAllAssociative("SELECT name, state FROM collaborate_draft_field WHERE draft_id = ? AND type = 'member' ORDER BY name", [
            $id,
        ]));
    }

    /** @param list<array<string, mixed>> $rows `name` and JSON `state` columns
     * @return array<string, Field>
     */
    private function decodeFields(array $rows): array
    {
        $fields = [];
        foreach ($rows as $row) {
            /** @var Field $decoded */
            $decoded = json_decode((string) $row['state'], true, flags: \JSON_THROW_ON_ERROR);
            $fields[(string) $row['name']] = $decoded;
        }

        return $fields;
    }

    /** @param Draft $draft
     * @param list<array-key>|null $names the field states to store; null replaces them all
     */
    private function write(string $id, array &$draft, ?array $names = null): void
    {
        $draft['updatedAt'] = $this->now();
        $this->db()->update(
            'collaborate_draft',
            [
                'generation' => $draft['generation'],
                'revision' => $draft['revision'],
                'saved_revision' => $draft['savedRevision'],
                'bytes' => $draft['bytes'],
                'record_id' => $draft['recordId'],
                'saved_by' => $draft['savedBy']['userId'] ?? null,
                'saved_by_label' => $draft['savedBy']['label'] ?? null,
                'updated_at' => $draft['updatedAt'],
            ],
            ['id' => $id],
        );
        if (null === $names) {
            $this->db()->delete('collaborate_draft_field', ['draft_id' => $id]);
            $names = array_keys($draft['fields']);
        }
        foreach (array_chunk(array_values(array_unique($names)), 100) as $chunk) {
            $parameters = [];
            foreach ($chunk as $name) {
                $field = $draft['fields'][$name];
                array_push($parameters, $id, (string) $name, $field['type'], json_encode($field, \JSON_THROW_ON_ERROR));
            }
            $this->db()->executeStatement(
                'INSERT INTO collaborate_draft_field (draft_id, name, type, state) VALUES '
                . implode(', ', array_fill(0, \count($chunk), '(?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE type = VALUES(type), state = VALUES(state)',
                $parameters,
            );
        }
    }

    private function now(string $modifier = 'now'): string
    {
        return new \DateTimeImmutable($modifier)->format('Y-m-d H:i:s.u');
    }

    /** @param Batch $batch */
    private function record(string $id, array $batch): void
    {
        $this->db()->insert('collaborate_draft_event', [
            'draft_id' => $id,
            'revision' => $batch['revision'],
            'batch_id' => $batch['id'],
            'field' => $batch['field'],
            'payload' => json_encode($batch, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return Batch|null */
    private function batch(string $id, string $batchId): ?array
    {
        return (
            $this->decode($this->db()->fetchFirstColumn('SELECT payload FROM collaborate_draft_event WHERE draft_id = ? AND batch_id = ?', [
                $id,
                $batchId,
            ]))[0] ?? null
        );
    }

    /** @return list<Batch> */
    private function events(string $id, int $since): array
    {
        return $this->decode($this->db()->fetchFirstColumn('SELECT payload FROM collaborate_draft_event WHERE draft_id = ? AND revision > ? ORDER BY revision', [
            $id,
            $since,
        ]));
    }

    /** @param list<mixed> $payloads
     * @return list<Batch>
     */
    private function decode(array $payloads): array
    {
        $events = [];
        foreach ($payloads as $payload) {
            /** @var Batch $event */
            $event = json_decode((string) $payload, true, flags: \JSON_THROW_ON_ERROR);
            $events[] = $event;
        }

        return $events;
    }

    private function size(string $id): int
    {
        return (int) $this->db()->fetchOne('SELECT COALESCE(SUM(LENGTH(payload)), 0) FROM collaborate_draft_event WHERE draft_id = ?', [
            $id,
        ]);
    }

    /** @param Draft $draft
     * @param array<string, SchemaField> $allowed
     *
     * @return View
     */
    private function visible(array $draft, array $allowed, int $since, float $requestedAt = 0.0): array
    {
        $fields = $this->allowedFields($draft, $allowed);
        $clean = [] === array_filter($fields, $this->unsaved(...));

        return [
            'generation' => $draft['generation'],
            'revision' => $draft['revision'],
            'savedRevision' => $clean ? $draft['revision'] : -1,
            'recordId' => $draft['recordId'],
            'savedBy' => $draft['savedBy'] ?? null,
            'conflicts' => $this->conflicts($draft, $fields, $allowed, $requestedAt),
            'fields' => $fields,
            'digest' => hash('xxh128', json_encode($fields, \JSON_THROW_ON_ERROR)),
            'stamp' => $draft['updatedAt'],
            'events' => $this->readable($this->events($draft['id'], $since), $allowed),
        ];
    }

    /** The events a form may read: its own fields' batches, and the Discards that reset one of its fields,
     * naming those only.
     *
     * @param list<Batch>                $events
     * @param array<string, SchemaField> $allowed
     *
     * @return list<Batch>
     */
    private function readable(array $events, array $allowed): array
    {
        $readable = [];
        foreach ($events as $event) {
            if ('discard' === $event['kind']) {
                $event['fields'] = array_values(array_filter(
                    $event['fields'] ?? [],
                    fn(string $field): bool => null !== $this->definition($allowed, $field),
                ));
                if ([] === $event['fields']) {
                    continue;
                }
            } elseif (null === $this->definition($allowed, $event['field'])) {
                continue;
            }
            $readable[] = $event;
        }

        return $readable;
    }

    /** @param Draft $draft
     * @param array<string, SchemaField> $allowed
     *
     * @return array<string, Field>
     */
    private function allowedFields(array $draft, array $allowed): array
    {
        return array_filter(
            $draft['fields'],
            fn(string $name): bool => null !== $this->definition($allowed, $name),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    /** The fields whose record, as the grant issued at `$requestedAt` read it, differs from their saved baseline.
     * A removed collection entry's fields describe nothing the record holds.
     *
     * @param Draft                      $draft
     * @param array<string, Field>       $fields
     * @param array<string, SchemaField> $allowed
     *
     * @return array<string, mixed>
     */
    private function conflicts(array $draft, array $fields, array $allowed, float $requestedAt): array
    {
        $conflicts = [];
        $removed = $this->removedEntries($draft);
        foreach ($fields as $name => $field) {
            $definition = $this->definition($allowed, $name);
            if (
                null !== $definition
                && $requestedAt > ($field['savedAt'] ?? 0.0)
                && $this->differs($field, $definition['initial'])
                && !$this->removed($removed, $field['formName'] ?? $name)
            ) {
                $conflicts[$name] = $definition['initial'];
            }
        }

        return $conflicts;
    }

    /** @param array<string, SchemaField> $allowed
     * @return SchemaField|null
     */
    private function definition(array $allowed, string $name): ?array
    {
        if (isset($allowed[$name])) {
            return $allowed[$name];
        }
        foreach ($allowed as $pattern => $field) {
            if (!$this->prototype($pattern)) {
                continue;
            }
            $this->expressions[$pattern] ??=
                '#^'
                . str_replace(
                    ['__name__', '__member__'],
                    ['[a-zA-Z0-9_-]{1,80}', '@[a-zA-Z0-9_-]{1,80}'],
                    preg_quote($pattern, '#'),
                )
                . '$#D';
            if (1 === preg_match($this->expressions[$pattern], $name)) {
                if (isset($field['formName'])) {
                    $field['formName'] = substr($name, \strlen($pattern) - \strlen($field['formName']));
                }

                return $field;
            }
        }

        return null;
    }

    /** A collection's entry template names its placeholders literally; no edit targets the template itself. */
    private function prototype(string $name): bool
    {
        return str_contains($name, '__name__') || str_contains($name, '__member__');
    }

    /**
     * The collection entries a removed member stands for, as a set: rows grow with a draft, so a name is
     * looked up by its own prefixes instead of every row being scanned for each name.
     *
     * @param Draft $draft
     *
     * @return array<string, true>
     */
    private function removedEntries(array $draft): array
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
            $position = strrpos($memberName, '[@');
            if (false === $position) {
                continue;
            }
            $entries[substr($memberName, 0, $position) . '[' . substr($memberName, $position + 2, -1) . ']'] = true;
        }

        return $entries;
    }

    /** @param array<string, true> $entries */
    private function removed(array $entries, string $name): bool
    {
        // A compound entry's fields sit below it; a scalar entry's value is the entry itself.
        $end = strpos($name, ']');
        while (false !== $end) {
            if (
                isset($entries[substr($name, 0, $end + 1)])
                && (\strlen($name) === ($end + 1) || '[' === $name[$end + 1])
            ) {
                return true;
            }
            $end = strpos($name, ']', $end + 1);
        }

        return false;
    }
}
