<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use Doctrine\DBAL\Connection;

/**
 * A DBAL wrapper (`wrapperClass`) that runs another connection's work once, just before the first
 * `fetchFirstColumn()` query or `executeStatement()` statement containing `$pattern`: a race between
 * two statements made deterministic.
 */
final class InterleavingConnection extends Connection
{
    public string $pattern = '';

    /** @var (\Closure(): void)|null */
    public ?\Closure $before = null;

    public function fetchFirstColumn(string $query, array $params = [], array $types = []): array
    {
        $this->interleave($query);

        return parent::fetchFirstColumn($query, $params, $types);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        $this->interleave($sql);

        return parent::executeStatement($sql, $params, $types);
    }

    private function interleave(string $sql): void
    {
        $before = $this->before;
        if (null !== $before && str_contains($sql, $this->pattern)) {
            $this->before = null;
            $before();
        }
    }
}
