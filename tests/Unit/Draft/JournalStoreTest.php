<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Janalis\Collaborate\Draft\Dialect\JournalDialect;
use Janalis\Collaborate\Draft\Dialect\MySqlDialect;
use Janalis\Collaborate\Draft\JournalStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JournalStore::class)]
#[UsesClass(JournalDialect::class)]
#[UsesClass(MySqlDialect::class)]
final class JournalStoreTest extends TestCase
{
    public function testLargePayloadsAreOrderedWithoutDatabaseJsonSorting(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $first = ['id' => 'first', 'revision' => 1];
        $second = ['id' => 'second', 'revision' => 2];
        $connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with('SELECT revision, payload FROM collaborate_draft_event WHERE draft_id = ? AND revision > ?', [
                'record',
                0,
            ])
            ->willReturn([
                ['revision' => '4', 'payload' => '[5,6]'],
                ['revision' => '2', 'payload' => json_encode($second, \JSON_THROW_ON_ERROR)],
                ['revision' => '3', 'payload' => '[5]'],
                ['revision' => '1', 'payload' => json_encode($first, \JSON_THROW_ON_ERROR)],
            ]);

        self::assertSame([[$first, $second], [5, 6]], new JournalStore($connection)->events('record', 0));
    }

    public function testFieldStatesAreOrderedWithoutDatabaseJsonSorting(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with('SELECT name, state FROM collaborate_draft_field WHERE draft_id = ? AND type = ?', [
                'record',
                'source',
            ])
            ->willReturn([['name' => 'z', 'state' => '{}'], ['name' => 'a', 'state' => '{}']]);

        self::assertSame(['a', 'z'], array_keys(new JournalStore($connection)->fieldsOfType('record', 'source')));
    }
}
