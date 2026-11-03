<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Functional\Draft;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Draft\DraftText;
use Janalis\Collaborate\Tests\Fixtures\Draft\Article;
use Janalis\Collaborate\Tests\Fixtures\Draft\InterleavingConnection;
use Janalis\Collaborate\Tests\Fixtures\Draft\JsonAssertions;
use Janalis\Collaborate\Tests\Fixtures\Draft\StatementRecordingConnection;
use Janalis\Collaborate\Tests\Fixtures\Draft\Tag;
use Janalis\Collaborate\Tests\Fixtures\Draft\Tagging;
use Pdo\Mysql;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The journal on a real MariaDB or MySQL database, named by `COLLABORATE_DATABASE_URL`. Its tables come from
 * docs/schema.sql: each test works on temporary copies, which shadow the real ones on its own connection;
 * tests that race two connections use the real tables and clean up after themselves.
 */
/** @phpstan-import-type SignedScope from DraftAccess */
#[CoversClass(DraftJournal::class)]
#[CoversClass(DraftSave::class)]
#[UsesClass(RecordIdentifier::class)]
#[UsesClass(DraftText::class)]
final class DraftJournalTest extends TestCase
{
    use JsonAssertions;

    /** The defaults of the `collaborate.drafts` limits the journal is built with. */
    private const int MAX_UPDATE_BYTES = 262_144;
    private const int MAX_DRAFT_BYTES = 10_485_760;
    private const int MAX_FIELDS = 10_000;
    private const int COMPACT_BYTES = 1_048_576;
    private const int COMPACT_IDLE_SECONDS = 60;
    private const int LOCK_SECONDS = 10;
    private const int CLEAN_DAYS = 7;
    private const int UNSAVED_DAYS = 90;

    private Connection $db;
    private DraftJournal $journal;
    /** @var array<string, array{type: string, initial: mixed}> */
    private array $fields = [
        'body' => ['type' => 'rich', 'initial' => 'abcd'],
        'choice' => ['type' => 'value', 'initial' => 'a'],
    ];

    /** Whether this process created its own database (`TEST_TOKEN`). */
    private static bool $created = false;

    /**
     * A connection to the test database. Parallel test processes (Infection's, ParaTest's) each work in a
     * database of their own, `<name>_t<TEST_TOKEN>`: named locks are global to the server and keyed by the
     * database, so processes sharing one would hold each other's draft locks.
     *
     * @param class-string<Connection> $wrapperClass
     */
    public static function connection(string $wrapperClass = Connection::class): Connection
    {
        $url =
            $_SERVER['COLLABORATE_DATABASE_URL']
            ?? $_ENV['COLLABORATE_DATABASE_URL']
            ?? getenv('COLLABORATE_DATABASE_URL');
        self::assertIsString($url, 'COLLABORATE_DATABASE_URL names the test database.');
        $params = new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql'])->parse($url);
        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');
        $name = $params['dbname'] ?? null;
        if (\is_string($token) && '' !== $token && null !== $name) {
            $params['dbname'] = $name . '_t' . (string) preg_replace('/\W/', '_', $token);
            if (!self::$created) {
                $serverParams = $params;
                unset($serverParams['dbname']);
                $server = DriverManager::getConnection($serverParams);
                $server->executeStatement(
                    'CREATE DATABASE IF NOT EXISTS ' . $server->quoteSingleIdentifier($params['dbname']),
                );
                $server->close();
                self::$created = true;
            }
        }

        // No journal statement waits for a row lock longer than a peer's write: a test that does fails fast.
        $params['driverOptions'] = [Mysql::ATTR_INIT_COMMAND => 'SET SESSION innodb_lock_wait_timeout = 1'];

        return DriverManager::getConnection(['wrapperClass' => $wrapperClass] + $params);
    }

    /** @return list<string> the CREATE TABLE statements of docs/schema.sql */
    public static function schema(): array
    {
        $sql = (string) file_get_contents(\dirname(__DIR__, 3) . '/docs/schema.sql');
        preg_match_all('/^CREATE TABLE .+?;$/m', $sql, $statements);

        return array_values(array_map(static fn(string $statement): string => rtrim($statement, ';'), $statements[0]));
    }

