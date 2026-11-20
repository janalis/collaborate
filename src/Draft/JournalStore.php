<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Janalis\Collaborate\Draft\Dialect\JournalDialect;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The rows of the drafts {@see DraftJournal} keeps: one per draft, one per field state, one per batch. Every query of
 * the journal is here; what they mean, the order they run in and the locks they run under are the journal's.
 *
 * @phpstan-import-type Field from DraftJournal
 * @phpstan-import-type Batch from DraftJournal
 * @phpstan-import-type Draft from DraftJournal
 *
 * @internal the journal's own
 */
final class JournalStore
{
    /** The batch id of the event row naming the generations a compaction replaced; no batch reads it. */
    private const string REBASE = 'rebase';

    /** The drafts {@see stale()} names, given its two dates. */
    private const string STALE = '(updated_at < ? AND (saved_revision = revision OR record_id IS NOT NULL)) OR updated_at < ?';

    private ?JournalDialect $dialect = null;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /** The connection, once its platform is one the journal speaks ({@see JournalDialect}). */
    public function db(): Connection
    {
        $this->dialect();

        return $this->connection;
    }

    /** The SQL of the connection's platform for what standard SQL leaves out: upserts, row and named locks. */
    public function dialect(): JournalDialect
    {
        return $this->dialect ??= JournalDialect::for($this->connection);
    }

    /** A timestamp as the draft rows store it. */
    public static function now(string $modifier = 'now'): string
    {
        return new \DateTimeImmutable($modifier)->format('Y-m-d H:i:s.u');
    }

    /** Creates the draft's row unless it exists. */
    public function create(string $id, int $generation): void
    {
        $this->db()->executeStatement($this->dialect()->createDraft(), [$id, $generation, self::now()]);
    }

    /** @return array{revision: int, stamp: string}|null the draft's revision and last write, null without a draft */
    public function stamp(string $id): ?array
    {
        $row = $this->db()->fetchAssociative('SELECT revision, updated_at FROM collaborate_draft WHERE id = ?', [$id]);
        if (false === $row) {
            return null;
        }
        /** @var array{revision: int|string, updated_at: string} $row */

        return ['revision' => (int) $row['revision'], 'stamp' => $row['updated_at']];
    }

    /** An append reads its own field and the collection members that may have removed it, not the whole draft.
     *
     * @param list<string>|null $names the fields to read, null for every one
     *
     * @return Draft
     */
    public function read(string $id, bool $lock = false, ?array $names = null): array
    {
        $row = $this->db()->fetchAssociative(
            'SELECT generation, revision, saved_revision, bytes, record_id, saved_by, saved_by_label, updated_at FROM collaborate_draft WHERE id = ?'
            . ($lock ? $this->dialect()->forUpdate() : ''),
            [$id],
        );
        if (false === $row) {
            throw new ConflictHttpException('Collaborate draft does not exist. Join it first.');
        }
        // Drivers return the string columns as strings, the integer ones as integers or numeric strings.
        /** @var array{generation: int|string, revision: int|string, saved_revision: int|string, bytes: int|string, record_id: string|null, saved_by: int|string|null, saved_by_label: string|null, updated_at: string} $row */
        $states = null === $names
            ? $this->db()->fetchAllAssociative('SELECT name, state FROM collaborate_draft_field WHERE draft_id = ? ORDER BY name', [
                $id,
            ])
            : $this->db()->fetchAllAssociative(
                'SELECT name, state FROM collaborate_draft_field WHERE draft_id = ? AND name IN (?) ORDER BY name',
                [$id, $names],
                [1 => ArrayParameterType::STRING],
            );

        return [
            'id' => $id,
            'generation' => (int) $row['generation'],
            'revision' => (int) $row['revision'],
            'savedRevision' => (int) $row['saved_revision'],
            // @infection-ignore-all: a numeric string adds and compares as its integer; the cast only types it.
            'bytes' => (int) $row['bytes'],
            'recordId' => $row['record_id'],
            'savedBy' => null === $row['saved_by']
                ? null
                : ['userId' => (int) $row['saved_by'], 'label' => $row['saved_by_label'] ?? ''],
            'updatedAt' => $row['updated_at'],
            'fields' => $this->decodeFields($states),
        ];
    }

    /** @return array<string, Field> the field states of one type, `member` or `collection` */
    public function fieldsOfType(string $id, string $type): array
    {
        return $this->decodeFields($this->db()->fetchAllAssociative('SELECT name, state FROM collaborate_draft_field WHERE draft_id = ? AND type = ? ORDER BY name', [
            $id,
            $type,
        ]));
    }

    public function fieldCount(string $id): int
    {
        /** @var int|numeric-string $rows a count */
        $rows = $this->db()->fetchOne('SELECT COUNT(*) FROM collaborate_draft_field WHERE draft_id = ?', [$id]);

        return (int) $rows;
    }

    /** @param Draft $draft
     * @param list<array-key>|null $names the field states to store; null replaces them all
     */
    public function write(string $id, array &$draft, ?array $names = null): void
    {
        $draft['updatedAt'] = self::now();
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
        foreach (array_chunk($names, 100) as $chunk) {
            $parameters = [];
            foreach ($chunk as $name) {
                $field = $draft['fields'][$name];
                array_push($parameters, $id, (string) $name, $field['type'], json_encode($field, \JSON_THROW_ON_ERROR));
            }
            $this->db()->executeStatement($this->dialect()->writeFields(\count($chunk)), $parameters);
        }
    }

