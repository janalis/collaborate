<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Functional\Draft;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Janalis\Collaborate\Doctrine\PortableSchema;
use Janalis\Collaborate\Draft\Dialect\JournalDialect;
use Janalis\Collaborate\Draft\Dialect\MySqlDialect;
use Janalis\Collaborate\Draft\Dialect\PostgreSqlDialect;
use Janalis\Collaborate\Draft\Dialect\SqliteDialect;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftText;
use Janalis\Collaborate\Draft\DraftValues;
use Janalis\Collaborate\Draft\EventValidator;
use Janalis\Collaborate\Draft\FieldDefinitions;
use Janalis\Collaborate\Entity\Draft;
use Janalis\Collaborate\Tests\Fixtures\Draft\JsonAssertions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The journal on each database it speaks to, its tables created from the bundle's mapping: SQLite always,
 * PostgreSQL when `COLLABORATE_POSTGRES_URL` names a test database, MariaDB or MySQL with `COLLABORATE_DATABASE_URL`.
 */
#[CoversClass(DraftJournal::class)]
#[CoversClass(JournalDialect::class)]
#[CoversClass(MySqlDialect::class)]
#[CoversClass(PostgreSqlDialect::class)]
#[CoversClass(SqliteDialect::class)]
#[CoversClass(PortableSchema::class)]
#[UsesClass(DraftText::class)]
#[UsesClass(DraftValues::class)]
#[UsesClass(EventValidator::class)]
#[UsesClass(FieldDefinitions::class)]
final class DraftJournalPlatformsTest extends TestCase
{
    use JsonAssertions;

    /** @var array<string, array{type: string, initial: mixed}> */
    private const array FIELDS = [
        'title' => ['type' => 'text', 'initial' => 'Hello'],
        'choice' => ['type' => 'value', 'initial' => 'a'],
        'notes[__collection__]' => ['type' => 'collection', 'initial' => ['p0']],
        'notes[@p0]' => ['type' => 'member', 'initial' => ['alive' => true, 'order' => 1, 'recordId' => null]],
        'notes[__member__]' => ['type' => 'member', 'initial' => ['alive' => false, 'order' => 0, 'recordId' => null]],
    ];

    /** @var list<Connection> */
    private array $connections = [];

    private ?string $file = null;

    /** @return iterable<string, array{string}> */
    public static function platforms(): iterable
    {
        yield 'SQLite' => ['sqlite'];
        yield 'PostgreSQL' => ['pgsql'];
        yield 'MariaDB or MySQL' => ['mysql'];
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
    }

    #[DataProvider('platforms')]
    public function testADraftIsJoinedEditedSavedAndPurged(string $platform): void
    {
        $db = $this->database($platform);
        $journal = new DraftJournal($db, firstGeneration: 1);
        $view = $journal->join('record', self::FIELDS);
        self::assertSame(1, $view['generation']);
        // Joined again, by another page: the draft's row is created once.
        self::assertSame(
            1,
            new DraftJournal($this->connect($platform), firstGeneration: 7)->join('record', self::FIELDS)['generation'],
        );

        $event = [
            'id' => '12345678-1234-1234-1234-123456789abc',
            'generation' => 1,
            'seen' => 0,
            'field' => 'choice',
            'kind' => 'value',
            'version' => 0,
            'value' => 'b',
        ];
        $accepted = $journal->append('record', self::FIELDS, $event, 1, 'Alice');
        // MySQL returns JSON objects with their keys sorted.
        self::assertSameJson($accepted, $journal->append('record', self::FIELDS, $event, 1, 'Alice'));
        $replay = new DraftJournal($this->connect($platform))->replay('record', self::FIELDS, 0);
        self::assertCount(1, $replay['events']);
        self::assertSame('b', $replay['fields']['choice']['value']);

        // A save: the record still holds what the draft saw of it, then the checkpoint, with the collection's order.
        $journal->lock('record');
        $journal->lockSave(
            'record',
            1,
            $replay['revision'],
            array_map(static fn(array $field): mixed => $field['initial'], self::FIELDS),
        );
        $journal->saved(
            'record',
            [
                'choice' => 'b',
                'notes[__collection__]' => ['p0'],
                'notes[@p0]' => [
                    'alive' => true,
                    'order' => 1,
                    'recordId' => null,
                ],
            ],
            ['userId' => 1, 'label' => 'Alice'],
        );
        $journal->unlock('record');
        self::assertSame(['notes' => ['p0']], $journal->listKeys('record'));
        $this->expectSaveConflict($journal);

        self::assertSame(1, $journal->purge(new \DateTimeImmutable('+1 year')));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM collaborate_draft'));
    }

    #[DataProvider('platforms')]
    public function testADraftsWritersWaitForEachOther(string $platform): void
    {
        $this->database($platform);
        $first = new DraftJournal($this->connect($platform), limits: ['locks' => ['save' => 1, 'writer' => 1]]);
        $second = new DraftJournal($this->connect($platform), limits: ['locks' => ['save' => 1, 'writer' => 1]]);
        $first->lock('record');
        $first->lock('record');
        $first->unlock('record');
        try {
            $second->lock('record', 0);
            self::fail('A draft another writer holds is busy.');
        } catch (ServiceUnavailableHttpException) {
        }
        $first->unlock('record');
        $second->lock('record', 0);
        $second->reset();
        // A lock never taken is released as nothing.
        $second->unlock('other');
        $first->lock('record', 0);
        $first->unlock('record');
        self::assertTrue(true);
    }

    /** A save the draft moved on from is a conflict, on every platform. */
    private function expectSaveConflict(DraftJournal $journal): void
    {
        $journal->lock('record');
        try {
            $journal->lockSave('record', 1, 0, ['choice' => 'z']
            + array_map(static fn(array $field): mixed => $field['initial'], self::FIELDS));
            self::fail('The record changed since the draft saw it.');
        } catch (ConflictHttpException) {
        } finally {
            $journal->unlock('record');
        }
    }

    /** The platform's test database, its draft tables created anew from the bundle's mapping. */
    private function database(string $platform): Connection
    {
        $db = $this->connect($platform);
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(
            (string) new \ReflectionClass(Draft::class)->getFileName(),
        )]);
        $config->enableNativeLazyObjects(true);
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new PortableSchema());
        $manager = new EntityManager($db, $config, $events);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        return $db;
    }

    private function connect(string $platform): Connection
    {
        if ('sqlite' === $platform) {
            $this->file ??= sys_get_temp_dir() . '/collaborate-journal-' . bin2hex(random_bytes(4)) . '.sqlite';
            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->file]);
        } else {
            $variable = 'pgsql' === $platform ? 'COLLABORATE_POSTGRES_URL' : 'COLLABORATE_DATABASE_URL';
            $url = $_SERVER[$variable] ?? $_ENV[$variable] ?? getenv($variable);
            if (!\is_string($url) || '' === $url) {
                self::markTestSkipped($variable . ' names no test database.');
            }
            $connection = DriverManager::getConnection(new DsnParser([
                'mysql' => 'pdo_mysql',
                'mariadb' => 'pdo_mysql',
                'postgres' => 'pdo_pgsql',
                'postgresql' => 'pdo_pgsql',
                'pgsql' => 'pdo_pgsql',
            ])->parse($url));
        }
        $this->connections[] = $connection;

        return $connection;
    }
}
