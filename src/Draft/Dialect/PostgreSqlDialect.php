<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft\Dialect;

use Doctrine\DBAL\Connection;

/**
 * PostgreSQL's: ON CONFLICT, and session advisory locks, keyed by the lock name's 64-bit hash. A lock is tried
 * until its wait is over: `lock_timeout` would also bound the statements of the transaction it is taken in.
 *
 * @internal
 */
final class PostgreSqlDialect extends JournalDialect
{
    /** How long a wait for a lock sleeps between two tries, in microseconds. */
    private const int RETRY = 50_000;

    public function createDraft(): string
    {
        return 'INSERT INTO collaborate_draft (id, generation, revision, saved_revision, bytes, updated_at) VALUES (?, ?, 0, 0, 0, ?) ON CONFLICT (id) DO NOTHING';
    }

    /** @param non-negative-int $rows */
    public function writeFields(int $rows): string
    {
        return (
            'INSERT INTO collaborate_draft_field (draft_id, name, type, state) VALUES '
            . self::values($rows)
            . ' ON CONFLICT (draft_id, name) DO UPDATE SET type = EXCLUDED.type, state = EXCLUDED.state'
        );
    }

    public function forUpdate(): string
    {
        return ' FOR UPDATE';
    }

    public function lock(Connection $connection, string $name, int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (true) {
            // A boolean, as pdo_pgsql returns it.
            if (\in_array(
                $connection->fetchOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0))', [$name]),
                [true, 't'],
                true,
            )) {
                return true;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(self::RETRY);
        }
    }

    public function unlock(Connection $connection, string $name): void
    {
        $connection->fetchOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$name]);
    }
}
