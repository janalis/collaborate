<?php

declare(strict_types=1);

namespace Drupal\collaborate\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Drupal\Core\Database\Database;
use Janalis\Collaborate\Entity\Draft;
use Janalis\Collaborate\Notification\Entity\Notification;

/**
 * The bundle's storage on Drupal's default database: a DBAL connection for the draft journal (its SQL is MariaDB's
 * and MySQL's), and an entity manager on it for the notifications, mapped by the bundle's own entities. The tables
 * are the module's ({@see \collaborate_schema()}), named as the bundle names them, behind Drupal's table prefix.
 */
final class Connections
{
    /** Drupal's drivers the journal speaks to. */
    public const array DRIVERS = ['mysql'];

    public static function dbal(): Connection
    {
        $info = Database::getConnectionInfo()['default'] ?? [];
        $driver = self::driver();
        if (!\in_array($driver, self::DRIVERS, true)) {
            throw new \LogicException(\sprintf(
                'Collaborate needs Drupal\'s database on MariaDB or MySQL, not "%s".',
                $driver,
            ));
        }
        $configuration = new Configuration();
        // Drupal's prefix, on the tables the bundle names; the comparison of a schema sees those alone.
        $configuration->setMiddlewares([new TablePrefix(self::prefix())]);
        $configuration->setSchemaAssetsFilter(static fn(mixed $asset): bool => Schema::mapped($asset));

        return DriverManager::getConnection(
            array_filter(
                [
                    'driver' => 'pdo_mysql',
                    'host' => $info['host'] ?? null,
                    'port' => isset($info['port']) && '' !== $info['port'] ? (int) $info['port'] : null,
                    'unix_socket' => $info['unix_socket'] ?? null,
                    'dbname' => $info['database'] ?? null,
                    'user' => $info['username'] ?? null,
                    'password' => $info['password'] ?? null,
                    'charset' => 'utf8mb4',
                ],
                static fn(mixed $value): bool => null !== $value && '' !== $value,
            ),
            $configuration,
        );
    }

    /** The type of Drupal's default database: `mysql` for MariaDB and MySQL. */
    public static function driver(): string
    {
        return Database::getConnection()->databaseType();
    }

    /** The prefix of Drupal's default connection, its tests' included. */
    public static function prefix(): string
    {
        $prefix = Database::getConnectionInfo()['default']['prefix'] ?? '';

        return \is_string($prefix) ? $prefix : '';
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

        return new EntityManager($connection, $config);
    }
}
