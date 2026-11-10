<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft\Dialect;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * What the draft journal writes in its database's own SQL: a draft's row created once, its fields' rows written
 * whole, a draft's row read for update, and the named lock that serializes a draft's writers across requests
 * (held across a save's validation and persistence, outside any transaction).
 *
 * @internal
 */
abstract class JournalDialect
{
    /** The dialect of a connection's platform: MariaDB and MySQL, PostgreSQL, SQLite. */
    public static function for(Connection $connection): self
    {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof AbstractMySQLPlatform => new MySqlDialect(),
            $platform instanceof PostgreSQLPlatform => new PostgreSqlDialect(),
            $platform instanceof SQLitePlatform => new SqliteDialect(),
            default => throw new \LogicException(\sprintf(
                'The shared-draft journal needs a MariaDB, MySQL, PostgreSQL or SQLite connection; %s is not supported.',
                $platform::class,
            )),
        };
    }

    /** Creates a draft's row unless it exists: id, generation, updated_at. */
    abstract public function createDraft(): string;

    /**
     * Writes `$rows` fields' rows whole, replacing those that exist: draft_id, name, type, state each.
     *
     * @param non-negative-int $rows
     */
    abstract public function writeFields(int $rows): string;

    /** What a read of a draft's row ends with to hold it until its transaction ends. */
    abstract public function forUpdate(): string;

    /** Takes the named lock, waiting at most `$seconds`: whether it was taken. A connection may take it again. */
    abstract public function lock(Connection $connection, string $name, int $seconds): bool;

    /** Releases the named lock once. */
    abstract public function unlock(Connection $connection, string $name): void;

    /** @param non-negative-int $rows */
    protected static function values(int $rows): string
    {
        return implode(', ', array_fill(0, $rows, '(?, ?, ?, ?)'));
    }
}
