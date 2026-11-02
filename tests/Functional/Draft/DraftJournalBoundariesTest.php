<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Functional\Draft;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftText;
use Janalis\Collaborate\Tests\Fixtures\Draft\InterleavingConnection;
use Janalis\Collaborate\Tests\Fixtures\Draft\StatementRecordingConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The journal's limits, validation and locking at their boundaries, on the database of {@see DraftJournalTest}
 * and its temporary tables.
 */
#[CoversClass(DraftJournal::class)]
#[UsesClass(DraftText::class)]
final class DraftJournalBoundariesTest extends TestCase
{
    private const int MAX_UPDATE_BYTES = 262_144;
    private const int MAX_DRAFT_BYTES = 10_485_760;
    private const int COMPACT_BYTES = 1_048_576;
    private const int CLEAN_DAYS = 7;

    private StatementRecordingConnection $db;
    private DraftJournal $journal;

    /** @var list<Connection> */
    private array $connections = [];

    /** @var array<string, array{type: string, initial: mixed}> */
    private array $fields = [
        'body' => ['type' => 'rich', 'initial' => 'abcd'],
        'choice' => ['type' => 'value', 'initial' => 'a'],
    ];

    /** @var array<string, array{type: string, initial: mixed}> */
    private const array COLLECTION = [
        'items[__collection__]' => ['type' => 'collection', 'initial' => []],
        'items[__member__]' => ['type' => 'member', 'initial' => ['alive' => false, 'order' => 0, 'recordId' => null]],
        'items[__name__][kind]' => ['type' => 'value', 'initial' => 'a'],
    ];

