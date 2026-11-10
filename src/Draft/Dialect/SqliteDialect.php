<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft\Dialect;

use Doctrine\DBAL\Connection;

/**
 * SQLite's: INSERT OR IGNORE, INSERT OR REPLACE, no row lock (a write locks the whole database), and named locks
 * as exclusive locks on files of the system's temporary directory: the processes sharing an SQLite database share
 * its machine. A process takes a lock it holds again without waiting.
 *
 * @internal
 */
final class SqliteDialect extends JournalDialect
{
    /** How long a wait for a lock sleeps between two tries, in microseconds. */
    private const int RETRY = 50_000;

    /** @var array<string, array{resource, int}> name => the locked file, and how many times it is held */
    private array $files = [];

    public function __construct(
        private readonly string $directory = '',
    ) {}

    public function createDraft(): string
    {
        return 'INSERT OR IGNORE INTO collaborate_draft (id, generation, revision, saved_revision, bytes, updated_at) VALUES (?, ?, 0, 0, 0, ?)';
    }

    /** @param non-negative-int $rows */
    public function writeFields(int $rows): string
    {
        return 'INSERT OR REPLACE INTO collaborate_draft_field (draft_id, name, type, state) VALUES '
        . self::values($rows);
    }

    public function forUpdate(): string
    {
        return '';
    }

    public function lock(Connection $connection, string $name, int $seconds): bool
    {
        if (isset($this->files[$name])) {
            ++$this->files[$name][1];

            return true;
        }
        $directory = '' === $this->directory ? sys_get_temp_dir() : $this->directory;
        $file = is_dir($directory) ? fopen($directory . '/' . md5($name) . '.lock', 'c') : false;
        if (false === $file) {
            return false;
        }
        $deadline = microtime(true) + $seconds;
        while (!flock($file, \LOCK_EX | \LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($file);

                return false;
            }
            usleep(self::RETRY);
        }
        $this->files[$name] = [$file, 1];

        return true;
    }

    public function unlock(Connection $connection, string $name): void
    {
        if (!isset($this->files[$name])) {
            return;
        }
        if (0 < --$this->files[$name][1]) {
            return;
        }
        [$file] = $this->files[$name];
        unset($this->files[$name]);
        flock($file, \LOCK_UN);
        fclose($file);
    }
}
