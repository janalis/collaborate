<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Tests\Fixtures\Draft\Article;
use Janalis\Collaborate\Tests\Fixtures\Draft\Tag;
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

    public function testCompositeUnassignedOrUnmappedIdentifiersAreNone(): void
    {
        self::assertNull($this->identifier(['account' => 1, 'team' => 2])->value(new Tag()));
        self::assertNull($this->identifier([])->value(new Tag()));
        self::assertNull($this->identifier(['id' => 42])->value(new Article()));
        self::assertNull(new RecordIdentifier()->value(new Tag()));
    }
}
