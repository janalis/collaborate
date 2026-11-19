<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Doctrine\DBAL\Connection;
use Janalis\Collaborate\Draft\Dialect\JournalDialect;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The named locks of the drafts {@see DraftJournal} serializes its writers by, a save included: re-entrant within a
 * process, released by {@see reset()} when a worker's request left one behind. A named lock is not transactional:
 * EasyAdmin's flush commits before its postFlush side effects run.
 *
 * @internal the journal's own: hosts lock through {@see DraftJournal::lock()}
 */
final class DraftLocks
{
    /** @var array<string, int> how many times this process holds each draft's lock */
    private array $held = [];

    /** The hashed database name of {@see name()}: DBAL reads it with a query on every call. */
    private ?string $prefix = null;

    /**
     * @param \Closure(): JournalDialect $dialect the journal's: SQLite's keeps the locks of its connection
     * @param int                        $seconds how long a lock is waited for unless a caller says otherwise
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly \Closure $dialect,
        private readonly int $seconds,
    ) {}

    /** @throws ServiceUnavailableHttpException past the wait: another process holds it */
    public function lock(string $id, ?int $seconds = null): void
    {
        if (!($this->dialect)()->lock($this->connection, $this->name($id), $seconds ?? $this->seconds)) {
            throw new ServiceUnavailableHttpException(1, 'Collaborate draft is busy.');
        }
        $this->held[$id] = ($this->held[$id] ?? 0) + 1;
    }

    public function unlock(string $id): void
    {
        if (!isset($this->held[$id])) {
            return;
        }
        ($this->dialect)()->unlock($this->connection, $this->name($id));
        if (0 === --$this->held[$id]) {
            unset($this->held[$id]);
        }
    }

    /** Whether this process holds the draft's lock: a save holds it across validation and persistence. */
    public function holds(string $id): bool
    {
        return isset($this->held[$id]);
    }

    /**
     * Waits for a save of the draft under way, at most as long as a save may hold its lock; past the wait, or when
     * this process is the one saving, it waits for nothing.
     */
    public function waitForSave(string $id): void
    {
        if ($this->holds($id)) {
            return;
        }
        try {
            $this->lock($id);
            $this->unlock($id);
        } catch (ServiceUnavailableHttpException) {
            // Past the wait: the draft as it is.
        }
    }

    /** FrankenPHP workers keep their connection, and with it any lock a request left behind. */
    public function reset(): void
    {
        foreach ($this->held as $id => $count) {
            foreach (range(1, $count) as $_) {
                ($this->dialect)()->unlock($this->connection, $this->name($id));
            }
        }
        $this->held = [];
        // A worker's next request may run on another database (a tenant's): its locks are named after that one.
        $this->prefix = null;
    }

    /** Named locks are global to the database server, and a record's draft id is the same in every database;
     * the hashed database name keeps environments and test instances apart under the 64-character cap.
     */
    private function name(string $id): string
    {
        $this->prefix ??=
            'collaborate:' . substr(hash('xxh128', (string) $this->connection->getDatabase()), 0, 16) . ':';

        return $this->prefix . md5($id);
    }
}