    /** A batch as the journal stores it, and counts it: UTF-8 bytes, not \uXXXX escapes. */
    public static function encode(mixed $batch): string
    {
        return json_encode($batch, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param Batch $batch
     *
     * @return int the bytes the journal grew by
     */
    public function record(string $id, array $batch): int
    {
        $payload = self::encode($batch);
        $this->insert($id, $batch, $payload);

        return \strlen($payload);
    }

    /** @param Batch $batch
     * @param string $payload the batch {@see encode()}d
     */
    public function insert(string $id, array $batch, string $payload): void
    {
        $this->db()->insert('collaborate_draft_event', [
            'draft_id' => $id,
            'revision' => $batch['revision'],
            'batch_id' => $batch['id'],
            'field' => $batch['field'],
            'payload' => $payload,
        ]);
    }

    /** @return Batch|null */
    public function batch(string $id, string $batchId): ?array
    {
        $payload = $this->db()->fetchOne('SELECT payload FROM collaborate_draft_event WHERE draft_id = ? AND batch_id = ?', [
            $id,
            $batchId,
        ]);
        if (false === $payload) {
            return null;
        }
        /** @var Batch */
        return json_decode((string) $payload, true, flags: \JSON_THROW_ON_ERROR);
    }

    /** The batches after `since`, and the generations compactions replaced when the last one falls after `since`
     * too: a page still on one of those generations replays from a revision before it.
     *
     * @return array{list<Batch>, list<int>}
     */
    public function events(string $id, int $since): array
    {
        $events = [];
        $rebasedFrom = [];
        /** @var list<string> $payloads a JSON column */
        $payloads = $this->db()->fetchFirstColumn('SELECT payload FROM collaborate_draft_event WHERE draft_id = ? AND revision > ? ORDER BY revision', [
            $id,
            $since,
        ]);
        foreach ($payloads as $payload) {
            /** @var array<mixed> $row */
            $row = json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
            // A batch is an object; the row a compaction leaves, the list of the generations it replaced.
            if (array_is_list($row)) {
                /** @var list<int> $row */
                $rebasedFrom = $row;
            } else {
                /** @var Batch $row */
                $events[] = $row;
            }
        }

        return [$events, $rebasedFrom];
    }

    /** @return list<string> the fields the journal holds batches of */
    public function journalledFields(string $id): array
    {
        /** @var list<string> a text column */
        return $this->db()->fetchFirstColumn("SELECT DISTINCT field FROM collaborate_draft_event WHERE draft_id = ? AND field <> ''", [
            $id,
        ]);
    }

    /** @param list<string> $fields */
    public function deleteEvents(string $id, array $fields): void
    {
        $this->db()->executeStatement(
            'DELETE FROM collaborate_draft_event WHERE draft_id = ? AND field IN (?)',
            [$id, $fields],
            [1 => ArrayParameterType::STRING],
        );
    }

    /** Empties the journal of a compacted draft, leaving the row that names the generations it replaced, `$generation`
     * last: a page still on one finds it there.
     */
    public function rebase(string $id, int $revision, int $generation): void
    {
        $rebasedFrom = [...$this->rebasedFrom($id), $generation];
        $this->db()->delete('collaborate_draft_event', ['draft_id' => $id]);
        $this->db()->insert('collaborate_draft_event', [
            'draft_id' => $id,
            'revision' => $revision,
            'batch_id' => self::REBASE,
            'field' => '',
            'payload' => json_encode($rebasedFrom, \JSON_THROW_ON_ERROR),
        ]);
    }

    public function size(string $id): int
    {
        // Counted as {@see record()} wrote each batch: MySQL returns a JSON column reformatted, with its
        // own spacing and key order, so LENGTH() would count another text on each server.
        $bytes = 0;
        /** @var \Traversable<int, string> $payloads the payload column, strings on every driver */
        $payloads = $this->db()->iterateColumn('SELECT payload FROM collaborate_draft_event WHERE draft_id = ? AND batch_id <> ?', [
            $id,
            self::REBASE,
        ]);
        foreach ($payloads as $payload) {
            $bytes += \strlen(self::encode(json_decode($payload, flags: \JSON_THROW_ON_ERROR)));
        }

        return $bytes;
    }

    /** @return list<string> the drafts nobody wrote to since `$clean` that hold no unsaved change or created their
     *     record, and those nobody wrote to since `$unsaved`
     */
    public function stale(string $clean, string $unsaved): array
    {
        /** @var list<string> a text column */
        return $this->db()->fetchFirstColumn('SELECT id FROM collaborate_draft WHERE ' . self::STALE, [
            $clean,
            $unsaved,
        ]);
    }

    /** Deletes a draft {@see stale()} still names, with its rows.
     *
     * @return bool whether it did
     */
    public function delete(string $id, string $clean, string $unsaved): bool
    {
        return $this->db()->transactional(function () use ($id, $clean, $unsaved): bool {
            if (
                0 === $this->db()->executeStatement('DELETE FROM collaborate_draft WHERE id = ? AND ('
                . self::STALE
                . ')', [
                    $id,
                    $clean,
                    $unsaved,
                ])
            ) {
                return false;
            }
            $this->db()->delete('collaborate_draft_field', ['draft_id' => $id]);
            $this->db()->delete('collaborate_draft_event', ['draft_id' => $id]);

            return true;
        });
    }

    /** @return list<int> the generations compactions replaced, oldest first, since the draft was created */
    private function rebasedFrom(string $id): array
    {
        $payload = $this->db()->fetchOne('SELECT payload FROM collaborate_draft_event WHERE draft_id = ? AND batch_id = ?', [
            $id,
            self::REBASE,
        ]);
        if (false === $payload) {
            return [];
        }
        /** @var list<int> */
        return json_decode((string) $payload, true, flags: \JSON_THROW_ON_ERROR);
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
            /** @var string $name a text column */
            $name = $row['name'];
            $fields[$name] = $decoded;
        }

        return $fields;
    }
}
