<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Tests\Fixtures\Draft\Article;
use Janalis\Collaborate\Tests\Fixtures\Draft\Tag;
use Janalis\Collaborate\Tests\Fixtures\RecordKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordIdentifier::class)]
final class RecordIdentifierTest extends TestCase
{
    /**
     * @param array<string, mixed>                $ids    the identifier of every Tag but $owner
     * @param array<string, mixed>                $owned  the identifier of $owner
     */
    private function identifier(array $ids, ?Tag $owner = null, array $owned = []): RecordIdentifier
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturnCallback(static fn(object $entity): array => $entity
            === $owner
                ? $owned
                : $ids);
        $manager = $this->createStub(ObjectManager::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry
            ->method('getManagerForClass')
            ->willReturnCallback(static fn(string $class): ?ObjectManager => Tag::class === $class ? $manager : null);

        return new RecordIdentifier($registry);
    }

    public function testReadsTheSingleColumnIdentifierOfAMappedEntity(): void
    {
        self::assertSame(42, $this->identifier(['id' => 42])->value(new Tag()));
    }

    public function testAValueObjectIdentifierIsKeptAndADerivedOneIsItsEntitysOwn(): void
    {
        $uuid = new RecordKey('u-1');
        self::assertSame($uuid, $this->identifier(['id' => $uuid])->value(new Tag()));
        // A key derived from an association: the associated entity's identifier, never the entity.
        $owner = new Tag();
        self::assertSame(5, $this->identifier(['owner' => $owner], $owner, ['id' => 5])->value(new Tag()));
    }

    public function testCompositeUnassignedOrUnmappedIdentifiersAreNone(): void
    {
        self::assertNull($this->identifier(['account' => 1, 'team' => 2])->value(new Tag()));
        self::assertNull($this->identifier([])->value(new Tag()));
        self::assertNull($this->identifier(['id' => 42])->value(new Article()));
        self::assertNull(new RecordIdentifier()->value(new Tag()));
    }
}
