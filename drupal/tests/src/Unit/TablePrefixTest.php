<?php

declare(strict_types=1);

namespace Drupal\Tests\collaborate\Unit;

use Drupal\collaborate\Storage\TablePrefix;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TablePrefix::class)]
final class TablePrefixTest extends UnitTestCase
{
    public function testPrefixesTheBundlesTablesAlone(): void
    {
        self::assertSame('SELECT d.id FROM test1collaborate_draft d JOIN test1collaborate_draft_field f ON f.draft_id = d.id '
        . 'WHERE f.name = ? AND d.id NOT IN (SELECT draft_id FROM test1collaborate_draft_event)', TablePrefix::apply(
            'test1',
            'SELECT d.id FROM collaborate_draft d JOIN collaborate_draft_field f ON f.draft_id = d.id '
            . 'WHERE f.name = ? AND d.id NOT IN (SELECT draft_id FROM collaborate_draft_event)',
        ));
        // An index named after a table, a column, a lock's name: none of them a table.
        self::assertSame('CREATE INDEX collaborate_draft_updated_at ON t_collaborate_draft (updated_at)', TablePrefix::apply(
            't_',
            'CREATE INDEX collaborate_draft_updated_at ON collaborate_draft (updated_at)',
        ));
        self::assertSame('SELECT GET_LOCK(?, ?)', TablePrefix::apply('t_', 'SELECT GET_LOCK(?, ?)'));
        self::assertSame('SELECT 1 FROM collaborate_draft', TablePrefix::apply('', 'SELECT 1 FROM collaborate_draft'));
    }
}
