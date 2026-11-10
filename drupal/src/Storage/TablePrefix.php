<?php

declare(strict_types=1);

namespace Drupal\collaborate\Storage;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Drupal's table prefix on the bundle's tables: the journal's SQL, and the entity manager's, name them as the
 * bundle does (`collaborate_draft`…); a site, or a test, whose tables are prefixed has them under its prefix.
 */
final readonly class TablePrefix implements Middleware
{
    /** The bundle's tables, the longest first; an index named after one (`collaborate_draft_updated_at`) is left. */
    public const array TABLES = [
        'collaborate_notification_preference',
        'collaborate_push_subscription',
        'collaborate_notification',
        'collaborate_draft_event',
        'collaborate_draft_field',
        'collaborate_grant_cache',
        'collaborate_draft',
    ];

    public function __construct(
        private string $prefix,
    ) {}

    public function wrap(Driver $driver): Driver
    {
        $prefix = $this->prefix;

        return new class($driver, $prefix) extends AbstractDriverMiddleware {
            public function __construct(
                Driver $driver,
                private readonly string $prefix,
            ) {
                parent::__construct($driver);
            }

            public function connect(array $params): Connection
            {
                $prefix = $this->prefix;

                return new class(parent::connect($params), $prefix) extends AbstractConnectionMiddleware {
                    public function __construct(
                        Connection $connection,
                        private readonly string $prefix,
                    ) {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        return parent::prepare(TablePrefix::apply($this->prefix, $sql));
                    }

                    public function query(string $sql): Result
                    {
                        return parent::query(TablePrefix::apply($this->prefix, $sql));
                    }

                    public function exec(string $sql): int|string
                    {
                        return parent::exec(TablePrefix::apply($this->prefix, $sql));
                    }
                };
            }
        };
    }

    /** The statement with the bundle's table names under the prefix. */
    public static function apply(string $prefix, string $sql): string
    {
        if ('' === $prefix) {
            return $sql;
        }

        return (string) preg_replace('/(?<![\w$])(' . implode('|', self::TABLES) . ')(?![\w$])/', $prefix . '$1', $sql);
    }
}
