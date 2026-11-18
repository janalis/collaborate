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
        $configuration->setSchemaAssetsFilter(Schema::mapped(...));
        // Drupal's connection info is untyped: each parameter is text or a port, and an empty one is left out.
        $text = static fn(mixed $value): ?string => \is_scalar($value) && '' !== (string) $value
            ? (string) $value
            : null;
        $prefix = $text($info['prefix'] ?? null);
        $database = $text($info['database'] ?? null);
        $port = isset($info['port']) && is_numeric($info['port']) ? (int) $info['port'] : null;
        $parameters = match ($driver) {
            'sqlite' => [
                'driver' => 'pdo_sqlite',
                'path' => null !== $database && null !== $prefix ? $database . '-' . $prefix : $database,
            ],
            'pgsql' => [
                'driver' => 'pdo_pgsql',
                'host' => $text($info['host'] ?? null),
                'port' => $port,
                'dbname' => $database,
                'user' => $text($info['username'] ?? null),
                'password' => $text($info['password'] ?? null),
                'charset' => 'utf8',
            ],
            default => [
                'driver' => 'pdo_mysql',
                'host' => $text($info['host'] ?? null),
                'port' => $port,
                'unix_socket' => $text($info['unix_socket'] ?? null),
                'dbname' => $database,
                'user' => $text($info['username'] ?? null),
                'password' => $text($info['password'] ?? null),
                'charset' => 'utf8mb4',
            ],
        };
        /** @var array{driver: 'pdo_mysql'|'pdo_pgsql'|'pdo_sqlite', path?: string, host?: string, port?: int, unix_socket?: string, dbname?: string, user?: string, password?: string, charset?: string} $parameters */
        $parameters = array_filter($parameters, static fn(string|int|null $value): bool => null !== $value);

        return DriverManager::getConnection($parameters, $configuration);
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
