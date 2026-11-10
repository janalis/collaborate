<?php

declare(strict_types=1);

namespace Drupal\collaborate\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;

/**
 * The module's tables, as the bundle's mapping describes them (its JSON columns, its binary collations, its
 * microsecond times): the drafts', the notifications', and the cache of verified grants.
 */
final readonly class Schema
{
    public const string GRANT_CACHE = 'collaborate_grant_cache';

    public function __construct(
        private Connection $connection,
    ) {}

    public static function default(): self
    {
        return new self(Connections::dbal());
    }

    /**
     * Creates the bundle's tables that are missing, each as the bundle maps it, under Drupal's prefix (the
     * connection's middleware adds it to the statements).
     */
    public function install(): void
    {
        $manager = Connections::entityManager($this->connection, drafts: true);
        $tool = new SchemaTool($manager);
        $schema = $this->connection->createSchemaManager();
        foreach ($manager->getMetadataFactory()->getAllMetadata() as $class) {
            if (!$schema->tablesExist([$this->prefixed($class->getTableName())])) {
                foreach ($tool->getCreateSchemaSql([$class]) as $sql) {
                    $this->connection->executeStatement($sql);
                }
            }
        }
        if (!$schema->tablesExist([$this->prefixed(self::GRANT_CACHE)])) {
            $this->grantCache()->createTable();
        }
    }

    /** Whether an asset of the database is one of the bundle's tables, whatever the prefix: Drupal's are not. */
    public static function mapped(mixed $asset): bool
    {
        $name = $asset instanceof AbstractAsset ? $asset->getName() : $asset;
        $name = \is_string($name) ? strtolower(trim($name, '`"')) : '';

        return (
            [] !== array_filter(
                TablePrefix::TABLES,
                static fn(string $table): bool => (
                    $name === $table
                    || str_ends_with($name, $table) && str_starts_with($name, Connections::prefix())
                ),
            )
        );
    }

    public function uninstall(): void
    {
        // Through the middleware, which prefixes each name.
        foreach (TablePrefix::TABLES as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
    }

    private function prefixed(string $table): string
    {
        return Connections::prefix() . $table;
    }

    private function grantCache(): DoctrineDbalAdapter
    {
        return new DoctrineDbalAdapter($this->connection, 'collaborate', 0, ['db_table' => self::GRANT_CACHE]);
    }
}
