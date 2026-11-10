<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

/**
 * The bundle's tables on PostgreSQL and SQLite. Their mapping is written for MariaDB and MySQL, whose schema it
 * keeps unchanged: binary collations (`utf8mb4_bin`), which the other platforms' default text comparison already
 * is, and microsecond datetimes (`DATETIME(6)`), PostgreSQL's `TIMESTAMP(6)` and SQLite's text.
 */
final class PortableSchema
{
    /** The column definition of a datetime with microseconds, on MariaDB and MySQL. */
    private const string MICROSECONDS = 'DATETIME(6) NOT NULL';

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if (!str_starts_with($metadata->getName(), 'Janalis\Collaborate\\')) {
            return;
        }
        $platform = $event->getObjectManager()->getConnection()->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            return;
        }
        foreach ($metadata->fieldMappings as $mapping) {
            if (isset($mapping->options['collation'])) {
                unset($mapping->options['collation']);
            }
            if (self::MICROSECONDS === $mapping->columnDefinition) {
                $mapping->columnDefinition = $platform instanceof PostgreSQLPlatform
                    ? 'TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL'
                    : null;
            }
        }
    }
}
