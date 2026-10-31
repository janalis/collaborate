<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;

/** A DBAL wrapper (`wrapperClass`) that records the parameters of every statement it executes, and the SQL of every query. */
final class StatementRecordingConnection extends Connection
{
    /** @var list<array<array-key, mixed>> */
    public array $parameters = [];

    /** @var list<string> */
    public array $queries = [];

    public function executeQuery(
        string $sql,
        array $params = [],
        array $types = [],
        ?QueryCacheProfile $qcp = null,
    ): Result {
        $this->queries[] = $sql;

        return parent::executeQuery($sql, $params, $types, $qcp);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        $this->parameters[] = $params;

        return parent::executeStatement($sql, $params, $types);
    }
}
