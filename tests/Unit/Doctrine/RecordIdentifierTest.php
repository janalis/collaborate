<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\ClassMetadataFactory;
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
    /** @param array<string, mixed> $ids */
    private function identifier(array $ids): RecordIdentifier
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn($ids);
        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturnCallback(static fn(string $class): bool => Tag::class !== $class);
        $manager = $this->createStub(ObjectManager::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $manager->method('getMetadataFactory')->willReturn($factory);
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

    public function testAValueObjectIdentifierIsKeptAndAnEntityOneIsNone(): void
    {
        $uuid = new RecordKey('u-1');
        self::assertSame($uuid, $this->identifier(['id' => $uuid])->value(new Tag()));
        // A key derived from an association to another mapped entity.
        self::assertNull($this->identifier(['owner' => new Tag()])->value(new Tag()));
    }

    public function testCompositeUnassignedOrUnmappedIdentifiersAreNone(): void
    {
        self::assertNull($this->identifier(['account' => 1, 'team' => 2])->value(new Tag()));
        self::assertNull($this->identifier([])->value(new Tag()));
        self::assertNull($this->identifier(['id' => 42])->value(new Article()));
        self::assertNull(new RecordIdentifier()->value(new Tag()));
    }
}
