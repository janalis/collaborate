<?php

declare(strict_types=1);

namespace Drupal\collaborate\Storage;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Drupal\Core\Database\Database;
use Janalis\Collaborate\Doctrine\PortableSchema;
use Janalis\Collaborate\Entity\Draft;
use Janalis\Collaborate\Notification\Entity\Notification;

/**
 * The bundle's storage on Drupal's default database (MariaDB or MySQL, PostgreSQL, SQLite): a DBAL connection for
 * the draft journal, and an entity manager on it for the notifications, mapped by the bundle's own entities. The
 * tables are the module's ({@see Schema}), named as the bundle names them, behind Drupal's table prefix. SQLite
 * keeps a prefix's tables in a database file of their own (`<database>-<prefix>`), which the connection opens.
 */
final class Connections
{
    /** Drupal's drivers the journal speaks to. */
    public const array DRIVERS = ['mysql', 'pgsql', 'sqlite'];

    public static function dbal(): Connection
    {
        $info = Database::getConnectionInfo()['default'] ?? [];
        $driver = self::driver();
        if (!\in_array($driver, self::DRIVERS, true)) {
            throw new \LogicException(\sprintf(
                'Collaborate needs Drupal\'s database on MariaDB, MySQL, PostgreSQL or SQLite, not "%s".',
                $driver,
            ));
        }
        $configuration = new Configuration();
        // Drupal's prefix, on the tables the bundle names; the comparison of a schema sees those alone.
        $configuration->setMiddlewares([new TablePrefix(self::prefix())]);
        $configuration->setSchemaAssetsFilter(static fn(mixed $asset): bool => Schema::mapped($asset));
        $prefix = $info['prefix'] ?? '';
        $database = $info['database'] ?? null;
        $parameters = match ($driver) {
            'sqlite' => [
                'driver' => 'pdo_sqlite',
                'path' => \is_string($database) && \is_string($prefix) && '' !== $prefix ? $database . '-' . $prefix : $database,
            ],
            'pgsql' => [
                'driver' => 'pdo_pgsql',
                'host' => $info['host'] ?? null,
                'port' => isset($info['port']) && '' !== $info['port'] ? (int) $info['port'] : null,
                'dbname' => $database,
                'user' => $info['username'] ?? null,
                'password' => $info['password'] ?? null,
                'charset' => 'utf8',
            ],
            default => [
                'driver' => 'pdo_mysql',
                'host' => $info['host'] ?? null,
                'port' => isset($info['port']) && '' !== $info['port'] ? (int) $info['port'] : null,
                'unix_socket' => $info['unix_socket'] ?? null,
                'dbname' => $database,
                'user' => $info['username'] ?? null,
                'password' => $info['password'] ?? null,
                'charset' => 'utf8mb4',
            ],
        };

        return DriverManager::getConnection(
            array_filter($parameters, static fn(mixed $value): bool => null !== $value && '' !== $value),
            $configuration,
        );
    }

    /** The type of Drupal's default database: `mysql` for MariaDB and MySQL, `pgsql`, `sqlite`. */
    public static function driver(): string
    {
        return Database::getConnection()->databaseType();
    }

    /**
     * The prefix of Drupal's default connection, its tests' included, on the tables' names: none on SQLite, which
     * keeps a prefix's tables in a file of their own.
     */
    public static function prefix(): string
    {
        $prefix = Database::getConnectionInfo()['default']['prefix'] ?? '';

        return \is_string($prefix) && 'sqlite' !== self::driver() ? $prefix : '';
    }

    /**
     * An entity manager on the connection, mapping the notifications' entities, and the drafts' too when asked:
     * the journal reads and writes those with SQL, their mapping only describes their tables.
     */
    public static function entityManager(Connection $connection, bool $drafts = false): EntityManagerInterface
    {
        $directories = [\dirname((string) new \ReflectionClass(Notification::class)->getFileName())];
        if ($drafts) {
            $directories[] = \dirname((string) new \ReflectionClass(Draft::class)->getFileName());
        }
        $config = ORMSetup::createAttributeMetadataConfig($directories);
        $config->enableNativeLazyObjects(true);

        // The bundle's mapping is MariaDB's and MySQL's: the others take its portable schema.
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new PortableSchema());

        return new EntityManager($connection, $config, $events);
    }
}