    protected function setUp(): void
    {
        $db = $this->connect(StatementRecordingConnection::class);
        \assert($db instanceof StatementRecordingConnection);
        $this->db = $db;
        $this->journal = new DraftJournal($this->db, firstGeneration: 1);
        $this->journal->join('record', $this->fields);
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->close();
        }
    }

    /**
     * A connection whose temporary draft tables shadow the real ones.
     *
     * @param class-string<Connection> $wrapperClass
     * @param array<int, mixed>        $driverOptions
     */
    private function connect(string $wrapperClass = Connection::class, array $driverOptions = []): Connection
    {
        $connection = [] === $driverOptions
            ? DraftJournalTest::connection($wrapperClass)
            : DriverManager::getConnection(['driverOptions' => $driverOptions]
            + DraftJournalTest::connection()->getParams());
        self::assertStringContainsString('test', (string) $connection->fetchOne('SELECT DATABASE()'));
        foreach (DraftJournalTest::schema() as $sql) {
            $connection->executeStatement(str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $sql));
            $connection->executeStatement(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $sql));
        }
        $this->connections[] = $connection;

        return $connection;
    }

    /**
     * A connection on the real tables, for races between connections.
     *
     * @param class-string<Connection> $wrapperClass
     */
    private function real(string $wrapperClass = Connection::class): Connection
    {
        $connection = DraftJournalTest::connection($wrapperClass);
        foreach (DraftJournalTest::schema() as $sql) {
            $connection->executeStatement(str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $sql));
        }
        $this->connections[] = $connection;

        return $connection;
    }

    private function locked(string $id): bool
    {
        return null !== $this->db->fetchOne('SELECT IS_USED_LOCK(?)', [$this->lockName($id)]);
    }

    private function lockName(string $id): string
    {
        return 'collaborate:' . substr(hash('xxh128', (string) $this->db->getDatabase()), 0, 16) . ':' . md5($id);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return array_replace([
            'id' => '12345678-1234-1234-1234-123456789abc',
            'generation' => 1,
            'seen' => 0,
            'field' => 'body',
            'kind' => 'operations',
            'version' => 2,
            'operations' => [['__className' => 'InsertOperation', 'baseVersion' => 2]],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function value(string $field, int $version, mixed $value, string $id, array $overrides = []): array
    {
        return $this->event([
            'id' => $id . '-batch-1234567890',
            'field' => $field,
            'kind' => 'value',
            'version' => $version,
            'value' => $value,
        ] + $overrides);
    }

    private function bytes(string $id): int
    {
        return (int) $this->db->fetchOne('SELECT bytes FROM collaborate_draft WHERE id = ?', [$id]);
    }

    private function idle(string $id, string $since): void
    {
        $this->db->update('collaborate_draft', ['updated_at' => $since], ['id' => $id]);
    }

    /** @param array<string, mixed> $event */
    private function encodedSize(array $event): int
    {
        return \strlen(json_encode($event, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    public function testAnUpdateMayTakeTheWholeDefaultLimitAndNoByteMore(): void
    {
        $padding = self::MAX_UPDATE_BYTES - $this->encodedSize($this->event(['padding' => '']));
        $largest = $this->event(['padding' => str_repeat('x', max(0, $padding))]);
        self::assertSame(self::MAX_UPDATE_BYTES, $this->encodedSize($largest));
        try {
            $this->journal->append(
                'record',
                $this->fields,
                ['padding' => str_repeat('x', max(0, $padding + 1))] + $largest,
                1,
                'Alice',
            );
            self::fail('An update over the default limit was accepted.');
        } catch (HttpException $error) {
            self::assertSame(413, $error->getStatusCode());
        }

        self::assertSame(1, $this->journal->append('record', $this->fields, $largest, 1, 'Alice')['revision']);
    }

    public function testADraftMayFillTheWholeDefaultJournalAndNoByteMore(): void
    {
        $event = $this->event();
        $this->db->update(
            'collaborate_draft',
            ['bytes' => self::MAX_DRAFT_BYTES - $this->encodedSize($event)],
            ['id' => 'record'],
        );

        $this->journal->append('record', $this->fields, $event, 1, 'Alice');

        // The journal counts what each update adds.
        self::assertSame(self::MAX_DRAFT_BYTES, $this->bytes('record'));
        $next = $this->event([
            'id' => 'next-batch-1234567890',
            'version' => 3,
            'operations' => [['__className' => 'InsertOperation', 'baseVersion' => 3]],
        ]);
        $this->db->update(
            'collaborate_draft',
            ['bytes' => self::MAX_DRAFT_BYTES - $this->encodedSize($next) + 1],
            ['id' => 'record'],
        );
        $this->expectException(HttpException::class);
        $this->journal->append('record', $this->fields, $next, 1, 'Alice');
    }

    public function testTheBytesOfSuccessiveUpdatesAddUp(): void
    {
        $first = $this->event();
        $second = $this->event([
            'id' => 'next-batch-1234567890',
            'version' => 3,
            'operations' => [['__className' => 'InsertOperation', 'baseVersion' => 3]],
        ]);

        $this->journal->append('record', $this->fields, $first, 1, 'Alice');
        $this->journal->append('record', $this->fields, $second, 1, 'Alice');

        self::assertSame($this->encodedSize($first) + $this->encodedSize($second), $this->bytes('record'));
    }

    public function testTheConfiguredJournalFieldAndNestingLimitsApply(): void
    {
        $small = new DraftJournal($this->db, limits: ['max_draft_bytes' => 100], firstGeneration: 1);
        try {
            $small->append('record', $this->fields, $this->event(), 1, 'Alice');
            self::fail('An update past the configured journal size was accepted.');
        } catch (HttpException $error) {
            self::assertSame(413, $error->getStatusCode());
        }

        $fields = [
            'title' => ['type' => 'value', 'initial' => ''],
            'items[__member__]' => self::COLLECTION['items[__member__]'],
        ];
        $few = new DraftJournal($this->db, limits: ['max_fields' => 1], firstGeneration: 1);
        $few->join('few', $fields);
        try {
            $few->append(
                'few',
                $fields,
                $this->value('items[@n1]', 0, ['alive' => true, 'order' => 1], 'entry'),
                1,
                'Alice',
            );
            self::fail('A field past the configured row limit was accepted.');
        } catch (HttpException $error) {
            self::assertSame(413, $error->getStatusCode());
        }

        $shallow = new DraftJournal($this->db, limits: ['max_value_depth' => 2], firstGeneration: 1);
        $this->expectException(BadRequestHttpException::class);
        $shallow->append('record', $this->fields, $this->value('choice', 0, [[['x']]], 'deep'), 1, 'Alice');
    }

    public function testALabelMayHoldTheConfiguredNumberOfCharactersWhateverItsBytes(): void
    {
        $journal = new DraftJournal($this->db, limits: ['label_length' => 3], firstGeneration: 1);

        $accepted = $journal->append('record', $this->fields, $this->value('choice', 0, 'x', 'label', ['labels' => [
            'x' => 'été',
        ]]), 1, 'Alice');

        self::assertSame(['x' => 'été'], $accepted['labels'] ?? null);
        $this->expectException(BadRequestHttpException::class);
        $journal->append('record', $this->fields, $this->value('choice', 1, 'y', 'longer', ['labels' => [
            'y' => 'abcd',
        ]]), 1, 'Alice');
    }

    public function testASaveWaitsTenSecondsForTheLockAndAnyOtherWriterOneSecond(): void
    {
        $this->db->queries = [];
        $this->db->queryParameters = [];

        $this->journal->lock('record');
        $this->journal->unlock('record');
        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');

        $waits = [];
        foreach ($this->db->queries as $index => $sql) {
            if (!str_contains($sql, 'GET_LOCK')) {
                continue;
            }
            $waits[] = $this->db->queryParameters[$index][1] ?? null;
        }
        self::assertSame([10, 1], $waits);
    }

    /** @return list<string> */
    private function releases(): array
    {
        return array_values(array_filter($this->db->queries, static fn(string $sql): bool => str_contains(
            $sql,
            'RELEASE_LOCK',
        )));
    }

    public function testANestedLockIsReleasedByItsLastUnlockAndAnUnheldOneIsNotReleased(): void
    {
        $other = DriverManager::getConnection(DraftJournalTest::connection()->getParams());
        $this->connections[] = $other;
        $this->db->queries = [];

        $this->journal->lock('nested');
        $this->journal->lock('nested');
        $this->journal->unlock('nested');
        self::assertSame(0, (int) $other->fetchOne('SELECT GET_LOCK(?, 0)', [$this->lockName('nested')]));
        $this->journal->unlock('nested');
        self::assertSame(1, (int) $other->fetchOne('SELECT GET_LOCK(?, 0)', [$this->lockName('nested')]));
        $this->journal->unlock('nested');

        self::assertCount(2, $this->releases());
    }

    public function testResetReleasesEveryNestingOfEveryHeldLockOnce(): void
    {
        $this->journal->lock('first');
        $this->journal->lock('first');
        $this->journal->lock('second');
        $this->db->queries = [];

        $this->journal->reset();

        self::assertFalse($this->locked('first'));
        self::assertFalse($this->locked('second'));
        $this->journal->unlock('first');
        $this->journal->reset();
        self::assertCount(3, $this->releases());
    }

    public function testASaveCheckOfARecordAlreadyCreatedPassesWhenTheSaveDoesNotCreateOne(): void
    {
        $this->journal->created('record', '42');

        $this->journal->lockSave('record', 1, 0, []);

        $this->expectException(ConflictHttpException::class);
        $this->journal->lockSave('record', 1, 0, [], true);
    }

    /**
     * Runs `$write` on a journal over the real tables, and an outside update of the draft row just before the
     * write's statement matching `$pattern`.
     *
     * @param \Closure(DraftJournal, string): void $write
     */
    private function outsideUpdateBlocks(
        string $pattern,
        \Closure $write,
        string $statement = 'UPDATE collaborate_draft SET bytes = bytes WHERE id = ?',
    ): bool {
        $connection = $this->real(InterleavingConnection::class);
        \assert($connection instanceof InterleavingConnection);
        $outside = $this->real();
        $outside->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        $id = 'row-lock-' . bin2hex(random_bytes(8));
        $journal = new DraftJournal($connection, firstGeneration: 1);
        $blocked = null;
        try {
            $journal->join($id, $this->fields);
            $journal->append($id, $this->fields, $this->event(), 1, 'Alice');
            $connection->pattern = $pattern;
            $connection->before = static function () use ($outside, $id, $statement, &$blocked): void {
                try {
                    $outside->executeStatement($statement, [$id]);
                    $blocked = false;
                } catch (LockWaitTimeoutException) {
                    $blocked = true;
                }
            };
            $write($journal, $id);
        } finally {
            foreach (['collaborate_draft_event', 'collaborate_draft_field'] as $table) {
                $outside->delete($table, ['draft_id' => $id]);
            }
            $outside->delete('collaborate_draft', ['id' => $id]);
        }
        self::assertIsBool($blocked);

        return $blocked;
    }

    public function testACheckpointHoldsTheDraftRowUntilItCommits(): void
    {
        self::assertTrue($this->outsideUpdateBlocks('UPDATE collaborate_draft SET', static fn(
            DraftJournal $journal,
            string $id,
        ) => $journal->saved($id, ['choice' => 'b'])));
    }

    public function testADiscardHoldsTheDraftRowUntilItCommits(): void
    {
        self::assertTrue($this->outsideUpdateBlocks('DELETE FROM collaborate_draft_event', fn(
            DraftJournal $journal,
            string $id,
        ) => $journal->discard($id, 1, 1, $this->fields)));
    }

    public function testPurgeSkipsADraftInUseAndGoesOnWithTheNextOnes(): void
    {
        $old = new \DateTimeImmutable('-' . (self::CLEAN_DAYS + 1) . ' days')->format('Y-m-d H:i:s');
        foreach (['a-held', 'b-clean', 'c-clean'] as $id) {
            $this->journal->join($id, $this->fields);
            $this->idle($id, $old);
        }
        $other = DriverManager::getConnection(DraftJournalTest::connection()->getParams());
        $this->connections[] = $other;
        $other->fetchOne('SELECT GET_LOCK(?, 0)', [$this->lockName('a-held')]);

        $started = microtime(true);
        self::assertSame(2, $this->journal->purge(new \DateTimeImmutable()));
        // It does not wait for a draft in use.
        self::assertLessThan(0.5, microtime(true) - $started);

        self::assertSame(
            ['a-held', 'record'],
            $this->db->fetchFirstColumn('SELECT id FROM collaborate_draft ORDER BY id'),
        );
    }

    public function testPurgeReleasesTheLockOfADraftItFailedToDelete(): void
    {
        $connection = $this->connect(InterleavingConnection::class);
        \assert($connection instanceof InterleavingConnection);
        $journal = new DraftJournal($connection, firstGeneration: 1);
        $journal->join('failing', $this->fields);
        $connection->update(
            'collaborate_draft',
            ['updated_at' => new \DateTimeImmutable('-' . (self::CLEAN_DAYS + 1) . ' days')->format('Y-m-d H:i:s')],
            ['id' => 'failing'],
        );
        $connection->pattern = 'DELETE FROM collaborate_draft WHERE';
        $connection->before = static function (): void {
            throw new \RuntimeException('The database went away.');
        };

        try {
            $journal->purge(new \DateTimeImmutable());
            self::fail('The failure was swallowed.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The database went away.', $failure->getMessage());
        }

        self::assertFalse($this->locked('failing'));
    }

    public function testPurgeCountsNoDraftWrittenAfterItWasSelected(): void
    {
        $connection = $this->connect(InterleavingConnection::class);
        \assert($connection instanceof InterleavingConnection);
        $journal = new DraftJournal($connection, firstGeneration: 1);
        $journal->join('written', $this->fields);
        $connection->update(
            'collaborate_draft',
            ['updated_at' => new \DateTimeImmutable('-' . (self::CLEAN_DAYS + 1) . ' days')->format('Y-m-d H:i:s')],
            ['id' => 'written'],
        );
        $connection->pattern = 'DELETE FROM collaborate_draft WHERE';
        $connection->before = static function () use ($connection): void {
            $connection->update(
                'collaborate_draft',
                ['updated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s')],
                ['id' => 'written'],
            );
        };

        self::assertSame(0, $journal->purge(new \DateTimeImmutable()));
        self::assertSame(['written'], $connection->fetchFirstColumn('SELECT id FROM collaborate_draft'));
    }

    public function testTheConfiguredUnsavedLifetimeApplies(): void
    {
        $journal = new DraftJournal($this->db, limits: ['purge' => ['unsaved_days' => 1]], firstGeneration: 1);
        $journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        $this->idle('record', new \DateTimeImmutable('-2 days')->format('Y-m-d H:i:s'));

        self::assertSame(1, $journal->purge(new \DateTimeImmutable()));
    }

    public function testJoinStoresTheFieldsListedAfterACollectionTemplate(): void
    {
        $view = $this->journal->join('template-first', [
            'items[__name__][kind]' => ['type' => 'value', 'initial' => 'a'],
            'title' => ['type' => 'value', 'initial' => 'Title'],
        ]);

        self::assertSame(['title'], array_keys($view['fields']));
    }

    public function testJoinCompactsADraftPastTheDefaultSizeAfterAMinuteIdle(): void
    {
        $this->db->update('collaborate_draft', ['bytes' => self::COMPACT_BYTES], ['id' => 'record']);
        $this->idle('record', '2000-01-01 00:00:00');
        self::assertSame(1, $this->journal->join('record', $this->fields)['generation']);

        $this->db->update('collaborate_draft', ['bytes' => self::COMPACT_BYTES + 1], ['id' => 'record']);
        $this->idle('record', new \DateTimeImmutable('-60 seconds -500 milliseconds')->format('Y-m-d H:i:s.u'));
        self::assertSame(2, $this->journal->join('record', $this->fields)['generation']);
    }

    public function testTheConfiguredCompactionSizeApplies(): void
    {
        $journal = new DraftJournal($this->db, limits: ['compact' => ['bytes' => 10]], firstGeneration: 1);
        $this->db->update('collaborate_draft', ['bytes' => 11], ['id' => 'record']);
        $this->idle('record', '2000-01-01 00:00:00');

        self::assertSame(2, $journal->join('record', $this->fields)['generation']);
    }

    public function testACompactionNamesEveryGenerationItReplacedAndNoEventCarriesThem(): void
    {
        $before = $this->journal->join('record', $this->fields);
        self::assertSame([], $before['rebasedFrom']);

        $this->db->update('collaborate_draft', ['bytes' => self::COMPACT_BYTES + 1], ['id' => 'record']);
        $this->idle('record', '2000-01-01 00:00:00');
        $compacted = $this->journal->join('record', $this->fields);
        self::assertSame(2, $compacted['generation']);
        self::assertSame([1], $compacted['rebasedFrom']);

        // A page still on generation 1 replays from its revision: it reads the generation replaced, and no event.
        $stale = $this->journal->replay('record', $this->fields, $before['revision']);
        self::assertSame([1], $stale['rebasedFrom']);
        self::assertSame([], $stale['events']);
        // A page of generation 2 replays from the compaction's revision on, and needs them no more.
        self::assertSame([], $this->journal->replay('record', $this->fields, $compacted['revision'])['rebasedFrom']);

        // The draft goes on from the compaction's revision; a checkpoint counts the batches of its journal alone.
        $after = $this->value('choice', 0, 'b', 'after', ['generation' => 2, 'seen' => $compacted['revision']]);
        $this->journal->append('record', $this->fields, $after, 1, 'Alice');
        $this->journal->saved('record', ['choice' => 'b']);
        self::assertSame(
            (int) $this->db->fetchOne(
                "SELECT LENGTH(payload) FROM collaborate_draft_event WHERE draft_id = 'record' AND batch_id <> 'rebase'",
            ),
            $this->bytes('record'),
        );
        $this->db->update('collaborate_draft', ['bytes' => self::COMPACT_BYTES + 1], ['id' => 'record']);
        $this->idle('record', '2000-01-01 00:00:00');
        self::assertSame([1, 2], $this->journal->join('record', $this->fields)['rebasedFrom']);

        // Each compaction adds its own to the list.
        $this->db->update('collaborate_draft', ['bytes' => self::COMPACT_BYTES + 1], ['id' => 'record']);
        $this->idle('record', '2000-01-01 00:00:00');
        self::assertSame([1, 2, 3], $this->journal->join('record', $this->fields)['rebasedFrom']);
    }

    /** A saved, idle draft of three field rows: its collection, and the member and field of a removed entry. */
    private function removedEntry(string $id): void
    {
        $this->journal->join($id, self::COLLECTION);
        foreach ([
            ['items[@n1]', 0, ['alive' => true, 'order' => 1, 'recordId' => null]],
            ['items[n1][kind]', 0, 'b'],
            ['items[@n1]', 1, ['alive' => false, 'order' => 1]],
        ] as $index => [$field, $version, $value]) {
            $this->journal->append(
                $id,
                self::COLLECTION,
                $this->value($field, $version, $value, 'removed-' . $index),
                1,
                'Alice',
            );
        }
        $this->journal->saved($id, ['items[__collection__]' => []]);
        $this->idle($id, '2000-01-01 00:00:00');
    }

    public function testADraftOfAsManyRowsAsTheConfiguredCompactionThresholdIsNotCompacted(): void
    {
        $this->removedEntry('rows');

        $kept = new DraftJournal($this->db, limits: ['compact' => ['fields' => 3]], firstGeneration: 1)->join(
            'rows',
            self::COLLECTION,
        );
        $compacted = new DraftJournal($this->db, limits: ['compact' => ['fields' => 2]], firstGeneration: 1)->join(
            'rows',
            self::COLLECTION,
        );

        self::assertSame(1, $kept['generation']);
        self::assertCount(3, $kept['fields']);
        self::assertSame(2, $compacted['generation']);
        self::assertSame(['items[__collection__]'], array_keys($compacted['fields']));
    }

    public function testCompactionDropsTheFieldsOfAnEntryTheSavedRecordNoLongerHoldsAndOnlyThisDraftsJournal(): void
    {
        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        $this->journal->join('gone', self::COLLECTION);
        $this->journal->append(
            'gone',
            self::COLLECTION,
            $this->value('items[@n1]', 0, ['alive' => true, 'order' => 1, 'recordId' => null], 'added'),
            1,
            'Alice',
        );
        $this->journal->append('gone', self::COLLECTION, $this->value('items[n1][kind]', 0, 'b', 'kind'), 1, 'Alice');
        // The record was saved without the entry: its member keeps a tombstone as its saved state.
        $this->journal->saved('gone', ['items[__collection__]' => [], 'items[n1][kind]' => 'b']);
        self::assertSame(
            ['alive' => false, 'order' => 0, 'recordId' => null],
            $this->journal->replay('gone', self::COLLECTION, 0)['fields']['items[@n1]']['saved'],
        );
        $this->db->update('collaborate_draft', ['bytes' => self::COMPACT_BYTES + 1], ['id' => 'gone']);
        $this->idle('gone', '2000-01-01 00:00:00');

        $compacted = $this->journal->join('gone', self::COLLECTION);

        self::assertSame(2, $compacted['generation']);
        self::assertSame(['items[__collection__]'], array_keys($compacted['fields']));
        self::assertCount(1, $this->journal->replay('record', $this->fields, 0)['events']);
    }

    /** Removes the `savedAt` of a field state, as stored before the journal recorded it. */
    private function withoutSavedAt(string $id, string $name): void
    {
        /** @var array<string, mixed> $state */
        $state = json_decode(
            (string) $this->db->fetchOne('SELECT state FROM collaborate_draft_field WHERE draft_id = ? AND name = ?', [
                $id,
                $name,
            ]),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        unset($state['savedAt']);
        $this->db->update(
            'collaborate_draft_field',
            ['state' => json_encode($state, \JSON_THROW_ON_ERROR)],
            [
                'draft_id' => $id,
                'name' => $name,
            ],
        );
    }

    public function testAFieldStateWithoutItsSaveTimeConflictsWithAnyGrantButNoneByDefault(): void
    {
        $this->withoutSavedAt('record', 'choice');
        $changed = ['choice' => ['type' => 'value', 'initial' => 'z']] + $this->fields;

        self::assertSame([], $this->journal->replay('record', $changed, 0)['conflicts']);
        self::assertSame(['choice' => 'z'], $this->journal->replay('record', $changed, 0, 0.5)['conflicts']);
        self::assertSame([], $this->journal->discard('record', 1, 0, $changed)['conflicts']);
        self::assertSame([], $this->journal->join('record', $changed)['conflicts']);
    }

    public function testAGrantIssuedBeforeTheLastSaveSeesNoConflict(): void
    {
        $changed = ['choice' => ['type' => 'value', 'initial' => 'z']] + $this->fields;

        self::assertSame([], $this->journal->replay('record', $changed, 0, 1.0)['conflicts']);
        self::assertSame(
            ['choice' => 'z'],
            $this->journal->replay('record', $changed, 0, microtime(true) + 60)['conflicts'],
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidEnvelopes(): iterable
    {
        yield 'identifier that is no string' => [['id' => 1_234_567_890_123_456]];
        yield 'generation that is no integer' => [['generation' => '1']];
        yield 'no seen revision' => [['seen' => null]];
        yield 'identifier after another character' => [['id' => '!' . str_repeat('a', 16)]];
        yield 'identifier before a line break' => [['id' => str_repeat('a', 16) . "\n"]];
        yield 'initial version past the largest' => [[
            'version' => 100_001,
            'operations' => [['__className' => 'InsertOperation', 'baseVersion' => 100_001]],
        ]];
        yield 'negative initial version' => [[
            'version' => -1,
            'operations' => [['__className' => 'InsertOperation', 'baseVersion' => -1]],
        ]];
        yield 'operations that are no list' => [[
            'operations' => [1 => ['__className' => 'InsertOperation', 'baseVersion' => 3]],
        ]];
        yield 'too many operations' => [[
            'operations' => array_map(
                static fn(int $version): array => ['__className' => 'InsertOperation', 'baseVersion' => $version],
                range(2, 2050),
            ),
        ]];
        yield 'operation without a class' => [['operations' => [['baseVersion' => 2]]]];
        yield 'value sent as operations' => [[
            'field' => 'choice',
            'version' => 0,
            'value' => 'b',
            'operations' => null,
        ]];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidEnvelopes')]
    public function testAnInvalidEnvelopeIsRefusedWithoutAppending(array $overrides): void
    {
        $event = array_filter($this->event($overrides), static fn(mixed $value): bool => null !== $value);
        try {
            $this->journal->append('record', $this->fields, $event, 1, 'Alice');
            self::fail('An invalid event was accepted.');
        } catch (BadRequestHttpException) {
            self::assertSame(0, $this->journal->replay('record', $this->fields, 0)['revision']);
        }
    }

    public function testAMembershipNeedsAFlagAndAnOrder(): void
    {
        $this->journal->join('members', self::COLLECTION);

        $this->expectException(BadRequestHttpException::class);
        $this->journal->append(
            'members',
            self::COLLECTION,
            $this->value('items[@n1]', 0, ['alive' => 'yes', 'order' => 1], 'flag'),
            1,
            'Alice',
        );
    }

    public function testTheLargestInitialVersionAndTheLargestBatchAreAccepted(): void
    {
        $initial = $this->journal->append(
            'record',
            $this->fields,
            $this->event([
                'version' => 100_000,
                'operations' => [['__className' => 'InsertOperation', 'baseVersion' => 100_000]],
            ]),
            1,
            'Alice',
        );
        self::assertSame(100_000, $initial['version']);

        $this->journal->join('batch', $this->fields);
        $this->journal->append(
            'batch',
            $this->fields,
            $this->event([
                'operations' => array_map(
                    static fn(int $version): array => ['__className' => 'InsertOperation', 'baseVersion' => $version],
                    range(2, 2049),
                ),
            ]),
            1,
            'Alice',
        );
        self::assertSame(2050, $this->journal->replay('batch', $this->fields, 0)['fields']['body']['version']);
    }

    public function testAnEditMadeBeforeAnyRevisionWasSeenIsRefused(): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->journal->append('record', $this->fields, $this->event(['seen' => -1]), 1, 'Alice');
    }

    public function testATextRemovalFillsTheGraveyardThatALaterInsertMayTarget(): void
    {
        $fields = ['title' => ['type' => 'text', 'initial' => 'abc']];
        $this->journal->join('text', $fields);
        $this->journal->append(
            'text',
            $fields,
            $this->event([
                'field' => 'title',
                'operations' => [[
                    '__className' => 'MoveOperation',
                    'baseVersion' => 2,
                    'howMany' => 1,
                    'sourcePosition' => ['root' => 'main', 'path' => [0, 0]],
                    'targetPosition' => ['root' => '$graveyard', 'path' => [0]],
                ]],
            ]),
            1,
            'Alice',
        );
        $this->journal->append(
            'text',
            $fields,
            $this->event([
                'id' => 'graveyard-batch-12345',
                'field' => 'title',
                'version' => 3,
                'operations' => [[
                    '__className' => 'InsertOperation',
                    'baseVersion' => 3,
                    'position' => ['root' => '$graveyard', 'path' => [1]],
                    'nodes' => [['data' => 'x']],
                ]],
            ]),
            1,
            'Alice',
        );

        $title = $this->journal->replay('text', $fields, 0)['fields']['title'];
        self::assertSame('bc', $title['value']);
        self::assertSame(2, $title['graveyardLength'] ?? null);

        // A checkpoint's own removal adds to the graveyard the editors filled.
        $this->journal->saved('text', ['title' => 'c']);
        self::assertSame(3, $this->journal->replay('text', $fields, 0)['fields']['title']['graveyardLength'] ?? null);
    }

    public function testACheckpointNormalizesTextsFromTheModelVersionTheirEditorsStartAt(): void
    {
        $fields = [
            'edited' => ['type' => 'text', 'initial' => 'ab'],
            'empty' => ['type' => 'text', 'initial' => ''],
            'filled' => ['type' => 'text', 'initial' => 'ab'],
        ];
        $this->journal->join('texts', $fields);
        $this->journal->append(
            'texts',
            $fields,
            $this->event([
                'field' => 'edited',
                'version' => 5,
                'operations' => [[
                    '__className' => 'InsertOperation',
                    'baseVersion' => 5,
                    'position' => ['root' => 'main', 'path' => [0, 2]],
                    'nodes' => [['data' => 'X']],
                ]],
            ]),
            1,
            'Alice',
        );

        $this->journal->saved('texts', ['unknown' => 'ignored', 'empty' => 'x', 'filled' => 'a', 'edited' => 'zz']);

        $view = $this->journal->replay('texts', $fields, 1);
        self::assertSame(
            [
                ['empty',  1, 0, 'System'],
                ['filled', 2, 0, 'System'],
                ['edited', 6, 0, 'System'],
            ],
            array_map(static fn(array $event): array => [
                $event['field'],
                $event['version'],
                $event['userId'],
                $event['label'],
            ], $view['events']),
        );
        self::assertSame(1, $view['fields']['filled']['graveyardLength'] ?? null);
        self::assertSame(5, $view['fields']['edited']['initialVersion'] ?? null);
        self::assertSame('zz', $view['fields']['edited']['saved']);
        // Its removal and its insertion advance the model by two versions.
        self::assertSame(8, $view['fields']['edited']['version']);
    }

    public function testAFieldWhoseKeyDiffersFromItsFormNameFollowsTheEntryItsFormNameIsIn(): void
    {
        $fields = [
            'items[__member__]' => self::COLLECTION['items[__member__]'],
            'fr|items[__name__][title]' => ['type' => 'value', 'initial' => '', 'formName' => 'items[__name__][title]'],
        ];
        $this->journal->join('keys', $fields);
        foreach ([
            ['items[@n1]', 0, ['alive' => true, 'order' => 1, 'recordId' => null]],
            ['fr|items[n1][title]', 0, 'x'],
            ['items[@n1]', 1, ['alive' => false, 'order' => 1]],
        ] as $index => [$field, $version, $value]) {
            $this->journal->append(
                'keys',
                $fields,
                $this->value($field, $version, $value, 'keys-' . $index),
                1,
                'Alice',
            );
        }

        $this->journal->saved('keys', []);

        $changed =
            ['fr|items[__name__][title]' => ['initial' => 'outside'] + $fields['fr|items[__name__][title]']] + $fields;
        $view = $this->journal->replay('keys', $changed, 0, microtime(true) + 60);
        // The removed entry's field holds nothing to save, and differs from no record value.
        self::assertSame(1, $view['fields']['fr|items[n1][title]']['savedVersion'] ?? null);
        self::assertSame([], $view['conflicts']);
    }

    public function testADiscardIsJournalledAsTheSystemsUnlessAnEditorIsNamed(): void
    {
        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        $this->journal->append('record', $this->fields, $this->value('choice', 0, 'b', 'choice'), 1, 'Alice');

        $view = $this->journal->discard('record', 1, 2, ['choice' => $this->fields['choice']]);

        // The Discard deleted the choice's batch; the body's is another form's.
        self::assertCount(1, $view['events']);
        $discard = $view['events'][0];
        unset($discard['id']);
        self::assertSame(
            [
                'field' => '',
                'kind' => 'discard',
                'version' => 0,
                'revision' => 3,
                'userId' => 0,
                'label' => 'System',
                'fields' => ['choice'],
            ],
            $discard,
        );
        // The journal stores the reset fields as a list.
        self::assertStringContainsString(
            '"fields":["choice"]',
            (string) $this->db->fetchOne('SELECT payload FROM collaborate_draft_event WHERE draft_id = ? AND revision = 3', [
                'record',
            ]),
        );
    }

    public function testAFormReadsTheDiscardsOfItsOwnFieldsAndTheEventsAfterOthers(): void
    {
        $choice = ['choice' => $this->fields['choice']];
        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        $this->journal->append('record', $this->fields, $this->value('choice', 0, 'b', 'choice'), 1, 'Alice');
        $this->journal->discard('record', 1, 2, $this->fields);
        self::assertSame(
            ['body', 'choice'],
            $this->journal->replay('record', $this->fields, 2)['events'][0]['fields'] ?? null,
        );
        self::assertSame(['choice'], $this->journal->replay('record', $choice, 2)['events'][0]['fields'] ?? null);

        $this->journal->append(
            'record',
            $this->fields,
            $this->event(['id' => 'body-again-1234567890', 'seen' => 3]),
            1,
            'Alice',
        );
        $this->journal->discard('record', 1, 4, ['body' => $this->fields['body']]);
        $this->journal->append('record', $this->fields, $this->value('choice', 0, 'c', 'after', [
            'seen' => 5,
        ]), 1, 'Alice');

        self::assertSame(['value'], array_column($this->journal->replay('record', $choice, 3)['events'], 'kind'));
    }

    public function testADraftWhoseEveryEditWasDiscardedIsPurgedLikeASavedOne(): void
    {
        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        $this->journal->discard('record', 1, 1, $this->fields);
        $this->idle('record', new \DateTimeImmutable('-' . (self::CLEAN_DAYS + 1) . ' days')->format('Y-m-d H:i:s'));

        self::assertSame(1, $this->journal->purge(new \DateTimeImmutable()));
    }

    public function testAPlainListReopensUnderTheTextOfItsSavedKeys(): void
    {
        $this->journal->join('list', ['items[__collection__]' => self::COLLECTION['items[__collection__]']]);

        $this->journal->saved('list', ['items[__collection__]' => [3, 5]]);

        self::assertSame(['items' => ['3', '5']], $this->journal->listKeys('list'));
    }

    public function testOnlyAMemberWhoseStateSaysItIsNoLongerAliveRemovesItsEntry(): void
    {
        $fields = [
            'archived' => ['type' => 'member', 'initial' => ['alive' => false, 'order' => 0]],
            'items[@n0]' => ['type' => 'member', 'initial' => null],
            'items[@n1]' => ['type' => 'member', 'initial' => ['alive' => false, 'order' => 0]],
            'items[@n2]' => ['type' => 'member', 'initial' => ['order' => 0]],
            'items[__name__][kind]' => ['type' => 'value', 'initial' => 'a'],
        ];
        $this->journal->join('members', $fields);

        $this->journal->append('members', $fields, $this->value('items[n0][kind]', 0, 'b', 'n0'), 1, 'Alice');
        foreach (['n1', 'n2'] as $entry) {
            try {
                $this->journal->append(
                    'members',
                    $fields,
                    $this->value("items[{$entry}][kind]", 0, 'b', $entry),
                    1,
                    'Alice',
                );
                self::fail('An edit of a removed entry was accepted.');
            } catch (ConflictHttpException $conflict) {
                self::assertSame('The collection entry was removed.', $conflict->getMessage());
            }
        }
    }

    public function testFieldStatesAreWrittenAHundredToAStatement(): void
    {
        $fields = [];
        foreach (range(1, 101) as $index) {
            $fields['field' . $index] = ['type' => 'value', 'initial' => ''];
        }
        $this->db->parameters = [];

        $this->journal->join('many', $fields);

        $rows = array_values(array_filter(
            array_map(\count(...), $this->db->parameters),
            static fn(int $count): bool => 0 === ($count % 4),
        ));
        self::assertSame([400, 4], $rows);
    }

    public function testTheJournalReadsADriverThatReturnsEveryColumnAsAString(): void
    {
        $connection = $this->connect(driverOptions: [\PDO::ATTR_STRINGIFY_FETCHES => true]);
        self::assertSame('1', $connection->fetchOne('SELECT 1'));
        $journal = new DraftJournal($connection, firstGeneration: 1);
        $journal->join('record', $this->fields);
        $journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        $journal->saved('record', ['body' => 'edited', 'choice' => 'b'], ['userId' => 7, 'label' => 'Alice']);

        $view = $journal->replay('record', $this->fields, 0);

        self::assertSame([1, 2, 2], [$view['generation'], $view['revision'], $view['savedRevision']]);
        self::assertSame(['userId' => 7, 'label' => 'Alice'], $view['savedBy']);
        self::assertTrue($journal->unchanged('record', 2, $view['stamp']));

        // Switching the shared source mode of a saved draft leaves it saved.
        $mode = ['body[__mode__]' => ['type' => 'value', 'initial' => 'wysiwyg']];
        $journal->join('mode', $mode);
        $journal->append('mode', $mode, $this->value('body[__mode__]', 0, 'source', 'mode'), 1, 'Alice');
        self::assertSame(
            ['1', '1'],
            array_values((array) $connection->fetchAssociative('SELECT revision, saved_revision FROM collaborate_draft WHERE id = ?', [
                'mode',
            ])),
        );
    }

    public function testJoinCompactsOnlyADraftIdleForAMinuteByDefaultOrForTheConfiguredTime(): void
    {
        $this->db->update('collaborate_draft', ['bytes' => self::COMPACT_BYTES + 1], ['id' => 'record']);
        $this->idle('record', new \DateTimeImmutable('-59 seconds -500 milliseconds')->format('Y-m-d H:i:s.u'));
        self::assertSame(1, $this->journal->join('record', $this->fields)['generation']);

        $this->idle('record', new \DateTimeImmutable('-2 minutes')->format('Y-m-d H:i:s.u'));
        $patient = new DraftJournal($this->db, limits: ['compact' => ['idle' => 3600]], firstGeneration: 1);
        self::assertSame(1, $patient->join('record', $this->fields)['generation']);
    }

    public function testAnUnsavedDraftIsKeptNinetyDaysByDefault(): void
    {
        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        $this->idle('record', new \DateTimeImmutable('-89 days -12 hours')->format('Y-m-d H:i:s'));

        self::assertSame(0, $this->journal->purge(new \DateTimeImmutable()));
    }

    public function testOnlyTheCollectionsThatStoreAPlainListReopenUnderTheirSavedKeys(): void
    {
        $fields = [
            'a[__collection__]' => self::COLLECTION['items[__collection__]'],
            'items[__collection__]' => self::COLLECTION['items[__collection__]'],
            'tags' => self::COLLECTION['items[__collection__]'],
            'z[__collection__]' => self::COLLECTION['items[__collection__]'],
        ];
        $this->journal->join('lists', $fields);

        $this->journal->saved('lists', [
            'a[__collection__]' => ['k' => 'v'],
            'items[__collection__]' => ['x'],
            'tags' => ['t'],
            'z[__collection__]' => ['y'],
        ]);

        self::assertSame(['items' => ['x'], 'z' => ['y']], $this->journal->listKeys('lists'));
    }

    public function testTheEntriesOfEveryCollectionAreAliasedByTheTextOfTheirRecordIds(): void
    {
        $fields = [
            'a[__member__]' => self::COLLECTION['items[__member__]'],
            'items[__member__]' => self::COLLECTION['items[__member__]'],
            'tags[__member__]' => self::COLLECTION['items[__member__]'],
        ];
        $this->journal->join('aliases', $fields);
        // An entry not saved yet has no record id to alias.
        $this->journal->append(
            'aliases',
            $fields,
            $this->value('a[@n0]', 0, ['alive' => true, 'order' => 1, 'recordId' => null], 'unsaved'),
            1,
            'Alice',
        );
        $this->journal->append(
            'aliases',
            $fields,
            $this->value('items[@n1]', 0, ['alive' => true, 'order' => 1, 'recordId' => null], 'item'),
            1,
            'Alice',
        );
        $this->journal->append(
            'aliases',
            $fields,
            $this->value('tags[@n2]', 0, ['alive' => true, 'order' => 1, 'recordId' => null], 'tag'),
            1,
            'Alice',
        );

        $this->journal->saved('aliases', [
            'items[@n1]' => ['alive' => true, 'order' => 1, 'recordId' => 1.5],
            'tags[@n2]' => ['alive' => true, 'order' => 1, 'recordId' => 7],
        ]);

        self::assertSame(['items' => ['1.5' => 'n1'], 'tags' => [7 => 'n2']], $this->journal->aliases('aliases'));
    }

    public function testAnEntryRemovedFromANestedCollectionTakesItsFieldsWithIt(): void
    {
        $fields = [
            'a[b][__member__]' => self::COLLECTION['items[__member__]'],
            'a[b][__name__][kind]' => ['type' => 'value', 'initial' => 'a'],
        ];
        $this->journal->join('nested', $fields);
        $this->journal->append(
            'nested',
            $fields,
            $this->value('a[b][@n1]', 0, ['alive' => false, 'order' => 1], 'removed'),
            1,
            'Alice',
        );

        $this->expectException(ConflictHttpException::class);
        $this->journal->append('nested', $fields, $this->value('a[b][n1][kind]', 0, 'b', 'kind'), 1, 'Alice');
    }

    public function testAReplayTakesNoRowLock(): void
    {
        self::assertFalse($this->outsideUpdateBlocks('SELECT payload', fn(
            DraftJournal $journal,
            string $id,
        ) => $journal->replay($id, $this->fields, 0)));
    }

    public function testAJournalOneRowShortOfTheDefaultLimitStillTakesANewEntry(): void
    {
        $fields = [
            'title' => ['type' => 'value', 'initial' => ''],
            'items[__member__]' => self::COLLECTION['items[__member__]'],
        ];
        $this->journal->join('almost', $fields);
        foreach (array_chunk(range(1, 9998), 1000) as $chunk) {
            $this->db->executeStatement(
                'INSERT INTO collaborate_draft_field (draft_id, name, type, state) VALUES '
                    . implode(', ', array_fill(0, \count($chunk), "('almost', ?, 'text', '{}')")),
                array_map(static fn(int $index): string => "items[p{$index}][title]", $chunk),
            );
        }

        $accepted = $this->journal->append(
            'almost',
            $fields,
            $this->value('items[@n1]', 0, ['alive' => true, 'order' => 1], 'entry'),
            1,
            'Alice',
        );

        self::assertSame('items[@n1]', $accepted['field']);
    }

    public function testAValueMayNestEightLevelsByDefault(): void
    {
        $value = 'x';
        foreach (range(1, 8) as $_) {
            $value = [$value];
        }
        $this->journal->append('record', $this->fields, $this->value('choice', 0, $value, 'eight'), 1, 'Alice');

        $this->expectException(BadRequestHttpException::class);
        $this->journal->append('record', $this->fields, $this->value('choice', 1, [$value], 'nine'), 1, 'Alice');
    }

    public function testADraftPastFiveThousandRowsIsCompactedToDropItsRemovedEntries(): void
    {
        $this->removedEntry('rows');
        $state =
            (string) $this->db->fetchOne('SELECT state FROM collaborate_draft_field WHERE draft_id = ? AND name = ?', [
                'rows',
                'items[n1][kind]',
            ]);
        $pad = function (int $from, int $to) use ($state): void {
            foreach (array_chunk(range($from, $to), 1000) as $chunk) {
                $this->db->executeStatement(
                    'INSERT INTO collaborate_draft_field (draft_id, name, type, state) VALUES '
                        . implode(', ', array_fill(0, \count($chunk), "('rows', ?, 'value', ?)")),
                    array_merge(...array_map(static fn(int $index): array => [
                        "items[n1][sub{$index}]",
                        $state,
                    ], $chunk)),
                );
            }
        };
        $pad(4, 5000);
        self::assertSame(1, $this->journal->join('rows', self::COLLECTION)['generation']);

        $pad(5001, 5001);
        $compacted = $this->journal->join('rows', self::COLLECTION);

        self::assertSame(2, $compacted['generation']);
        self::assertSame(['items[__collection__]'], array_keys($compacted['fields']));
    }

    public function testTheConfiguredLockWaitsApply(): void
    {
        $journal = new DraftJournal($this->db, limits: ['locks' => ['save' => 3, 'writer' => 2]], firstGeneration: 1);
        $this->db->queries = [];
        $this->db->queryParameters = [];

        $journal->lock('record');
        $journal->unlock('record');
        $journal->append('record', $this->fields, $this->event(), 1, 'Alice');

        $waits = [];
        foreach ($this->db->queries as $index => $sql) {
            if (!str_contains($sql, 'GET_LOCK')) {
                continue;
            }
            $waits[] = $this->db->queryParameters[$index][1] ?? null;
        }
        self::assertSame([3, 2], $waits);
    }

    public function testTheConfiguredCleanLifetimeApplies(): void
    {
        $journal = new DraftJournal($this->db, limits: ['purge' => ['clean_days' => 1]], firstGeneration: 1);
        $this->idle('record', new \DateTimeImmutable('-2 days')->format('Y-m-d H:i:s'));

        self::assertSame(1, $journal->purge(new \DateTimeImmutable()));
    }

    /** Its INSERT IGNORE shares the row; its read takes it alone, so no other transaction reads it meanwhile. */
    public function testJoinHoldsTheDraftRowAloneUntilItCommits(): void
    {
        self::assertTrue($this->outsideUpdateBlocks(
            'UPDATE collaborate_draft SET',
            fn(DraftJournal $journal, string $id) => $journal->join(
                $id,
                $this->fields
                + [
                    'added' => ['type' => 'value', 'initial' => ''],
                ],
            ),
            'SELECT id FROM collaborate_draft WHERE id = ? LOCK IN SHARE MODE',
        ));
    }

    public function testAnAppendHoldsTheDraftRowUntilItCommits(): void
    {
        self::assertTrue($this->outsideUpdateBlocks('INSERT INTO collaborate_draft_event', fn(
            DraftJournal $journal,
            string $id,
        ) => $journal->append($id, $this->fields, $this->value('choice', 0, 'b', 'locked'), 1, 'Alice')));
    }

    public function testRecordingTheCreatedRecordHoldsTheDraftRowUntilItCommits(): void
    {
        self::assertTrue($this->outsideUpdateBlocks('UPDATE collaborate_draft SET', static fn(
            DraftJournal $journal,
            string $id,
        ) => $journal->created($id, '42')));
    }

    public function testAReconciliationHoldsTheDraftRowUntilItCommits(): void
    {
        self::assertTrue($this->outsideUpdateBlocks('UPDATE collaborate_draft SET', fn(
            DraftJournal $journal,
            string $id,
        ) => $journal->reconcile($id, 1, 1, $this->fields, true)));
    }

    public function testCompactionDropsAFieldWhoseFormNameIsInARemovedEntry(): void
    {
        $fields = [
            'items[__member__]' => self::COLLECTION['items[__member__]'],
            'fr|items[__name__][title]' => ['type' => 'value', 'initial' => '', 'formName' => 'items[__name__][title]'],
        ];
        $this->journal->join('translated', $fields);
        foreach ([
            ['items[@n1]', 0, ['alive' => true, 'order' => 1, 'recordId' => null]],
            ['fr|items[n1][title]', 0, 'x'],
            ['items[@n1]', 1, ['alive' => false, 'order' => 1]],
        ] as $index => [$field, $version, $value]) {
            $this->journal->append(
                'translated',
                $fields,
                $this->value($field, $version, $value, 'translated-' . $index),
                1,
                'Alice',
            );
        }
        $this->journal->saved('translated', ['items[@n1]' => ['alive' => false, 'order' => 1]]);
        $this->db->update('collaborate_draft', ['bytes' => self::COMPACT_BYTES + 1], ['id' => 'translated']);
        $this->idle('translated', '2000-01-01 00:00:00');

        $compacted = $this->journal->join('translated', $fields);

        self::assertSame(2, $compacted['generation']);
        self::assertSame([], $compacted['fields']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidValues(): iterable
    {
        yield 'operation at another version' => [[
            'operations' => [['__className' => 'InsertOperation', 'baseVersion' => 9]],
        ]];
        yield 'membership ordered by text' => [[
            'field' => 'items[@n1]',
            'kind' => 'value',
            'version' => 0,
            'value' => ['alive' => true, 'order' => '1'],
        ]];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidValues')]
    public function testAnEditOfAnotherShapeIsRefused(array $overrides): void
    {
        $fields = $this->fields + self::COLLECTION;
        $this->journal->join('shapes', $fields);

        $this->expectException(BadRequestHttpException::class);
        $this->journal->append('shapes', $fields, $this->event($overrides), 1, 'Alice');
    }

    public function testAMembershipMayBeOrderedByAFraction(): void
    {
        $this->journal->join('fraction', self::COLLECTION);

        $accepted = $this->journal->append(
            'fraction',
            self::COLLECTION,
            $this->value('items[@n1]', 0, ['alive' => true, 'order' => 1.5], 'fraction'),
            1,
            'Alice',
        );

        self::assertSame(['alive' => true, 'order' => 1.5], $accepted['value'] ?? null);
    }

    public function testASaveOfAnotherFormLeavesACollectionEntryItDoesNotShowUnsaved(): void
    {
        $this->journal->join('entries', self::COLLECTION);
        $this->journal->append(
            'entries',
            self::COLLECTION,
            $this->value('items[@n1]', 0, ['alive' => true, 'order' => 1], 'entry'),
            1,
            'Alice',
        );

        $this->journal->saved('entries', ['other' => 'value']);

        $view = $this->journal->replay('entries', self::COLLECTION, 0);
        self::assertNull($view['fields']['items[@n1]']['savedVersion'] ?? null);
        self::assertSame(-1, $view['savedRevision']);
    }

    public function testAReconciliationByDefaultComparesNoFieldStateWithoutItsSaveTime(): void
    {
        $this->withoutSavedAt('record', 'choice');
        $changed = ['choice' => ['type' => 'value', 'initial' => 'z']] + $this->fields;

        $view = $this->journal->reconcile('record', 1, 0, $changed, true);

        self::assertSame(0, $view['savedRevision']);
    }

    public function testUsingTheSavedRecordIsJournalledAsTheSystemsDiscardUnlessAnEditorIsNamed(): void
    {
        $changed = ['choice' => ['type' => 'value', 'initial' => 'z']] + $this->fields;

        $view = $this->journal->reconcile('record', 1, 0, $changed, false, microtime(true) + 60);

        self::assertSame(
            [['discard', 0, 'System']],
            array_map(static fn(array $event): array => [
                $event['kind'],
                $event['userId'],
                $event['label'],
            ], $view['events']),
        );
    }

    public function testLabelsNameTheScalarIdsOfAChoiceByTheirText(): void
    {
        $this->journal->append(
            'record',
            $this->fields,
            $this->value('choice', 0, [['nested'], 'x'], 'nested', ['labels' => ['x' => 'X']]),
            1,
            'Alice',
        );
        $accepted = $this->journal->append(
            'record',
            $this->fields,
            $this->value('choice', 1, [5], 'number', ['labels' => ['5' => 'Five']]),
            1,
            'Alice',
        );

        self::assertSame([5 => 'Five'], $accepted['labels'] ?? null);
    }

    public function testACheckpointOfOneDraftKeepsTheFieldsOfTheOthers(): void
    {
        $this->journal->join('other', $this->fields);

        $this->journal->saved('other', ['choice' => 'b']);

        self::assertSame(['body', 'choice'], array_keys($this->journal->replay('record', $this->fields, 0)['fields']));
    }
}
