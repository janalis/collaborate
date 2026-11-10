<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft\Dialect;

use Doctrine\DBAL\Connection;

/** MariaDB's and MySQL's: INSERT IGNORE, REPLACE, and named locks global to the server. @internal */
final class MySqlDialect extends JournalDialect
{
    public function createDraft(): string
    {
        return 'INSERT IGNORE INTO collaborate_draft (id, generation, revision, saved_revision, bytes, updated_at) VALUES (?, ?, 0, 0, 0, ?)';
    }

    /** @param non-negative-int $rows */
    public function writeFields(int $rows): string
    {
        // Every column is written: REPLACE needs neither MySQL's row alias nor its deprecated VALUES().
        return 'REPLACE INTO collaborate_draft_field (draft_id, name, type, state) VALUES ' . self::values($rows);
    }

    public function forUpdate(): string
    {
        return ' FOR UPDATE';
    }

    public function lock(Connection $connection, string $name, int $seconds): bool
    {
        return 1 === (int) $connection->fetchOne('SELECT GET_LOCK(?, ?)', [$name, $seconds]);
    }

    public function unlock(Connection $connection, string $name): void
    {
        $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
    }
}
