<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Janalis\Collaborate\Draft\Dialect\JournalDialect;
use Janalis\Collaborate\Draft\Dialect\MySqlDialect;
use Janalis\Collaborate\Draft\Dialect\PostgreSqlDialect;
use Janalis\Collaborate\Draft\Dialect\SqliteDialect;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The journal's SQL where the platforms differ: upserts, row locks and named locks. */
#[CoversClass(JournalDialect::class)]
#[CoversClass(MySqlDialect::class)]
#[CoversClass(PostgreSqlDialect::class)]
#[CoversClass(SqliteDialect::class)]
final class JournalDialectTest extends TestCase
{
    public function testEachPlatformHasItsDialect(): void
    {
        self::assertInstanceOf(MySqlDialect::class, JournalDialect::for($this->on(new MariaDBPlatform())));
        self::assertInstanceOf(PostgreSqlDialect::class, JournalDialect::for($this->on(new PostgreSQLPlatform())));
        self::assertInstanceOf(SqliteDialect::class, JournalDialect::for($this->on(new SQLitePlatform())));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('MariaDB, MySQL, PostgreSQL or SQLite');
        JournalDialect::for($this->on(new SQLServerPlatform()));
    }

    public function testUpsertsAndRowLocks(): void
    {
        $mysql = new MySqlDialect();
        self::assertStringStartsWith('INSERT IGNORE INTO collaborate_draft ', $mysql->createDraft());
        self::assertSame(
            'REPLACE INTO collaborate_draft_field (draft_id, name, type, state) VALUES (?, ?, ?, ?), (?, ?, ?, ?)',
            $mysql->writeFields(2),
        );
        self::assertSame(' FOR UPDATE', $mysql->forUpdate());

        $postgres = new PostgreSqlDialect();
        self::assertStringEndsWith(' ON CONFLICT (id) DO NOTHING', $postgres->createDraft());
        self::assertSame(
            'INSERT INTO collaborate_draft_field (draft_id, name, type, state) VALUES (?, ?, ?, ?) '
            . 'ON CONFLICT (draft_id, name) DO UPDATE SET type = EXCLUDED.type, state = EXCLUDED.state',
            $postgres->writeFields(1),
        );
        self::assertSame(' FOR UPDATE', $postgres->forUpdate());

        $sqlite = new SqliteDialect();
        self::assertStringStartsWith('INSERT OR IGNORE INTO collaborate_draft ', $sqlite->createDraft());
        self::assertStringStartsWith('INSERT OR REPLACE INTO collaborate_draft_field ', $sqlite->writeFields(1));
        self::assertSame('', $sqlite->forUpdate());
    }

    public function testMySqlTakesNamedLocks(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::exactly(3))
            ->method('fetchOne')
            ->willReturnCallback(static fn(string $sql, array $parameters): mixed => match ($sql) {
                'SELECT GET_LOCK(?, ?)' => 'busy' === $parameters[0] ? 0 : 1,
                default => 1,
            });
        $dialect = new MySqlDialect();

        self::assertTrue($dialect->lock($connection, 'free', 5));
        self::assertFalse($dialect->lock($connection, 'busy', 5));
        $dialect->unlock($connection, 'free');
    }

    public function testPostgreSqlTriesAnAdvisoryLockUntilItsWaitIsOver(): void
    {
        $tries = 0;
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql, array $parameters) use (&$tries): mixed {
                if (str_starts_with($sql, 'SELECT pg_advisory_unlock')) {
                    return true;
                }

                return match ($parameters[0]) {
                    // Free on the second try, as pdo_pgsql may return a boolean.
                    'freed' => 2 === ++$tries ? 't' : false,
                    'free' => true,
                    default => false,
                };
            });
        $dialect = new PostgreSqlDialect();

        self::assertTrue($dialect->lock($connection, 'free', 0));
        self::assertTrue($dialect->lock($connection, 'freed', 5));
        self::assertSame(2, $tries);
        self::assertFalse($dialect->lock($connection, 'held', 0));
        $dialect->unlock($connection, 'free');
    }

    public function testSqliteLocksAFileItsProcessesShare(): void
    {
        $directory = sys_get_temp_dir() . '/collaborate-locks-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $connection = $this->createStub(Connection::class);
        $first = new SqliteDialect($directory);
        $second = new SqliteDialect($directory);

        self::assertTrue($first->lock($connection, 'draft', 0));
        // Held again by the process that holds it, without waiting.
        self::assertTrue($first->lock($connection, 'draft', 0));
        self::assertFalse($second->lock($connection, 'draft', 0));
        $first->unlock($connection, 'draft');
        // Waited for, as long as the wait allows.
        self::assertFalse($second->lock($connection, 'draft', 1));
        $first->unlock($connection, 'draft');
        self::assertTrue($second->lock($connection, 'draft', 0));
        $second->unlock($connection, 'draft');
        $second->unlock($connection, 'never taken');

        // A directory it cannot write to locks nothing; nor does a lock file that is a link.
        self::assertFalse(new SqliteDialect($directory . '/missing')->lock($connection, 'draft', 0));
        symlink($directory . '/elsewhere', $directory . '/.collaborate-' . md5('linked') . '.lock');
        self::assertFalse($first->lock($connection, 'linked', 0));
        unlink($directory . '/.collaborate-' . md5('linked') . '.lock');

        // An in-memory database's locks: in the user's own directory, which no one else may enter.
        $private = sys_get_temp_dir() . '/' . SqliteDialect::PRIVATE . '-' . (string) getmyuid();
        if (is_dir($private)) {
            $locks = glob($private . '/.collaborate-*.lock');
            array_map(unlink(...), false === $locks ? [] : $locks);
            rmdir($private);
        }
        self::assertTrue(new SqliteDialect()->lock($connection, 'default directory', 0));
        chmod($private, 0o777);
        self::assertFalse(new SqliteDialect()->lock($connection, 'shared directory', 0));
        chmod($private, 0o700);

        $files = glob($directory . '/.collaborate-*.lock');
        array_map(unlink(...), false === $files ? [] : $files);
        rmdir($directory);
    }

    public function testSqliteKeepsItsLocksNextToItsDatabase(): void
    {
        $directory = sys_get_temp_dir() . '/collaborate-database-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $file = $this->createStub(Connection::class);
        $file->method('getParams')->willReturn(['path' => $directory . '/app.sqlite']);
        $memory = $this->createStub(Connection::class);
        $memory->method('getParams')->willReturn(['memory' => true]);

        self::assertTrue(SqliteDialect::of($file)->lock($file, 'draft', 0));
        self::assertFileExists($directory . '/.collaborate-' . md5('draft') . '.lock');
        self::assertTrue(SqliteDialect::of($memory)->lock($memory, 'draft', 0));

        unlink($directory . '/.collaborate-' . md5('draft') . '.lock');
        rmdir($directory);
    }

    private function on(object $platform): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return $connection;
    }
}
