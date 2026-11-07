<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Functional\Entity;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Janalis\Collaborate\Entity\Draft;
use Janalis\Collaborate\Entity\DraftEvent;
use Janalis\Collaborate\Entity\DraftField;
use Janalis\Collaborate\Tests\Functional\Draft\DraftJournalTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The entity mapping describes the tables of docs/schema.sql, which applications copy into a migration. */
#[CoversClass(Draft::class)]
#[CoversClass(DraftEvent::class)]
#[CoversClass(DraftField::class)]
final class DraftSchemaTest extends TestCase
{
    public function testTheMappingMatchesTheDocumentedSchema(): void
    {
        $connection = DraftJournalTest::connection();
        foreach (DraftJournalTest::schema() as $sql) {
            $connection->executeStatement(str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $sql));
        }
        $configuration = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3) . '/src/Entity'], true);
        $configuration->enableNativeLazyObjects(true);
        $em = new EntityManager($connection, $configuration);
        $classes = array_map($em->getClassMetadata(...), [Draft::class, DraftEvent::class, DraftField::class]);

        $changes = array_values(array_filter(
            new SchemaTool($em)->getUpdateSchemaSql($classes),
            static fn(string $sql): bool => str_contains($sql, 'collaborate_draft'),
        ));

        self::assertSame([], $changes);
        // A schema created from the mapping is the documented one, in the application's default charset.
        $documented = array_values(array_filter(DraftJournalTest::schema(), static fn(string $sql): bool => str_contains(
            $sql,
            'CREATE TABLE collaborate_draft',
        )));
        sort($documented);
        $created = new SchemaTool($em)->getCreateSchemaSql($classes);
        sort($created);
        self::assertSame($created, array_map(
            static fn(string $sql): string => (string) strstr($sql, ') DEFAULT CHARACTER SET', true) . ')',
            $documented,
        ));
        self::assertSame(
            ['collaborate_draft', 'collaborate_draft_event', 'collaborate_draft_field'],
            array_map(static fn($metadata): string => $metadata->getTableName(), $classes),
        );
        $connection->close();
    }
}
