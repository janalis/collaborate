<?php

declare(strict_types=1);

namespace Drupal\Tests\collaborate\Kernel;

use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Drupal\collaborate\Storage\Connections;
use Drupal\collaborate\Storage\Schema;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Which tables of the site's database the bundle's schema tools may see: its own, prefixed or not (the test's own
 * prefix, none on SQLite), named or as DBAL assets.
 */
#[CoversClass(Schema::class)]
#[RunTestsInSeparateProcesses]
final class SchemaTest extends KernelTestBase
{
    protected static $modules = ['system', 'user', 'collaborate'];

    public function testTheBundlesTablesAloneAreMapped(): void
    {
        $prefix = Connections::prefix();
        foreach ([
            'collaborate_draft',
            $prefix . 'collaborate_draft_event',
            '`' . $prefix . 'collaborate_draft_field`',
        ] as $name) {
            self::assertTrue(Schema::mapped($name), $name);
        }
        foreach (['node', $prefix . 'node', 7] as $name) {
            self::assertFalse(Schema::mapped($name), (string) $name);
        }
    }

    public function testAnAssetIsMappedByItsName(): void
    {
        $prefix = Connections::prefix();
        self::assertTrue(Schema::mapped(new Table($prefix . 'collaborate_draft')));
        self::assertTrue(Schema::mapped(new Table(strtoupper($prefix . 'collaborate_draft'))));
        self::assertFalse(Schema::mapped(new Table($prefix . 'node')));
        self::assertFalse(Schema::mapped(new Table('other.node')));
        self::assertFalse(Schema::mapped(new Sequence($prefix . 'node_seq')));
    }
}