    protected function setUp(): void
    {
        $this->db = self::connection();
        self::assertStringContainsString('test', (string) $this->db->fetchOne('SELECT DATABASE()'));
        foreach (self::schema() as $sql) {
            $this->db->executeStatement(str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $sql));
            $this->db->executeStatement(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $sql));
        }
        $this->journal = new DraftJournal($this->db, firstGeneration: 1);
        $this->journal->join('record', $this->fields);
    }

    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        $this->db->executeStatement(
            'DROP TEMPORARY TABLE collaborate_draft, collaborate_draft_event, collaborate_draft_field',
        );
        $this->db->close();
    }

    /** Articles are mapped with a single-column id, taggings with a composite one. */
    private function identifier(): RecordIdentifier
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry
            ->method('getManagerForClass')
            ->willReturnCallback(function (string $class): ObjectManager {
                $metadata = $this->createStub(ClassMetadata::class);
                $metadata
                    ->method('getIdentifierValues')
                    ->willReturnCallback(static fn(object $entity): array => match (true) {
                        $entity instanceof Article => null === $entity->id ? [] : ['id' => $entity->id],
                        default => ['article' => 7, 'tag' => 3],
                    });
                $manager = $this->createStub(ObjectManager::class);
                $manager->method('getClassMetadata')->willReturn($metadata);

                return $manager;
            });

        return new RecordIdentifier($registry);
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(
                static fn(string $id, array $parameters = [], ?string $domain = null): string => (
                    '[' . $domain . '] ' . $id
                ),
            );

        return $translator;
    }

    private function article(int $id): Article
    {
        $article = new Article();
        $article->id = $id;

        return $article;
    }

    public function testRefusesAConnectionThatIsNeitherMariaDbNorMySql(): void
    {
        $journal = new DraftJournal(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The shared-draft journal needs a MariaDB or MySQL connection');
        $journal->replay('record', [], 0);
    }

    public function testTheConfiguredLimitsAndSystemLabelApply(): void
    {
        $journal = new DraftJournal(
            $this->db,
            systemLabel: 'Robot',
            limits: [
                'max_update_bytes' => 4096,
                'label_length' => 3,
            ],
            firstGeneration: 1,
        );
        $journal->saved('record', ['choice' => 'b'], ['userId' => 1, 'label' => 'Alice']);
        $view = $journal->replay('record', $this->fields, 0);
        self::assertSame('Robot', $view['events'][0]['label']);
        self::assertSame('Ali', $view['savedBy']['label'] ?? null);
        try {
            $journal->append('record', $this->fields, $this->event(['padding' => str_repeat('x', 4096)]), 1, 'Alice');
            self::fail('An update over the configured limit was accepted.');
        } catch (HttpException $error) {
            self::assertSame(413, $error->getStatusCode());
        }
    }

    private function locked(string $id): bool
    {
        return null !== $this->db->fetchOne('SELECT IS_USED_LOCK(?)', [$this->lockName($id)]);
    }

    private function lockName(string $id): string
    {
        return 'collaborate:' . substr(hash('xxh128', (string) $this->db->getDatabase()), 0, 16) . ':' . md5($id);
    }

    /** @return SignedScope */
    private function scope(bool $new = false): array
    {
        return [
            'id' => 'record',
            'entity' => Article::class,
            'permission' => $new ? 'create' : 'update',
            'new' => $new,
            'draft' => $new ? 'new' : null,
            'fields' => $this->fields,
            'userId' => 7,
            'expires' => time() + 100,
            'rolesHash' => 'hash',
        ];
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

    public function testJournalIsDurableDeduplicatedAndFilteredByAuthorizedFields(): void
    {
        $event = $this->event();
        $accepted = $this->journal->append('record', $this->fields, $event, 1, 'Alice');
        self::assertSameJson($accepted, $this->journal->append('record', $this->fields, $event, 1, 'Alice'));
        $otherWorker = new DraftJournal($this->db, firstGeneration: 1);
        $replay = $otherWorker->replay('record', ['body' => $this->fields['body']], 0);
        self::assertSameJson([$accepted], $replay['events']);
        self::assertSame(3, $replay['fields']['body']['version']);
        self::assertArrayNotHasKey('choice', $replay['fields']);
        self::assertSame([], $otherWorker->replay('record', $this->fields, 1)['events']);
        self::assertSame([], $otherWorker->replay('record', ['choice' => $this->fields['choice']], 0)['events']);
        self::assertSame(
            'abcd',
            $this->journal->join('record', ['body' => [
                'type' => 'rich',
                'initial' => 'stale',
            ]])['fields']['body']['initial'],
        );
    }

    public function testTheDraftStampKeepsItsMicroseconds(): void
    {
        // The test tables come from the entity mapping: it must match the migration's DATETIME(6).
        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        $stamp = $this->journal->replay('record', $this->fields, 0)['stamp'];
        self::assertMatchesRegularExpression('/\\.\\d{6}$/', $stamp);
        self::assertTrue($this->journal->unchanged('record', 1, $stamp));
        $this->journal->append(
            'record',
            $this->fields,
            $this->event([
                'id' => 'second-batch-123456',
                'version' => 3,
                'operations' => [['__className' => 'InsertOperation', 'baseVersion' => 3]],
            ]),
            1,
            'Alice',
        );
        self::assertFalse($this->journal->unchanged('record', 2, $stamp));
    }

    public function testReplayReadsTheDraftAndItsEventsFromOneSnapshot(): void
    {
        // Temporary tables are private to $this->db: the race runs on the real tables.
        $params = $this->db->getParams();
        $reader = DriverManager::getConnection(['wrapperClass' => InterleavingConnection::class] + $params);
        \assert($reader instanceof InterleavingConnection);
        $writer = DriverManager::getConnection($params);
        $id = 'replay-snapshot-' . bin2hex(random_bytes(8));
        $peer = new DraftJournal($writer, firstGeneration: 1);
        try {
            $peer->join($id, $this->fields);
            $reader->pattern = 'FROM collaborate_draft_event';
            $reader->before = function () use ($peer, $id): void {
                $peer->append($id, $this->fields, $this->event(), 2, 'Bob');
            };
            $replay = new DraftJournal($reader, firstGeneration: 1)->replay($id, $this->fields, 0);
            self::assertNull($reader->before);
            self::assertSame(0, $replay['revision']);
            self::assertSame([], $replay['events']);
            self::assertSame(
                1,
                new DraftJournal($reader, firstGeneration: 1)->replay($id, $this->fields, 0)['revision'],
            );
        } finally {
            $writer->delete('collaborate_draft_event', ['draft_id' => $id]);
            $writer->delete('collaborate_draft_field', ['draft_id' => $id]);
            $writer->delete('collaborate_draft', ['id' => $id]);
            $reader->close();
            $writer->close();
        }
    }

    public function testTheOrderOfACollectionThatStoresNoneIsNotAnExternalChange(): void
    {
        $fields = [
            'places[__collection__]' => ['type' => 'collection', 'initial' => ['a', 'b'], 'unordered' => true],
            'places[@a]' => [
                'type' => 'member',
                'initial' => ['alive' => true, 'order' => 1, 'recordId' => '1'],
                'unordered' => true,
            ],
            'places[@b]' => [
                'type' => 'member',
                'initial' => ['alive' => true, 'order' => 2, 'recordId' => '2'],
                'unordered' => true,
            ],
        ];
        $this->journal->join('order', $fields);
        // A submitted reorder the record does not store.
        $this->journal->saved('order', [
            'places[__collection__]' => ['b', 'a'],
            'places[@a]' => ['alive' => true, 'order' => 2, 'recordId' => '1'],
            'places[@b]' => ['alive' => true, 'order' => 1, 'recordId' => '2'],
        ]);

        self::assertSame([], $this->journal->join('order', $fields, microtime(true) + 1)['conflicts']);
        $this->journal->lockSave(
            'order',
            1,
            0,
            array_map(static fn(array $field): mixed => $field['initial'], $fields),
        );

        $removed = $fields;
        $removed['places[__collection__]']['initial'] = ['a'];
        $removed['places[@b]']['initial'] = ['alive' => false, 'order' => 0, 'recordId' => null];
        self::assertSame(
            // Field states are read in name order.
            ['places[@b]', 'places[__collection__]'],
            array_keys($this->journal->join('order', $removed, microtime(true) + 1)['conflicts']),
        );
        $this->expectException(ConflictHttpException::class);
        $this->journal->lockSave('order', 1, 0, ['places[__collection__]' => ['a']]);
    }

    public function testAnObjectValueIsTheSameWhateverTheOrderOfItsKeys(): void
    {
        // MySQL stores a JSON object with its keys sorted, MariaDB as written: neither order is a change.
        $fields = [
            'period' => [
                'type' => 'value',
                'initial' => ['from' => ['year' => 2026, 'month' => 11, 'day' => 3], 'days' => 7],
            ],
        ];
        $this->journal->join('keys', $fields);
        $this->journal->saved('keys', [
            'period' => ['days' => 7, 'from' => ['day' => 3, 'month' => 11, 'year' => 2026]],
        ]);

        $view = $this->journal->join('keys', $fields, microtime(true) + 1);
        self::assertSame(0, $view['revision']);
        self::assertSame([], $view['conflicts']);
        self::assertSame($view['digest'], $this->journal->replay('keys', $fields, 0)['digest']);
        $this->journal->lockSave('keys', 1, 0, [
            'period' => ['days' => 7, 'from' => ['month' => 11, 'year' => 2026, 'day' => 3]],
        ]);
    }

    public function testTheOrderOfACollectionThatStoresItIsAnExternalChange(): void
    {
        $fields = [
            'blocks[__collection__]' => ['type' => 'collection', 'initial' => ['a', 'b']],
            'blocks[@a]' => ['type' => 'member', 'initial' => ['alive' => true, 'order' => 1, 'recordId' => '1']],
        ];
        $this->journal->join('stored-order', $fields);
        $this->journal->saved('stored-order', [
            'blocks[__collection__]' => ['b', 'a'],
            'blocks[@a]' => ['alive' => true, 'order' => 2, 'recordId' => '1'],
        ]);

        self::assertSame(
            ['blocks[@a]', 'blocks[__collection__]'],
            array_keys($this->journal->join('stored-order', $fields, microtime(true) + 1)['conflicts']),
        );
        $this->expectException(ConflictHttpException::class);
        $this->journal->lockSave('stored-order', 1, 0, ['blocks[@a]' => $fields['blocks[@a]']['initial']]);
    }

    public function testReplaySendsFieldStatesOnlyWhenTheyDifferFromTheClientsDigest(): void
    {
        $joined = $this->journal->join('record', $this->fields);
        $unchanged = $this->journal->replay('record', $this->fields, 0, digest: $joined['digest']);
        self::assertSame([], $unchanged['fields']);
        self::assertSame($joined['digest'], $unchanged['digest']);

        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        $changed = $this->journal->replay('record', $this->fields, 0, digest: $joined['digest']);
        self::assertSame(3, $changed['fields']['body']['version']);
        self::assertNotSame($joined['digest'], $changed['digest']);
        self::assertCount(1, $changed['events']);
        self::assertSame($changed['fields'], $this->journal->replay('record', $this->fields, 0)['fields']);
    }

    public function testADraftIsUnchangedWhileNobodyWroteSinceTheClientsStamp(): void
    {
        $joined = $this->journal->join('record', $this->fields);
        self::assertSame(
            $this->db->fetchOne("SELECT updated_at FROM collaborate_draft WHERE id = 'record'"),
            $joined['stamp'],
        );
        self::assertTrue($this->journal->unchanged('record', 0, $joined['stamp']));
        // A client behind the row, or a stamp from another write, reads the draft.
        self::assertFalse($this->journal->unchanged('record', 1, $joined['stamp']));
        self::assertFalse($this->journal->unchanged('record', 0, 'other'));

        // Opening the page again writes nothing; a form with a field the draft lacks does.
        usleep(10);
        self::assertSame($joined['stamp'], $this->journal->join('record', $this->fields)['stamp']);
        self::assertTrue($this->journal->unchanged('record', 0, $joined['stamp']));
        $extended = [...$this->fields, 'note' => ['type' => 'value', 'initial' => 'n']];
        self::assertNotSame($joined['stamp'], $this->journal->join('record', $extended)['stamp']);
        $joined = $this->journal->join('record', $this->fields);

        // A save checkpoints the field states without a new revision: the row's stamp still moves.
        usleep(10);
        $this->journal->saved('record', ['choice' => 'a']);
        self::assertFalse($this->journal->unchanged('record', 0, $joined['stamp']));
        $saved = $this->journal->replay('record', $this->fields, 0);
        self::assertSame(0, $saved['revision']);
        self::assertNotSame($joined['stamp'], $saved['stamp']);
        self::assertTrue($this->journal->unchanged('record', 0, $saved['stamp']));

        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        self::assertFalse($this->journal->unchanged('record', 1, $saved['stamp']));
        self::assertTrue($this->journal->unchanged(
            'record',
            1,
            $this->journal->replay('record', $this->fields, 0)['stamp'],
        ));

        // A purged draft is not unchanged: its replay answers that it is gone.
        $this->db->delete('collaborate_draft', ['id' => 'record']);
        self::assertFalse($this->journal->unchanged('record', 1, $saved['stamp']));
    }

    public function testRejectsStaleCompareAndSwapWithoutChangingTheJournal(): void
    {
        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        try {
            $this->journal->append(
                'record',
                $this->fields,
                $this->event(['id' => 'another-batch-identifier']),
                2,
                'Bob',
            );
            self::fail('A stale operation was accepted.');
        } catch (ConflictHttpException) {
            self::assertSame(1, $this->journal->replay('record', $this->fields, 0)['revision']);
        }
        $this->expectException(ConflictHttpException::class);
        $this->journal->append('record', $this->fields, $this->event(), 2, 'Bob');
    }

    public function testSaveLockChecksExternalChangesAndCheckpointSurvivesDiscard(): void
    {
        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        $this->db->beginTransaction();
        $this->journal->lockSave('record', 1, 1, ['body' => 'abcd']);
        $this->journal->saved('record', ['body' => 'edited']);
        $this->db->commit();
        $saved = $this->journal->replay('record', $this->fields, 0);
        self::assertSame(1, $saved['savedRevision']);
        $discarded = $this->journal->discard('record', 1, 1, $this->fields);
        self::assertSame(1, $discarded['generation']);
        self::assertSame(
            [['discard', ['body']]],
            array_map(static fn(array $event): array => [
                $event['kind'],
                $event['fields'] ?? null,
            ], $discarded['events']),
        );
        self::assertSame('edited', $discarded['fields']['body']['initial']);
        // A page that has not replayed the Discard still edits the content it reset.
        $this->expectException(ConflictHttpException::class);
        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
    }

    public function testRejectsStaleSaveAndDiscardAndExternalPersistedChanges(): void
    {
        foreach ([[2, 0, ['body' => 'abcd']], [1, 1, ['body' => 'abcd']], [1, 0, ['body' => 'external']]] as [
            $generation,
            $revision,
            $current,
        ]) {
            try {
                $this->journal->lockSave('record', $generation, $revision, $current);
                self::fail('Conflicting save was accepted.');
            } catch (ConflictHttpException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(ConflictHttpException::class);
        $this->journal->discard('record', 2, 0, $this->fields);
    }

    public function testScalarChangesUseTheSameRevisionProtocol(): void
    {
        $event = $this->event(['field' => 'choice', 'kind' => 'value', 'version' => 0, 'value' => ['a', 'b']]);
        $accepted = $this->journal->append('record', $this->fields, $event, 2, 'Bob');
        self::assertSame(['a', 'b'], $accepted['value']);
        self::assertSame(['a', 'b'], $this->journal->replay('record', $this->fields, 0)['fields']['choice']['value']);
    }

    public function testAChoiceCarriesTheLabelsOfItsValueToPeers(): void
    {
        $event = $this->event([
            'field' => 'choice',
            'kind' => 'value',
            'version' => 0,
            'value' => ['42', '7'],
            'labels' => ['42' => 'Ada', '7' => 'Zoë'],
        ]);
        $accepted = $this->journal->append('record', $this->fields, $event, 2, 'Bob');
        $labels = $accepted['labels'] ?? null;
        self::assertSame([42 => 'Ada', 7 => 'Zoë'], $labels);
        self::assertSameJson(
            $labels,
            $this->journal->replay('record', $this->fields, 0)['events'][0]['labels'] ?? null,
        );
        $unlabelled = $this->journal->append(
            'record',
            $this->fields,
            $this->event([
                'id' => 'unlabelled-1234567',
                'field' => 'choice',
                'kind' => 'value',
                'version' => 1,
                'value' => 'a',
            ]),
            2,
            'Bob',
        );
        self::assertArrayNotHasKey('labels', $unlabelled);
    }

    public function testFieldNamesAndBatchIdentifiersAreCaseSensitive(): void
    {
        $fields = ['name' => ['type' => 'value', 'initial' => 'a'], 'Name' => ['type' => 'value', 'initial' => 'a']];
        $this->journal->join('cased', $fields);
        $lower = ['generation' => 1, 'seen' => 0, 'field' => 'name', 'kind' => 'value', 'version' => 0, 'value' => 'b'];
        $this->journal->append('cased', $fields, ['id' => 'abcdef0123456789'] + $lower, 1, 'Alice');
        $upper = $this->journal->append(
            'cased',
            $fields,
            ['id' => 'ABCDEF0123456789', 'field' => 'Name'] + $lower,
            1,
            'Alice',
        );
        self::assertSame(2, $upper['revision']);
        $this->journal->discard('cased', 1, 2, ['name' => $fields['name']]);
        $view = $this->journal->replay('cased', $fields, 0);
        self::assertSame(['Name', ''], array_column($view['events'], 'field'));
        self::assertSame(['name'], $view['events'][1]['fields'] ?? null);
    }

    /** @return iterable<string, array{string, mixed, mixed}> */
    public static function invalidLabels(): iterable
    {
        yield 'not a map' => ['choice', '42', 'Ada'];
        yield 'not a string' => ['choice', '42', ['42' => 42]];
        yield 'too long' => ['choice', '42', ['42' => str_repeat('é', 256)]];
        yield 'not a value' => ['choice', '42', ['7' => 'Zoë']];
        yield 'on text' => ['body', 'abcd', ['abcd' => 'Text']];
    }

    #[DataProvider('invalidLabels')]
    public function testLabelsOtherThanTheValuesOfAChoiceAreRejected(string $field, mixed $value, mixed $labels): void
    {
        try {
            $this->journal->append(
                'record',
                $this->fields,
                $this->event([
                    'field' => $field,
                    'kind' => 'value',
                    'version' => 0,
                    'value' => $value,
                    'labels' => $labels,
                ]),
                2,
                'Bob',
            );
            self::fail('Invalid labels were accepted.');
        } catch (BadRequestHttpException) {
            self::assertSame(0, $this->journal->replay('record', $this->fields, 0)['revision']);
        }
    }

    public function testValidatesEnvelopesAndEnforcesBoundedStorage(): void
    {
        foreach ([
            ['field' => 'secret'],
            ['id' => 'bad'],
            ['version' => -1],
            ['version' => 100_001],
            ['operations' => []],
            ['operations' => [['baseVersion' => 100]]],
            ['kind' => 'unknown'],
        ] as $invalid) {
            try {
                $this->journal->append('record', $this->fields, $this->event($invalid), 1, 'Alice');
                self::fail('Invalid operation was accepted.');
            } catch (BadRequestHttpException) {
                self::assertSame(0, $this->journal->replay('record', $this->fields, 0)['revision']);
            }
        }
        foreach ([false, true] as $full) {
            $event = $this->event();
            if ($full) {
                $this->db->update('collaborate_draft', ['bytes' => self::MAX_DRAFT_BYTES], ['id' => 'record']);
            } else {
                $event['padding'] = str_repeat('x', self::MAX_UPDATE_BYTES);
            }
            try {
                $this->journal->append('record', $this->fields, $event, 1, 'Alice');
                self::fail('Oversized journal entry was accepted.');
            } catch (HttpException $error) {
                self::assertSame(413, $error->getStatusCode());
            }
        }
    }

    public function testUpdateLimitCountsUtf8BytesNotEscapedJson(): void
    {
        // About 98 KiB of UTF-8, and three times that once escaped as \uXXXX.
        $greek = str_repeat('λόγος ', 10_000);
        $accepted = $this->journal->append(
            'record',
            $this->fields,
            $this->event([
                'operations' => [[
                    '__className' => 'InsertOperation',
                    'baseVersion' => 2,
                    'nodes' => [['data' => $greek]],
                ]],
            ]),
            1,
            'Alice',
        );
        self::assertSame(1, $accepted['revision']);
        $stored = (string) $this->db->fetchOne('SELECT payload FROM collaborate_draft_event WHERE draft_id = ?', [
            'record',
        ]);
        self::assertStringContainsString($greek, $stored);
        $this->journal->saved('record', ['choice' => 'b']);
        self::assertSame(
            // As stored by PHP: MySQL would measure its own reformatted JSON.
            array_sum(array_map(
                static fn(mixed $payload): int => \strlen(json_encode(
                    json_decode((string) $payload, flags: \JSON_THROW_ON_ERROR),
                    \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
                )),
                $this->db->fetchFirstColumn('SELECT payload FROM collaborate_draft_event WHERE draft_id = ?', [
                    'record',
                ]),
            )),
            (int) $this->db->fetchOne('SELECT bytes FROM collaborate_draft WHERE id = ?', ['record']),
        );
        self::assertLessThan(self::MAX_UPDATE_BYTES, \strlen($stored));
    }

    public function testProgrammaticallySavedChoicesAreReplayedBeforeAnotherSessionCanSave(): void
    {
        $this->journal->saved('record', ['choice' => 'b']);
        $peer = $this->journal->replay('record', $this->fields, 0);
        self::assertSame('b', $peer['fields']['choice']['value']);
        self::assertSame(1, $peer['fields']['choice']['version']);
        self::assertSame(1, $peer['savedRevision']);
        self::assertSameJson(
            [
                'field' => 'choice',
                'kind' => 'value',
                'version' => 0,
                'revision' => 1,
                'userId' => 0,
                'label' => 'System',
                'value' => 'b',
            ],
            array_diff_key($peer['events'][0], ['id' => true]),
        );

        $this->journal->saved('record', ['choice' => 'b']);
        self::assertSame([], $this->journal->replay('record', $this->fields, 1)['events']);
        $this->expectException(ConflictHttpException::class);
        $this->journal->lockSave('record', 1, 0, ['choice' => 'b']);
    }

    public function testSavedChoiceNormalizationAdvancesItsExistingVersionAndReplaysAfterReload(): void
    {
        $this->journal->append(
            'record',
            $this->fields,
            $this->event([
                'field' => 'choice',
                'kind' => 'value',
                'version' => 0,
                'value' => 'raw',
            ]),
            1,
            'Alice',
        );
        $this->journal->saved('record', ['choice' => ['canonical']]);
        $view = $this->journal->join('record', $this->fields);
        self::assertSame(['canonical'], $view['fields']['choice']['value']);
        self::assertSame(2, $view['fields']['choice']['version']);
        self::assertSame(2, $view['savedRevision']);
        self::assertSame(1, $view['events'][1]['version']);
        self::assertSame(['canonical'], $view['events'][1]['value']);
        $this->journal->lockSave('record', 1, 2, ['choice' => ['canonical']]);
        $this->journal->saved('record', ['choice' => []]);
        self::assertSame([], $this->journal->replay('record', $this->fields, 2)['events'][0]['value']);
    }

    public function testSavingDoesNotResetTheSharedSourceModeToItsFormDefault(): void
    {
        $fields = ['body[__mode__]' => ['type' => 'value', 'initial' => 'wysiwyg']];
        $this->journal->join('mode', $fields);
        $this->journal->append(
            'mode',
            $fields,
            $this->event([
                'field' => 'body[__mode__]',
                'kind' => 'value',
                'version' => 0,
                'value' => 'markdown',
            ]),
            1,
            'Alice',
        );
        $this->journal->saved('mode', ['body[__mode__]' => 'wysiwyg']);
        $view = $this->journal->replay('mode', $fields, 1);
        self::assertSame('markdown', $view['fields']['body[__mode__]']['value']);
        self::assertSame([], $view['events']);
        self::assertSame(1, $view['savedRevision']);
    }

    public function testTheSharedSourceModeIsNotAnUnsavedChange(): void
    {
        $fields = ['body[__mode__]' => ['type' => 'value', 'initial' => 'wysiwyg']];
        $this->journal->join('mode', $fields);
        $this->journal->append(
            'mode',
            $fields,
            $this->event([
                'field' => 'body[__mode__]',
                'kind' => 'value',
                'version' => 0,
                'value' => 'markdown',
            ]),
            1,
            'Alice',
        );
        $view = $this->journal->replay('mode', $fields, 0);
        self::assertSame(1, $view['revision']);
        self::assertSame(1, $view['savedRevision']);
        self::assertSame(
            1,
            (int) $this->db->fetchOne('SELECT saved_revision FROM collaborate_draft WHERE id = ?', ['mode']),
        );
    }

    public function testAnotherLocaleUnsavedEditKeepsTheDraftUnsavedForThePurge(): void
    {
        $fields = [
            'en:name' => ['type' => 'value', 'initial' => 'Name'],
            'fr:name' => ['type' => 'value', 'initial' => 'Nom'],
        ];
        $english = ['en:name' => $fields['en:name']];
        $this->journal->join('locales', $fields);
        $this->journal->append(
            'locales',
            $fields,
            $this->event(['field' => 'fr:name', 'kind' => 'value', 'version' => 0, 'value' => 'Nom!']),
            1,
            'Alice',
        );
        $stored = fn(): array => array_map(
            'intval',
            (array) $this->db->fetchAssociative('SELECT revision, saved_revision FROM collaborate_draft WHERE id = ?', [
                'locales',
            ]),
        );
        $this->journal->saved('locales', ['en:name' => 'Name']);
        $row = $stored();
        self::assertLessThan($row['revision'], $row['saved_revision']);
        $this->journal->discard('locales', 1, $row['revision'], $english);
        $row = $stored();
        self::assertLessThan($row['revision'], $row['saved_revision']);
        $this->db->update('collaborate_draft', ['updated_at' => '2000-01-01 00:00:00'], ['id' => 'locales']);
        $this->journal->purge(new \DateTimeImmutable('2000-01-09 00:00:00'));
        self::assertSame('locales', $this->db->fetchOne('SELECT id FROM collaborate_draft WHERE id = ?', ['locales']));
        $this->journal->saved('locales', ['fr:name' => 'Nom!']);
        $row = $stored();
        self::assertSame($row['revision'], $row['saved_revision']);
    }

    public function testTextNormalizationIsAnOrderedModelChangeAndLocalesHaveIndependentCheckpoints(): void
    {
        $fields = [
            'en:name' => ['type' => 'text', 'initial' => '  Name  '],
            'fr:name' => ['type' => 'text', 'initial' => 'Nom'],
        ];
        $this->journal->join('normalize', $fields);
        $this->journal->append(
            'normalize',
            $fields,
            $this->event([
                'field' => 'fr:name',
                'operations' => [[
                    '__className' => 'InsertOperation',
                    'baseVersion' => 2,
                    'position' => ['root' => 'main', 'path' => [0, 3]],
                    'nodes' => [['data' => '!']],
                ]],
            ]),
            1,
            'Alice',
        );
        $this->journal->saved('normalize', ['en:name' => 'Name']);
        $view = $this->journal->replay('normalize', $fields, 0);
        self::assertSame('Name', $view['fields']['en:name']['value']);
        self::assertSame('Nom!', $view['fields']['fr:name']['value']);
        self::assertSame(2, $view['revision']);
        self::assertLessThan($view['revision'], $view['savedRevision']);
        $normalization = $view['events'][1];
        self::assertSame('System', $normalization['label']);
        self::assertCount(2, $normalization['operations'] ?? []);
        $this->journal->saved('normalize', ['fr:name' => 'Nom!']);
        $view = $this->journal->replay('normalize', $fields, 0);
        self::assertSame($view['revision'], $view['savedRevision']);
    }

    public function testTheDraftNamesWhoSavedAndWhoDiscardedIt(): void
    {
        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        self::assertNull($this->journal->replay('record', $this->fields, 0)['savedBy']);
        $this->journal->saved('record', ['body' => 'edited'], ['userId' => 1, 'label' => str_repeat('é', 300)]);
        $view = $this->journal->replay('record', $this->fields, 0);
        self::assertSame(1, $view['savedBy']['userId'] ?? null);
        self::assertSame(str_repeat('é', 255), $view['savedBy']['label'] ?? null);
        // A save outside the draft names nobody.
        $this->journal->saved('record', ['body' => 'again']);
        self::assertNull($this->journal->replay('record', $this->fields, 0)['savedBy']);
        $this->journal->saved('record', ['body' => 'again'], ['userId' => 1, 'label' => 'Alice']);
        // The journal still holds the edit, so a Discard resets its field.
        $revision = $this->journal->replay('record', $this->fields, 0)['revision'];
        $discarded = $this->journal->discard('record', 1, $revision, $this->fields, 2, 'Bob');
        $discard = array_values(array_filter(
            $discarded['events'],
            static fn(array $event): bool => 'discard' === $event['kind'],
        ));
        self::assertSame(
            [[2, 'Bob']],
            array_map(static fn(array $event): array => [$event['userId'], $event['label']], $discard),
        );
    }

    public function testARecordSavedOutsideTheDraftNamesNobodyOnceReconciled(): void
    {
        $fields = ['name' => ['type' => 'text', 'initial' => 'Original']];
        $this->journal->join('external', $fields);
        $this->journal->saved('external', ['name' => 'Original'], ['userId' => 1, 'label' => 'Alice']);
        $fresh = ['name' => ['type' => 'text', 'initial' => 'External']];
        $view = $this->journal->reconcile('external', 1, 0, $fresh, true, microtime(true) + 1);
        self::assertNull($view['savedBy']);
        $later = ['name' => ['type' => 'text', 'initial' => 'Later']];
        $view = $this->journal->reconcile('external', 1, 0, $later, false, microtime(true) + 1, 2, 'Bob');
        self::assertSame(
            [[2, 'Bob']],
            array_map(static fn(array $event): array => [
                $event['userId'],
                $event['label'],
            ], array_values(array_filter($view['events'], static fn(array $event): bool => 'discard' === $event['kind']))),
        );
    }

    public function testExternalChangesRequireAnExplicitChoiceAndKeepDoesNotWriteTheRecord(): void
    {
        $fields = ['name' => ['type' => 'text', 'initial' => 'Original']];
        $this->journal->join('external', $fields);
        $fresh = ['name' => ['type' => 'text', 'initial' => 'External']];
        $view = $this->journal->replay('external', $fresh, 0, microtime(true) + 1);
        self::assertSame(['name' => 'External'], $view['conflicts']);
        $view = $this->journal->reconcile('external', 1, 0, $fresh, true, microtime(true) + 1);
        self::assertSame([], $view['conflicts']);
        self::assertSame('Original', $view['fields']['name']['value']);
        self::assertSame('External', $view['fields']['name']['saved']);
        // The kept draft differs from the record: it is purged after the unsaved delay, not the clean one.
        self::assertSame(-1, $view['savedRevision']);
        $this->db->update('collaborate_draft', ['updated_at' => '2000-01-01 00:00:00'], ['id' => 'external']);
        $this->journal->purge(new \DateTimeImmutable('2000-01-09 00:00:00'));
        self::assertSame('external', $this->db->fetchOne('SELECT id FROM collaborate_draft WHERE id = ?', [
            'external',
        ]));
        $later = ['name' => ['type' => 'text', 'initial' => 'Later']];
        $view = $this->journal->reconcile('external', 1, 0, $later, false, microtime(true) + 1);
        self::assertSame(1, $view['generation']);
        self::assertSame('Later', $view['fields']['name']['initial']);
    }

    public function testADiscardResetsOneFormsEditedFieldsAndLeavesAnotherLocalesPageEditing(): void
    {
        $fr = ['fr:name' => ['type' => 'value', 'initial' => 'Nom'], 'type' => ['type' => 'value', 'initial' => 'a']];
        $en = ['en:name' => ['type' => 'value', 'initial' => 'Name'], 'type' => ['type' => 'value', 'initial' => 'a']];
        $this->journal->join('locales', $fr);
        $this->journal->join('locales', $en);
        $value = fn(string $id, string $field, int $version, string $value, int $seen): array => $this->event([
            'id' => $id,
            'field' => $field,
            'kind' => 'value',
            'version' => $version,
            'value' => $value,
            'seen' => $seen,
        ]);
        $this->journal->append('locales', $en, $value('english-batch-0001', 'en:name', 0, 'English', 0), 2, 'Bob');
        $this->journal->append('locales', $fr, $value('french-batch-00001', 'fr:name', 0, 'Français', 1), 1, 'Alice');
        $view = $this->journal->discard('locales', 1, 2, $fr);
        self::assertSame(1, $view['generation']);
        self::assertSame('Nom', $view['fields']['fr:name']['value']);
        // The English page reads no Discard: none of its fields was reset.
        $english = $this->journal->replay('locales', $en, 1);
        self::assertSame([], $english['events']);
        self::assertSame('English', $english['fields']['en:name']['value']);
        // It goes on editing from the revision it had seen before the Discard.
        $this->journal->append('locales', $en, $value('english-batch-0002', 'en:name', 1, 'English 2', 1), 2, 'Bob');
        // A shared field edited, then reset, is named to every page that shows it, and only it.
        $this->journal->append('locales', $fr, $value('shared-batch-00001', 'type', 0, 'b', 4), 1, 'Alice');
        $this->journal->discard('locales', 1, 5, $fr);
        $english = $this->journal->replay('locales', $en, 5);
        self::assertSame(
            [['discard', ['type']]],
            array_map(static fn(array $event): array => [$event['kind'], $event['fields'] ?? null], $english['events']),
        );
        try {
            $this->journal->append('locales', $en, $value('stale-type-batch-01', 'type', 1, 'c', 5), 2, 'Bob');
            self::fail('An edit made before the Discard was accepted.');
        } catch (ConflictHttpException) {
            self::assertSame('English 2', $this->journal->replay('locales', $en, 0)['fields']['en:name']['value']);
        }
        // Nothing to reset: the draft is left as it is.
        self::assertSame(6, $this->journal->discard('locales', 1, 6, ['fr:name' => $fr['fr:name']])['revision']);
    }

    public function testReconcilingReadsTheJournalOnceForItsAnswer(): void
    {
        // Temporary tables are private to $this->db: the recording connection works on the real tables.
        $recording = DriverManager::getConnection(['wrapperClass' => StatementRecordingConnection::class]
        + $this->db->getParams());
        \assert($recording instanceof StatementRecordingConnection);
        $id = 'reconcile-reads-' . bin2hex(random_bytes(8));
        $journal = new DraftJournal($recording, firstGeneration: 1);
        $fields = ['name' => ['type' => 'value', 'initial' => 'Original']];
        try {
            $journal->join($id, $fields);
            $journal->append(
                $id,
                $fields,
                $this->event(['field' => 'name', 'kind' => 'value', 'version' => 0, 'value' => 'Draft']),
                1,
                'Alice',
            );
            $before = \count($recording->queries);
            $view = $journal->reconcile(
                $id,
                1,
                1,
                ['name' => ['type' => 'value', 'initial' => 'External']],
                true,
                microtime(true) + 1,
            );
            self::assertCount(1, $view['events']);
            self::assertCount(1, array_filter(
                \array_slice($recording->queries, $before),
                static fn(string $sql): bool => str_starts_with($sql, 'SELECT payload FROM collaborate_draft_event'),
            ));
        } finally {
            $recording->delete('collaborate_draft_event', ['draft_id' => $id]);
            $recording->delete('collaborate_draft_field', ['draft_id' => $id]);
            $recording->delete('collaborate_draft', ['id' => $id]);
            $recording->close();
        }
    }

    public function testAPlainListReopensUnderItsSavedKeysAndARemovedEntryIsNoConflict(): void
    {
        $fields = [
            'roles[__collection__]' => ['type' => 'collection', 'initial' => ['p0', 'p1'], 'unordered' => true],
            'roles[__member__]' => [
                'type' => 'member',
                'initial' => ['alive' => false, 'order' => 0, 'recordId' => null],
                'unordered' => true,
            ],
            'roles[@p0]' => [
                'type' => 'member',
                'initial' => ['alive' => true, 'order' => 1, 'recordId' => null],
                'unordered' => true,
            ],
            'roles[@p1]' => [
                'type' => 'member',
                'initial' => ['alive' => true, 'order' => 2, 'recordId' => null],
                'unordered' => true,
            ],
            'roles[__name__]' => ['type' => 'text', 'initial' => ''],
            'roles[p0]' => ['type' => 'text', 'initial' => 'ROLE_A'],
            'roles[p1]' => ['type' => 'text', 'initial' => 'ROLE_B'],
            'title' => ['type' => 'value', 'initial' => 'a'],
        ];
        $this->journal->join('list', $fields);
        self::assertSame(['roles' => ['p0', 'p1']], $this->journal->listKeys('list'));
        $this->journal->append(
            'list',
            $fields,
            $this->event([
                'field' => 'roles[@p0]',
                'kind' => 'value',
                'version' => 0,
                'value' => ['alive' => false, 'order' => 1],
            ]),
            1,
            'Alice',
        );
        // The save stores ROLE_B alone and checkpoints the entries it kept.
        $this->journal->saved('list', [
            'roles[__collection__]' => ['p1'],
            'roles[@p1]' => ['alive' => true, 'order' => 2, 'recordId' => null],
            'roles[p1]' => 'ROLE_B',
        ]);
        self::assertSame(['roles' => ['p1']], $this->journal->listKeys('list'));
        // Reopened, the page offers the kept entry and the template: the removed entry's value conflicts with nothing.
        $reopened =
            ['roles[__collection__]' => ['type' => 'collection', 'initial' => ['p1'], 'unordered' => true]] + $fields;
        unset($reopened['roles[@p0]'], $reopened['roles[p0]']);
        self::assertSame([], $this->journal->replay('list', $reopened, 0, microtime(true) + 1)['conflicts']);
        $this->db->executeStatement(
            "UPDATE collaborate_draft_field SET state = JSON_SET(state, '$.saved', JSON_OBJECT('p0', 'x')) WHERE draft_id = 'list' AND name = 'roles[__collection__]'",
        );
        self::assertSame([], $this->journal->listKeys('list'));
    }

    public function testRemovedCollectionChildrenCannotBeEditedAndOtherLocaleEventsSurviveDiscard(): void
    {
        $fields = [
            'items[__member__]' => ['type' => 'member', 'initial' => ['alive' => false, 'order' => 0]],
            'en:items[__name__][name]' => ['type' => 'text', 'initial' => '', 'formName' => 'items[__name__][name]'],
            'fr:name' => ['type' => 'value', 'initial' => 'Nom'],
        ];
        $this->journal->join('removed', $fields);
        $this->journal->append(
            'removed',
            $fields,
            $this->event([
                'field' => 'items[@n1]',
                'kind' => 'value',
                'version' => 0,
                'value' => ['alive' => true, 'order' => 1],
            ]),
            1,
            'Alice',
        );
        $this->journal->append(
            'removed',
            $fields,
            $this->event([
                'id' => 'child-batch-123456',
                'field' => 'en:items[n1][name]',
                'version' => 1,
                'operations' => [[
                    '__className' => 'InsertOperation',
                    'baseVersion' => 1,
                    'position' => ['root' => 'main', 'path' => [0, 0]],
                    'nodes' => [['data' => 'Child']],
                ]],
            ]),
            1,
            'Alice',
        );
        $this->journal->append(
            'removed',
            $fields,
            $this->event([
                'id' => 'remove-batch-123456',
                'field' => 'items[@n1]',
                'kind' => 'value',
                'version' => 1,
                'value' => ['alive' => false, 'order' => 1],
            ]),
            1,
            'Alice',
        );
        try {
            $this->journal->append(
                'removed',
                $fields,
                $this->event(['id' => 'after-batch-123456', 'field' => 'en:items[n1][name]']),
                1,
                'Alice',
            );
            self::fail('A removed child was edited.');
        } catch (ConflictHttpException) {
            self::assertSame(3, $this->journal->replay('removed', $fields, 0)['revision']);
        }
        $this->journal->saved('removed', ['items[__collection__]' => [], 'missing' => 'ignored']);
        $this->journal->append(
            'removed',
            $fields,
            $this->event([
                'id' => 'locale-batch-123456',
                'field' => 'fr:name',
                'kind' => 'value',
                'version' => 0,
                'value' => 'Français',
            ]),
            2,
            'Bob',
        );
        unset($fields['fr:name']);
        $this->journal->discard('removed', 1, 4, $fields);
        $view = $this->journal->replay('removed', ['fr:name' => ['type' => 'value', 'initial' => 'Nom']], 0);
        self::assertCount(1, $view['events']);
        self::assertSame('Français', $view['fields']['fr:name']['value']);
    }

    public function testMalformedScalarMembershipAndChangedSchemasFailWithoutAppending(): void
    {
        foreach ([
            ['field' => 'choice', 'kind' => 'value', 'value' => new \stdClass()],
            ['field' => 'choice', 'kind' => 'value', 'value' => static fn() => null],
        ] as $override) {
            try {
                $this->journal->append('record', $this->fields, $this->event($override), 1, 'Alice');
                self::fail('An invalid scalar was accepted.');
            } catch (BadRequestHttpException) {
                self::assertSame(0, $this->journal->replay('record', $this->fields, 0)['revision']);
            }
        }
        $this->journal->created('record', '42');
        $this->expectException(ConflictHttpException::class);
        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
    }

    public function testMissingDraftRequiresJoin(): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->journal->replay('missing', [], 0);
    }

    public function testCollectionTemplatesStayAuthorizedAndRemovalWinsOverReordering(): void
    {
        $fields = [
            'items[__member__]' => [
                'type' => 'member',
                'initial' => ['alive' => false, 'order' => 0, 'recordId' => null],
            ],
            'items[__name__][title]' => ['type' => 'text', 'initial' => ''],
        ];
        $this->journal->join('collection', $fields);
        $event = $this->event([
            'field' => 'items[@n123]',
            'kind' => 'value',
            'version' => 0,
            'value' => ['alive' => true, 'order' => 1, 'recordId' => null],
        ]);
        $this->journal->append('collection', $fields, $event, 1, 'Alice');
        $this->journal->append(
            'collection',
            $fields,
            array_replace($event, [
                'id' => 'delete-batch-123456',
                'version' => 1,
                'value' => ['alive' => false, 'order' => 1],
            ]),
            2,
            'Bob',
        );
        $accepted = $this->journal->append(
            'collection',
            $fields,
            array_replace($event, ['id' => 'reorder-batch-123456', 'version' => 2]),
            1,
            'Alice',
        );
        self::assertFalse(((array) $accepted['value'])['alive']);
        $this->journal->saved('collection', ['items[@n123]' => ['alive' => true, 'order' => 1, 'recordId' => '42']]);
        self::assertSame(['items' => [42 => 'n123']], $this->journal->aliases('collection'));
        self::assertSame([], $this->journal->aliases('missing'));
        $this->expectException(BadRequestHttpException::class);
        $this->journal->append('collection', $fields, $this->event(['field' => 'items[n123][password]']), 1, 'Alice');
    }

    public function testAFullDraftRefusesANewCollectionFieldAndStillAcceptsItsExistingOnes(): void
    {
        $fields = [
            'items[__member__]' => [
                'type' => 'member',
                'initial' => ['alive' => false, 'order' => 0, 'recordId' => null],
            ],
            'items[__name__][title]' => ['type' => 'text', 'initial' => ''],
        ];
        $this->journal->join('collection', $fields);
        $event = $this->event([
            'field' => 'items[@n1]',
            'kind' => 'value',
            'version' => 0,
            'value' => ['alive' => true, 'order' => 1, 'recordId' => null],
        ]);
        $this->journal->append('collection', $fields, $event, 1, 'Alice');
        // Discard and compaction empty the journal but keep the rows: the rows have their own limit.
        $rows = (int) $this->db->fetchOne('SELECT COUNT(*) FROM collaborate_draft_field WHERE draft_id = ?', [
            'collection',
        ]);
        foreach (array_chunk(range($rows, self::MAX_FIELDS - 1), 1000) as $chunk) {
            $this->db->executeStatement(
                'INSERT INTO collaborate_draft_field (draft_id, name, type, state) VALUES '
                    . implode(', ', array_fill(0, \count($chunk), "('collection', ?, 'text', '{}')")),
                array_map(static fn(int $index): string => "items[p{$index}][title]", $chunk),
            );
        }
        try {
            $this->journal->append(
                'collection',
                $fields,
                array_replace($event, ['id' => 'new-entry-batch-123456', 'field' => 'items[@n2]']),
                1,
                'Alice',
            );
            self::fail('A field beyond the draft row limit was accepted.');
        } catch (HttpException $error) {
            self::assertSame(413, $error->getStatusCode());
        }
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM collaborate_draft_field WHERE draft_id = ? AND name = ?', [
            'collection',
            'items[@n2]',
        ]));
        $accepted = $this->journal->append(
            'collection',
            $fields,
            array_replace($event, [
                'id' => 'reorder-batch-123456',
                'version' => 1,
                'value' => ['alive' => true, 'order' => 2, 'recordId' => null],
            ]),
            1,
            'Alice',
        );
        self::assertSame(['alive' => true, 'order' => 2, 'recordId' => null], $accepted['value'] ?? null);
    }

    /** @return array<string, array{type: string, initial: mixed}> */
    private function fullDraft(string $id, bool $removed): array
    {
        $fields = [
            'items[__collection__]' => ['type' => 'collection', 'initial' => []],
            'items[__member__]' => [
                'type' => 'member',
                'initial' => ['alive' => false, 'order' => 0, 'recordId' => null],
            ],
            'items[__name__][kind]' => ['type' => 'value', 'initial' => 'a'],
        ];
        $this->journal->join($id, $fields);
        $batches = [
            ['items[@n1]', 0, ['alive' => true, 'order' => 1, 'recordId' => null]],
            ['items[n1][kind]', 0, 'b'],
        ];
        if ($removed) {
            $batches[] = ['items[@n1]', 1, ['alive' => false, 'order' => 1]];
        }
        foreach ($batches as $index => [$field, $version, $value]) {
            $this->journal->append(
                $id,
                $fields,
                $this->event([
                    'id' => 'full-batch-' . $index . '-123456',
                    'field' => $field,
                    'kind' => 'value',
                    'version' => $version,
                    'value' => $value,
                ]),
                1,
                'Alice',
            );
        }
        $this->journal->saved(
            $id,
            $removed
                ? ['items[__collection__]' => []]
                : [
                    'items[__collection__]' => ['n1'],
                    'items[@n1]' => ['alive' => true, 'order' => 1, 'recordId' => '7'],
                    'items[n1][kind]' => 'b',
                ],
        );
        // The entry's saved field, repeated up to the limit under the entry: the rows of its subfields.
        $state =
            (string) $this->db->fetchOne('SELECT state FROM collaborate_draft_field WHERE draft_id = ? AND name = ?', [
                $id,
                'items[n1][kind]',
            ]);
        $rows = (int) $this->db->fetchOne('SELECT COUNT(*) FROM collaborate_draft_field WHERE draft_id = ?', [$id]);
        foreach (array_chunk(range($rows, self::MAX_FIELDS - 1), 1000) as $chunk) {
            $this->db->executeStatement(
                'INSERT INTO collaborate_draft_field (draft_id, name, type, state) VALUES '
                    . implode(', ', array_fill(0, \count($chunk), "(?, ?, 'value', ?)")),
                array_merge(...array_map(static fn(int $index): array => [
                    $id,
                    "items[n1][sub{$index}]",
                    $state,
                ], $chunk)),
            );
        }
        $this->db->update('collaborate_draft', ['updated_at' => '2000-01-01 00:00:00'], ['id' => $id]);

        return $fields;
    }

    public function testAFullDraftOfRemovedEntriesTakesNewEntriesAgainOnceSavedIdleAndJoined(): void
    {
        $fields = $this->fullDraft('full', true);
        $added = $this->event([
            'id' => 'new-entry-batch-123456',
            'field' => 'items[@n2]',
            'kind' => 'value',
            'version' => 0,
            'value' => ['alive' => true, 'order' => 2, 'recordId' => null],
        ]);
        try {
            $this->journal->append('full', $fields, $added, 1, 'Alice');
            self::fail('A field beyond the draft row limit was accepted.');
        } catch (HttpException $error) {
            self::assertSame(413, $error->getStatusCode());
        }

        $joined = $this->journal->join('full', $fields);

        self::assertSame(2, $joined['generation']);
        self::assertSame(['items[__collection__]'], array_keys($joined['fields']));
        $accepted = $this->journal->append(
            'full',
            $fields,
            array_replace($added, ['generation' => 2, 'seen' => $joined['revision']]),
            1,
            'Alice',
        );
        self::assertSame(['alive' => true, 'order' => 2, 'recordId' => null], $accepted['value'] ?? null);
    }

    public function testAFullDraftOfLiveEntriesIsNotCompactedOnEveryJoin(): void
    {
        $fields = $this->fullDraft('full', false);

        $joined = $this->journal->join('full', $fields);

        self::assertSame(1, $joined['generation']);
        self::assertSame(
            self::MAX_FIELDS,
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM collaborate_draft_field WHERE draft_id = ?', ['full']),
        );
    }

    public function testAValueNestedTooDeeplyForTheDraftViewIsRefusedAndTheDraftStillReplays(): void
    {
        $nested = 'leaf';
        for ($level = 0; $level < 510; ++$level) {
            $nested = [$nested];
        }
        $event = $this->event(['field' => 'choice', 'kind' => 'value', 'version' => 0]);
        try {
            $this->journal->append('record', $this->fields, ['value' => $nested] + $event, 1, 'Alice');
            self::fail('A value nested beyond the draft view was accepted.');
        } catch (BadRequestHttpException) {
        }
        $shallow = [[[[[[[['leaf']]]]]]]];
        $accepted = $this->journal->append(
            'record',
            $this->fields,
            ['id' => 'shallow-batch-123456', 'value' => $shallow] + $event,
            1,
            'Alice',
        );
        self::assertSame($shallow, $accepted['value'] ?? null);

        $replay = $this->journal->replay('record', $this->fields, 0);

        self::assertSame($shallow, $replay['fields']['choice']['value']);
        self::assertJson((string) new JsonResponse($replay)->getContent());
    }

    #[DataProvider('templateFields')]
    public function testAnEditAddressedToACollectionTemplateIsRejectedAndNotStored(string $field, mixed $value): void
    {
        $fields = [
            'items[__member__]' => [
                'type' => 'member',
                'initial' => ['alive' => false, 'order' => 0, 'recordId' => null],
            ],
            'items[__name__][kind]' => ['type' => 'value', 'initial' => 'a'],
        ];
        $this->journal->join('collection', $fields);
        try {
            $this->journal->append(
                'collection',
                $fields,
                $this->event(['field' => $field, 'kind' => 'value', 'version' => 0, 'value' => $value]),
                1,
                'Alice',
            );
            self::fail('An edit to a collection template was accepted.');
        } catch (BadRequestHttpException) {
        }
        $view = $this->journal->replay('collection', $fields, 0);
        self::assertSame([], $view['events']);
        self::assertArrayNotHasKey($field, $view['fields']);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function templateFields(): iterable
    {
        yield 'entry field' => ['items[__name__][kind]', 'b'];
        yield 'membership' => ['items[__member__]', ['alive' => true, 'order' => 1, 'recordId' => null]];
    }

    public function testRejectsIncompatibleSchemasAndDuplicateNewRecordCreation(): void
    {
        $this->journal->join('schema', ['body' => ['type' => 'rich', 'initial' => '', 'schema' => 'v1']]);
        $this->journal->append(
            'schema',
            ['body' => ['type' => 'rich', 'initial' => '', 'schema' => 'v1']],
            $this->event(),
            1,
            'Alice',
        );
        try {
            $this->journal->join('schema', ['body' => ['type' => 'rich', 'initial' => '', 'schema' => 'v2']]);
            self::fail('An incompatible editor joined the draft.');
        } catch (ConflictHttpException) {
            self::assertSame(
                1,
                $this->journal->replay('schema', ['body' => ['type' => 'rich', 'initial' => '']], 0)['revision'],
            );
        }
        $this->journal->created('record', '42');
        self::assertSame('42', $this->journal->replay('record', $this->fields, 0)['recordId']);
        $this->expectException(ConflictHttpException::class);
        $this->journal->lockSave('record', 1, 0, [], true);
    }

    public function testASaveOfADraftThatAlreadyCreatedItsRecordSaysSo(): void
    {
        $this->journal->created('record', '42');
        $request = new Request(request: ['_collaborate_generation' => '1', '_collaborate_revision' => '0']);
        try {
            new DraftSave(
                $this->journal,
                new RequestStack(),
                $this->identifier(),
                new NullLogger(),
                new IdentityTranslator(),
            )->begin($request, $this->scope(true), [], 'Alice');
            self::fail('A second creation was accepted.');
        } catch (ConflictHttpException) {
            self::assertSame(DraftJournal::ALREADY_CREATED, $request->attributes->getString('_collaborate_conflict'));
            self::assertFalse($this->locked('record'));
        }
    }

    public function testSaveGuardRejectsMissingAndStaleRevisionsWithoutLeakingTransactions(): void
    {
        $guard = new DraftSave(
            $this->journal,
            new RequestStack(),
            $this->identifier(),
            new NullLogger(),
            new IdentityTranslator(),
        );
        foreach ([
            // A form posted without its revision returns to the form, which synchronizes it.
            [new Request(), 'Synchronize the shared draft before saving.'],
            [
                new Request(request: ['_collaborate_generation' => '8', '_collaborate_revision' => '0']),
                'The shared draft changed while saving. Review it and save again.',
            ],
        ] as [$request, $stale]) {
            try {
                $guard->begin($request, $this->scope(), [], 'Alice');
                self::fail('Missing or stale revision was accepted.');
            } catch (ConflictHttpException) {
                self::assertFalse($this->db->isTransactionActive());
                self::assertFalse($this->locked('record'));
                self::assertFalse($request->attributes->has('_collaborate_save'));
                self::assertSame($stale, $request->attributes->getString('_collaborate_conflict'));
            }
        }
        $journal = $this->createMock(DraftJournal::class);
        $journal->expects($this->once())->method('lock');
        $journal->method('lockSave')->willThrowException(new \RuntimeException('database'));
        $journal->expects($this->once())->method('unlock')->with('record');
        try {
            new DraftSave(
                $journal,
                new RequestStack(),
                $this->identifier(),
                new NullLogger(),
                new IdentityTranslator(),
            )->begin(
                new Request(request: ['_collaborate_generation' => '1', '_collaborate_revision' => '0']),
                $this->scope(),
                [],
                'Alice',
            );
            self::fail('A failed guard kept its lock.');
        } catch (\RuntimeException $error) {
            self::assertSame('database', $error->getMessage());
        }
        $kernel = $this->createStub(KernelInterface::class);
        $guard->finish(new ResponseEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST, new Response()));
    }

    public function testSchemaAdoptionIsAllowedOnlyBeforeModelOperationsAndStaleGenerationsAreRejected(): void
    {
        $this->journal->join('adopt', ['body' => ['type' => 'rich', 'initial' => '', 'schema' => 'v1']]);
        $fields = ['body' => ['type' => 'rich', 'initial' => 'new', 'schema' => 'v2', 'formName' => 'body']];
        $view = $this->journal->join('adopt', $fields);
        self::assertSame('new', $view['fields']['body']['initial']);
        self::assertSame('v2', $view['fields']['body']['schema']);
        try {
            $this->journal->append('adopt', $fields, $this->event(['generation' => 9]), 1, 'Alice');
            self::fail('A stale generation was accepted.');
        } catch (ConflictHttpException) {
            self::assertSame(0, $this->journal->replay('adopt', $fields, 0)['revision']);
        }
        try {
            $this->journal->reconcile('adopt', 1, 2, $fields, true);
            self::fail('A stale reconciliation was accepted.');
        } catch (ConflictHttpException) {
            self::assertSame(0, $this->journal->replay('adopt', $fields, 0)['revision']);
        }
    }

    public function testFailedCheckpointKeepsTheSavedResponseLogsAndReleasesTheDraftLock(): void
    {
        $journal = $this->createMock(DraftJournal::class);
        $journal->expects($this->once())->method('lock')->with('record');
        $journal->expects($this->once())->method('unlock')->with('record');
        $journal->expects($this->once())->method('lockSave');
        $failure = new \RuntimeException('checkpoint failed');
        $journal->expects($this->once())->method('saved')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('error')
            ->with('Shared draft checkpoint failed after the record was saved.', [
                'draft' => 'record',
                'exception' => $failure,
            ]);
        $requests = new RequestStack();
        $request = new Request(request: ['_collaborate_generation' => '1', '_collaborate_revision' => '0']);
        $requests->push($request);
        $guard = new DraftSave($journal, $requests, $this->identifier(), $logger, new IdentityTranslator());
        $guard->begin($request, $this->scope(), [], 'Alice');
        $guard->completed(new Article());
        $redirect = new Response('', Response::HTTP_FOUND, ['Location' => '/admin/article/1/edit']);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $redirect,
        );

        // The record is committed: the editor gets the save's own response, not an error page.
        $guard->finish($event);

        self::assertSame($redirect, $event->getResponse());
        self::assertFalse($request->attributes->has('_collaborate_save'));
        self::assertFalse($request->attributes->has('_collaborate_committed'));
    }

    public function testAppendChecksSchemaAndMembershipShapeAndAliasesIgnoreNonMembers(): void
    {
        self::assertSame([], $this->journal->aliases('record'));
        $fields = [
            'member' => ['type' => 'member', 'initial' => ['alive' => false, 'order' => 0]],
            'body' => ['type' => 'rich', 'initial' => '', 'schema' => 'v1'],
        ];
        $this->journal->join('shape', $fields);
        try {
            $this->journal->append(
                'shape',
                $fields,
                $this->event(['field' => 'member', 'kind' => 'value', 'version' => 0, 'value' => []]),
                1,
                'Alice',
            );
            self::fail('An invalid collection member was accepted.');
        } catch (BadRequestHttpException) {
            self::assertSame(0, $this->journal->replay('shape', $fields, 0)['revision']);
        }
        $fields['body']['schema'] = 'v2';
        $this->expectException(ConflictHttpException::class);
        $this->journal->append('shape', $fields, $this->event(), 1, 'Alice');
    }

    public function testSaveGuardCheckpointsOnlyAfterPersistenceAndAlwaysReleasesItsLock(): void
    {
        $requests = new RequestStack();
        $guard = new DraftSave(
            $this->journal,
            $requests,
            $this->identifier(),
            new NullLogger(),
            new IdentityTranslator(),
        );
        $scope = $this->scope(true);
        $request = new Request(request: ['_collaborate_generation' => '1', '_collaborate_revision' => '0']);
        $requests->push($request);
        $guard->begin($request, $scope, ['body' => 'abcd'], 'Alice');
        self::assertFalse($this->db->isTransactionActive(), 'The editorial flush must commit on its own.');
        self::assertTrue($this->locked('record'));
        $request->attributes->set('_collaborate_snapshot', static fn(): array => ['body' => 'saved']);
        $guard->completed($this->article(42));
        $kernel = $this->createStub(KernelInterface::class);
        $redirect = new Response(status: 302, headers: ['Location' => '/admin/article?draft=nonce&query=kept']);
        $guard->finish(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $redirect));
        self::assertFalse($this->locked('record'));
        self::assertSame('/admin/article?query=kept', $redirect->headers->get('Location'));
        self::assertSame('record', $request->attributes->get('_collaborate_committed'));
        $view = $this->journal->replay('record', $this->fields, 0);
        self::assertSame('saved', $view['fields']['body']['saved']);
        // The editors still on the draft see who saved it.
        self::assertSame(['userId' => 7, 'label' => 'Alice'], $view['savedBy']);
        $scope['new'] = false;
        $request->attributes->remove('_collaborate_committed');
        $guard->begin($request, $scope, ['body' => 'saved'], 'Alice');
        $guard->finish(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));
        self::assertFalse($this->locked('record'));
        self::assertFalse($request->attributes->has('_collaborate_committed'));
        $guard->begin($request, $scope, ['body' => 'saved'], 'Alice');
        $failure = new ExceptionEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('failure'),
        );
        $guard->failed($failure);
        self::assertFalse($this->locked('record'));
        self::assertNull($failure->getResponse());
        $guard->failed(
            new ExceptionEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, new \RuntimeException('failure')),
        );
        $guard->completed(new Article());
        $guard->finish(new ResponseEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, new Response()));
        self::assertNotEmpty(DraftSave::getSubscribedEvents());
    }

    public function testANewRecordWithACompositeKeyIsNotRecordedAsCreated(): void
    {
        $journal = $this->createMock(DraftJournal::class);
        $journal->expects($this->never())->method('created');
        $requests = new RequestStack();
        $request = new Request(request: ['_collaborate_generation' => '1', '_collaborate_revision' => '0']);
        $requests->push($request);
        $guard = new DraftSave($journal, $requests, $this->identifier(), new NullLogger(), new IdentityTranslator());
        $guard->begin($request, $this->scope(true), [], 'Alice');
        $entry = new Tagging($this->article(7), new Tag());

        $guard->completed($entry);

        self::assertTrue($request->attributes->all('_collaborate_save')['completed']);
    }

    public function testSaveConflictReturnsToTheFormWithAWarning(): void
    {
        $guard = new DraftSave(
            $this->journal,
            new RequestStack(),
            $this->identifier(),
            new NullLogger(),
            new IdentityTranslator(),
        );
        $this->journal->append('record', $this->fields, $this->event(), 2, 'Bob');
        $request = Request::create('/admin/article/1/edit?referrer=list', 'POST', [
            '_collaborate_generation' => '1',
            '_collaborate_revision' => '0',
        ]);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        try {
            $guard->begin($request, $this->scope(), ['body' => 'abcd'], 'Alice');
            self::fail('A save behind a peer edit was accepted.');
        } catch (ConflictHttpException $conflict) {
            $event = new ExceptionEvent(
                $this->createStub(KernelInterface::class),
                $request,
                HttpKernelInterface::MAIN_REQUEST,
                $conflict,
            );
            $guard->failed($event);
            $response = $event->getResponse();
            self::assertNotNull($response);
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/admin/article/1/edit?referrer=list', $response->headers->get('Location'));
            self::assertTrue($event->isAllowingCustomResponseCode());
            self::assertSame(
                ['The shared draft changed while saving. Review it and save again.'],
                $session->getFlashBag()->get('warning'),
            );
            self::assertFalse($this->locked('record'));
        }
    }

    public function testSaveBehindAnotherSaveReturnsToTheFormWithAWarning(): void
    {
        $other = DriverManager::getConnection($this->db->getParams());
        $holder = new DraftJournal($other, firstGeneration: 1);
        $holder->lock('record');
        $guard = new DraftSave(
            new DraftJournal($this->db, limits: ['locks' => ['save' => 0]], firstGeneration: 1),
            new RequestStack(),
            $this->identifier(),
            new NullLogger(),
            $this->translator(),
        );
        $request = Request::create('/admin/article/1/edit', 'POST', [
            '_collaborate_generation' => '1',
            '_collaborate_revision' => '0',
        ]);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        try {
            $guard->begin($request, $this->scope(), ['body' => 'abcd'], 'Alice');
            self::fail('A save entered while another save held the draft.');
        } catch (ServiceUnavailableHttpException $busy) {
            $event = new ExceptionEvent(
                $this->createStub(KernelInterface::class),
                $request,
                HttpKernelInterface::MAIN_REQUEST,
                $busy,
            );
            $guard->failed($event);
            $response = $event->getResponse();
            self::assertNotNull($response);
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/admin/article/1/edit', $response->headers->get('Location'));
            // The warning is shown in the editor's language.
            self::assertSame(
                ['[collaborate] Another save of the shared draft is in progress. Save again in a moment.'],
                $session->getFlashBag()->get('warning'),
            );
        } finally {
            $holder->reset();
            $other->close();
        }
        self::assertFalse($this->locked('record'));
    }

    /** Named locks are global to the MariaDB server: the same record's draft in another database (another
     * environment or test instance) holds its own lock.
     */
    public function testTheSameDraftInAnotherDatabaseOfTheServerHoldsItsOwnLock(): void
    {
        $other = DriverManager::getConnection(['dbname' => 'information_schema'] + $this->db->getParams());
        $elsewhere = new DraftJournal($other, limits: ['locks' => ['save' => 0]], firstGeneration: 1);
        $elsewhere->lock('record');
        try {
            $this->journal->lock('record', 0);
            self::assertTrue($this->locked('record'));
            $this->journal->unlock('record');
        } finally {
            $elsewhere->reset();
            $other->close();
        }
        self::assertLessThanOrEqual(64, \strlen($this->lockName('record')));
    }

    public function testDraftWritersWaitForTheSaveLockAndReleaseItOnReset(): void
    {
        $other = DriverManager::getConnection($this->db->getParams());
        $busy = new DraftJournal($other, limits: ['locks' => ['save' => 0]], firstGeneration: 1);
        $this->journal->lock('record');
        $this->journal->lock('record');
        $this->journal->unlock('record');
        try {
            $busy->append('record', $this->fields, $this->event(), 2, 'Bob');
            self::fail('A peer appended while a save held the draft.');
        } catch (ServiceUnavailableHttpException $error) {
            self::assertSame(1, $error->getHeaders()['Retry-After'] ?? null);
        }
        $this->journal->unlock('nothing-held');
        $this->journal->reset();
        self::assertFalse($this->locked('record'));
        $this->journal->reset();
        $other->close();
    }

    public function testAnAppendRewritesItsOwnFieldStateOnly(): void
    {
        // Temporary tables are private to $this->db: the recording connection works on the real tables.
        $params = $this->db->getParams();
        $recording = DriverManager::getConnection(['wrapperClass' => StatementRecordingConnection::class] + $params);
        \assert($recording instanceof StatementRecordingConnection);
        $id = 'append-field-' . bin2hex(random_bytes(8));
        $journal = new DraftJournal($recording, firstGeneration: 1);
        try {
            $journal->join($id, $this->fields);
            $recording->executeStatement('UPDATE collaborate_draft SET updated_at = ? WHERE id = ?', [
                '2000-01-01',
                $id,
            ]);
            $before = \count($recording->parameters);

            $journal->append(
                $id,
                $this->fields,
                $this->event(['field' => 'choice', 'kind' => 'value', 'version' => 0, 'value' => 'b']),
                1,
                'Alice',
            );

            $written = [];
            foreach (\array_slice($recording->parameters, $before) as $parameters) {
                $written = [...$written, ...array_values($parameters)];
            }
            self::assertContains('choice', $written);
            self::assertNotContains('body', $written);
            self::assertSame('b', $journal->replay($id, $this->fields, 0)['fields']['choice']['value']);
            self::assertGreaterThan(
                '2000-01-02',
                (string) $recording->fetchOne('SELECT updated_at FROM collaborate_draft WHERE id = ?', [$id]),
            );
        } finally {
            $recording->delete('collaborate_draft_event', ['draft_id' => $id]);
            $recording->delete('collaborate_draft_field', ['draft_id' => $id]);
            $recording->delete('collaborate_draft', ['id' => $id]);
            $recording->close();
        }
    }

    public function testARetriedBatchGetsItsStoredResultAfterTheRecordIsCreatedOrItsEntryRemoved(): void
    {
        $fields = [
            'items[__collection__]' => ['type' => 'collection', 'initial' => []],
            'items[__member__]' => [
                'type' => 'member',
                'initial' => ['alive' => false, 'order' => 0, 'recordId' => null],
            ],
            'items[__name__][kind]' => ['type' => 'value', 'initial' => 'a'],
        ];
        $this->journal->join('retry', $fields);
        $member = $this->event([
            'id' => 'member-batch-123456',
            'field' => 'items[@n1]',
            'kind' => 'value',
            'version' => 0,
            'value' => ['alive' => true, 'order' => 1, 'recordId' => null],
        ]);
        $this->journal->append('retry', $fields, $member, 1, 'Alice');
        $kind = $this->event([
            'id' => 'kind-batch-12345678',
            'field' => 'items[n1][kind]',
            'kind' => 'value',
            'version' => 0,
            'value' => 'b',
        ]);
        $accepted = $this->journal->append('retry', $fields, $kind, 1, 'Alice');
        $this->journal->append(
            'retry',
            $fields,
            array_replace($member, [
                'id' => 'remove-batch-123456',
                'version' => 1,
                'value' => ['alive' => false, 'order' => 1],
            ]),
            2,
            'Bob',
        );

        // Entries without a stored record have no alias.
        self::assertSame([], $this->journal->aliases('retry'));
        // The response was lost: the retry finds the entry removed, then the record created.
        self::assertSameJson($accepted, $this->journal->append('retry', $fields, $kind, 1, 'Alice'));
        $this->journal->created('retry', '42');
        self::assertSameJson($accepted, $this->journal->append('retry', $fields, $kind, 1, 'Alice'));
        $this->expectException(ConflictHttpException::class);
        $this->journal->append('retry', $fields, array_replace($kind, ['id' => 'fresh-batch-1234567']), 1, 'Alice');
    }

    public function testCompactionDropsRemovedCollectionEntries(): void
    {
        $fields = [
            'items[__collection__]' => ['type' => 'collection', 'initial' => []],
            'items[__member__]' => [
                'type' => 'member',
                'initial' => ['alive' => false, 'order' => 0, 'recordId' => null],
            ],
            'items[__name__][kind]' => ['type' => 'value', 'initial' => 'a'],
        ];
        $this->journal->join('prune', $fields);
        foreach ([
            ['items[@n1]', 0, ['alive' => true, 'order' => 1, 'recordId' => null]],
            ['items[n1][kind]', 0, 'b'],
            ['items[@n2]', 0, ['alive' => true, 'order' => 2, 'recordId' => null]],
            ['items[@n1]', 1, ['alive' => false, 'order' => 1]],
        ] as $index => [$field, $version, $value]) {
            $this->journal->append(
                'prune',
                $fields,
                $this->event([
                    'id' => 'prune-batch-' . $index . '-123456',
                    'field' => $field,
                    'kind' => 'value',
                    'version' => $version,
                    'value' => $value,
                ]),
                1,
                'Alice',
            );
        }
        $this->journal->saved('prune', [
            'items[__collection__]' => ['n2'],
            'items[@n2]' => ['alive' => true, 'order' => 1, 'recordId' => '7'],
        ]);
        // Large, saved and idle: the next join compacts it.
        $this->db->executeStatement('UPDATE collaborate_draft SET bytes = ?, updated_at = ? WHERE id = ?', [
            self::COMPACT_BYTES + 1,
            '2000-01-01 00:00:00',
            'prune',
        ]);

        $compacted = $this->journal->join('prune', $fields);

        self::assertSame(2, $compacted['generation']);
        self::assertSame(['items[@n2]', 'items[__collection__]'], array_keys($compacted['fields']));
        self::assertSame(
            ['items[@n2]', 'items[__collection__]'],
            $this->db->fetchFirstColumn('SELECT name FROM collaborate_draft_field WHERE draft_id = ? ORDER BY name', [
                'prune',
            ]),
        );
    }

    public function testPurgeKeepsADraftWrittenAfterItWasSelected(): void
    {
        // Temporary tables are private to $this->db: the race runs on the real tables.
        $params = $this->db->getParams();
        $purger = DriverManager::getConnection(['wrapperClass' => InterleavingConnection::class] + $params);
        \assert($purger instanceof InterleavingConnection);
        $writer = DriverManager::getConnection($params);
        $id = 'purge-race-' . bin2hex(random_bytes(8));
        $now = new \DateTimeImmutable();
        try {
            new DraftJournal($writer, firstGeneration: 1)->join($id, $this->fields);
            $writer->update(
                'collaborate_draft',
                ['updated_at' => $now->modify('-' . (self::CLEAN_DAYS + 1) . ' days')->format('Y-m-d H:i:s')],
                ['id' => $id],
            );
            $purger->pattern = 'DELETE FROM collaborate_draft WHERE';
            $purger->before = static function () use ($writer, $id): void {
                $writer->update(
                    'collaborate_draft',
                    ['updated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s')],
                    ['id' => $id],
                );
            };

            new DraftJournal($purger, firstGeneration: 1)->purge($now);

            self::assertNull($purger->before);
            self::assertSame(1, (int) $writer->fetchOne('SELECT COUNT(*) FROM collaborate_draft WHERE id = ?', [$id]));
            self::assertSame(
                2,
                (int) $writer->fetchOne('SELECT COUNT(*) FROM collaborate_draft_field WHERE draft_id = ?', [$id]),
            );
        } finally {
            $writer->delete('collaborate_draft_field', ['draft_id' => $id]);
            $writer->delete('collaborate_draft', ['id' => $id]);
            $purger->close();
            $writer->close();
        }
    }

    public function testAWriterWaitsForTheDraftLockOneSecondAtMost(): void
    {
        $other = DriverManager::getConnection($this->db->getParams());
        $this->journal->lock('record');
        $started = microtime(true);
        try {
            new DraftJournal($other, firstGeneration: 1)->append('record', $this->fields, $this->event(), 2, 'Bob');
            self::fail('A peer appended while a save held the draft.');
        } catch (ServiceUnavailableHttpException) {
            $waited = microtime(true) - $started;
            self::assertGreaterThanOrEqual(0.9, $waited);
            self::assertLessThan(self::LOCK_SECONDS, $waited);
        } finally {
            $this->journal->unlock('record');
            $other->close();
        }
    }

    public function testADraftRecreatedAfterThePurgeSupersedesPagesOfThePurgedOne(): void
    {
        $now = new \DateTimeImmutable('2026-10-20 12:00:00');
        $journal = new DraftJournal($this->db);
        $purged = $journal->join('purged', $this->fields)['generation'];
        $journal->append('purged', $this->fields, $this->event(['generation' => $purged]), 1, 'Alice');
        $journal->saved('purged', ['body' => 'edited']);
        $this->db->update(
            'collaborate_draft',
            [
                'updated_at' => $now->modify('-' . self::CLEAN_DAYS . ' days -1 minute')->format('Y-m-d H:i:s'),
            ],
            ['id' => 'purged'],
        );
        $journal->purge($now);

        $recreated = $journal->join('purged', $this->fields)['generation'];

        self::assertNotSame($purged, $recreated);
        self::assertSame($recreated, $journal->replay('purged', $this->fields, 0)['generation']);
        $this->expectException(ConflictHttpException::class);
        $journal->append('purged', $this->fields, $this->event(['generation' => $purged]), 1, 'Alice');
    }

    public function testPurgeDeletesIdleSavedOrCreatedDraftsAndLongAbandonedOnes(): void
    {
        $now = new \DateTimeImmutable('2026-10-20 12:00:00');
        $idle = static fn(int $days): string => $now->modify('-' . $days . ' days -1 minute')->format('Y-m-d H:i:s');
        foreach (['clean', 'unsaved', 'abandoned', 'created', 'held', 'recent'] as $id) {
            $this->journal->join($id, $this->fields);
        }
        foreach (['unsaved', 'abandoned', 'created'] as $id) {
            $this->journal->append($id, $this->fields, $this->event(), 1, 'Alice');
        }
        $this->journal->created('created', '42');
        foreach ([
            'clean' => self::CLEAN_DAYS,
            'unsaved' => self::CLEAN_DAYS,
            'abandoned' => self::UNSAVED_DAYS,
            'created' => self::CLEAN_DAYS,
            'held' => self::CLEAN_DAYS,
            'recent' => self::CLEAN_DAYS - 1,
        ] as $id => $days) {
            $this->db->update('collaborate_draft', ['updated_at' => $idle($days)], ['id' => $id]);
        }
        $other = DriverManager::getConnection($this->db->getParams());
        $other->fetchOne('SELECT GET_LOCK(?, 0)', [$this->lockName('held')]);

        try {
            self::assertSame(3, $this->journal->purge($now));
        } finally {
            $other->close();
        }

        $kept = ['held', 'recent', 'record', 'unsaved'];
        self::assertSame($kept, $this->db->fetchFirstColumn('SELECT id FROM collaborate_draft ORDER BY id'));
        self::assertSame(
            $kept,
            $this->db->fetchFirstColumn('SELECT DISTINCT draft_id FROM collaborate_draft_field ORDER BY draft_id'),
        );
        self::assertSame(
            ['unsaved'],
            $this->db->fetchFirstColumn('SELECT DISTINCT draft_id FROM collaborate_draft_event'),
        );
        self::assertFalse($this->locked('clean'));
    }

    public function testJoinCompactsOnlyALargeDraftWithoutUnsavedChanges(): void
    {
        $this->journal->append('record', $this->fields, $this->event(), 1, 'Alice');
        $this->db->executeStatement('UPDATE collaborate_draft SET bytes = ? WHERE id = ?', [
            self::COMPACT_BYTES + 1,
            'record',
        ]);
        $unsaved = $this->journal->join('record', $this->fields);
        self::assertSame(1, $unsaved['generation']);
        self::assertCount(1, $unsaved['events']);

        $this->journal->saved('record', ['body' => 'edited', 'choice' => 'a']);
        $this->db->executeStatement('UPDATE collaborate_draft SET bytes = ? WHERE id = ?', [
            self::COMPACT_BYTES + 1,
            'record',
        ]);
        // The saver's own reload follows its save: a peer may still be typing, so nothing is rebased yet.
        self::assertSame(1, $this->journal->join('record', $this->fields)['generation']);
        $this->db->executeStatement('UPDATE collaborate_draft SET updated_at = ? WHERE id = ?', [
            new \DateTimeImmutable('-' . (self::COMPACT_IDLE_SECONDS + 1) . ' seconds')->format('Y-m-d H:i:s.u'),
            'record',
        ]);
        $compacted = $this->journal->join('record', $this->fields);
        self::assertSame(2, $compacted['generation']);
        self::assertSame(2, $compacted['revision']);
        self::assertSame(2, $compacted['savedRevision']);
        self::assertSame([], $compacted['events']);
        self::assertSame('edited', $compacted['fields']['body']['initial']);
        self::assertNull($compacted['fields']['body']['version']);
        self::assertArrayNotHasKey('initialVersion', $compacted['fields']['body']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT bytes FROM collaborate_draft WHERE id = ?', ['record']));
        $accepted = $this->journal->append(
            'record',
            $this->fields,
            $this->event([
                'generation' => 2,
                'version' => 7,
                'operations' => [
                    ['__className' => 'InsertOperation', 'baseVersion' => 7],
                ],
            ]),
            1,
            'Alice',
        );
        self::assertSame(3, $accepted['revision']);

        $this->journal->created('record', '42');
        $this->journal->saved('record', ['body' => 'edited']);
        $this->db->executeStatement('UPDATE collaborate_draft SET bytes = ?, updated_at = ? WHERE id = ?', [
            self::COMPACT_BYTES + 1,
            '2000-01-01 00:00:00',
            'record',
        ]);
        self::assertSame(2, $this->journal->join('record', $this->fields)['generation']);
    }

    public function testSavedModelsAdoptANewEditorSchema(): void
    {
        $v1 = ['body' => ['type' => 'rich', 'initial' => 'abcd', 'schema' => 'v1']];
        $this->journal->join('schema', $v1);
        $this->journal->append('schema', $v1, $this->event(), 1, 'Alice');
        $this->journal->saved('schema', ['body' => 'edited']);
        $view = $this->journal->join('schema', ['body' => ['type' => 'rich', 'initial' => 'edited', 'schema' => 'v2']]);
        self::assertSame('v2', $view['fields']['body']['schema']);
        self::assertNull($view['fields']['body']['version']);
        self::assertNull($view['fields']['body']['savedVersion']);
        self::assertArrayNotHasKey('initialVersion', $view['fields']['body']);
        self::assertSame('edited', $view['fields']['body']['initial']);
        // The old schema's operations are already part of the adopted value.
        self::assertSame([], $view['events']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT bytes FROM collaborate_draft WHERE id = ?', ['schema']));
        self::assertSame(
            [],
            $this->journal->join('schema', ['body' => [
                'type' => 'rich',
                'initial' => 'edited',
                'schema' => 'v2',
            ]])['events'],
        );
    }
}
