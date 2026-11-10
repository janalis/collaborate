<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft\Dialect;

use Doctrine\DBAL\Connection;

/**
 * SQLite's: INSERT OR IGNORE, INSERT OR REPLACE, no row lock (a write locks the whole database), and named locks
 * as exclusive locks on files: the processes sharing an SQLite database share its machine. The files are kept in
 * the database's own directory, which its processes write to already; an in-memory database's, in a directory of
 * the system's temporary one that only the process's user may enter. A lock file that is a link is refused. A
 * process takes a lock it holds again without waiting.
 *
 * @internal
 */
final class SqliteDialect extends JournalDialect
{
    /** How long a wait for a lock sleeps between two tries, in microseconds. */
    private const int RETRY = 50_000;

    /** @var array<string, array{resource, int}> name => the locked file, and how many times it is held */
    private array $files = [];

    /** The private directory of an in-memory database's locks, below the system's temporary directory. */
    public const string PRIVATE = 'collaborate-locks';

    /** @param string $directory where the lock files are kept: the database's directory; '' for a private one */
    public function __construct(
        private readonly string $directory = '',
    ) {}

    /** The dialect of a connection to an SQLite database: its locks next to its file. */
    public static function of(Connection $connection): self
    {
        $path = $connection->getParams()['path'] ?? null;

        return new self(\is_string($path) && '' !== $path && ':memory:' !== $path ? \dirname($path) : '');
    }

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
        $directory = '' === $this->directory ? self::private() : $this->directory;
        $path = (string) $directory . '/.collaborate-' . md5($name) . '.lock';
        $file = null !== $directory && is_dir($directory) && !is_link($path) ? fopen($path, 'c') : false;
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

    /** The user's own directory of lock files below the system's temporary one, or null if another may enter it. */
    private static function private(): ?string
    {
        $user = getmyuid();
        $directory = sys_get_temp_dir() . '/' . self::PRIVATE . '-' . (string) $user;
        if (!is_dir($directory) && !is_link($directory)) {
            mkdir($directory, 0o700);
        }
        clearstatcache(true, $directory);

        return !is_link($directory)
        && is_dir($directory)
        && fileowner($directory) === $user
        && 0 === (fileperms($directory) & 0o077)
            ? $directory
            : null;
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
