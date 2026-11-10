<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Janalis\Collaborate\Doctrine\PortableSchema;
use Janalis\Collaborate\Entity\DraftField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The bundle's MariaDB and MySQL mapping, on the other platforms. */
#[CoversClass(PortableSchema::class)]
final class PortableSchemaTest extends TestCase
{
    public function testMariaDbAndMySqlKeepTheMapping(): void
    {
        $metadata = $this->load(new MariaDBPlatform(), DraftField::class);

        self::assertSame(['collation' => 'utf8mb4_bin'], $metadata->fieldMappings['name']->options);
        self::assertSame('DATETIME(6) NOT NULL', $metadata->fieldMappings['updatedAt']->columnDefinition);
    }

    public function testPostgreSqlTakesMicrosecondTimestampsAndItsOwnCollation(): void
    {
        $metadata = $this->load(new PostgreSQLPlatform(), DraftField::class);

        self::assertSame([], $metadata->fieldMappings['name']->options);
        self::assertSame(
            'TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL',
            $metadata->fieldMappings['updatedAt']->columnDefinition,
        );
        self::assertSame(['fixed' => true], $metadata->fieldMappings['code']->options);
    }

    public function testSqliteTakesItsOwnDatetimes(): void
    {
        self::assertNull($this->load(
            new SQLitePlatform(),
            DraftField::class,
        )->fieldMappings['updatedAt']->columnDefinition);
    }

    public function testAnotherMappingIsLeftAlone(): void
    {
        $metadata = $this->load(new PostgreSQLPlatform(), \stdClass::class);

        self::assertSame(['collation' => 'utf8mb4_bin'], $metadata->fieldMappings['name']->options);
    }

    /** @param class-string $class */
    private function load(AbstractPlatform $platform, string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->fieldMappings = [
            'name' => self::field('name', 'string', options: ['collation' => 'utf8mb4_bin']),
            'updatedAt' => self::field('updatedAt', 'datetime_immutable', definition: 'DATETIME(6) NOT NULL'),
            'code' => self::field('code', 'string', options: ['fixed' => true]),
            'plain' => self::field('plain', 'string'),
        ];
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        new PortableSchema()->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $manager));

        return $metadata;
    }

    /** @param array<string, mixed>|null $options */
    private static function field(
        string $name,
        string $type,
        ?array $options = null,
        ?string $definition = null,
    ): FieldMapping {
        $mapping = new FieldMapping($type, $name, $name);
        $mapping->options = $options;
        $mapping->columnDefinition = $definition;

        return $mapping;
    }
}
