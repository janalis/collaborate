<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Janalis\Collaborate\Draft\FieldNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldNames::class)]
final class FieldNamesTest extends TestCase
{
    public function testACollectionIsNamedByItsKeysItsMembersAndItsPrototypes(): void
    {
        self::assertSame('items[__collection__]', FieldNames::collection('items'));
        self::assertSame('items', FieldNames::collectionPath('items[__collection__]'));
        self::assertNull(FieldNames::collectionPath('items[__collection__][x]'));
        self::assertSame('items[@3]', FieldNames::member('items', '3'));
        self::assertSame('items[__member__]', FieldNames::prototype('items'));
        self::assertSame('items[__name__]', FieldNames::entryPrototype('items'));
        self::assertSame('__name__', FieldNames::PLACEHOLDER);
        self::assertSame('__member__', FieldNames::MEMBER_PLACEHOLDER);
    }

    public function testAMembershipNamesItsCollectionKeyAndEntry(): void
    {
        self::assertSame(['a[items]', 'n1'], FieldNames::parseMember('a[items][@n1]'));
        self::assertSame('a[items][n1]', FieldNames::entryOf('a[items][@n1]'));
        self::assertNull(FieldNames::parseMember('a[items][n1]'));
        self::assertNull(FieldNames::entryOf('member'));
    }

    public function testASourceModeIsSharedStateBesideItsField(): void
    {
        self::assertSame('body[__mode__]', FieldNames::mode('body'));
        self::assertTrue(FieldNames::isMode('body[__mode__]'));
        self::assertFalse(FieldNames::isMode('body[__mode__][x]'));
    }

    public function testAFieldSitsInTheEntriesItsNameClosesAndTheirMembersRemoveIt(): void
    {
        self::assertSame(['a[b]', 'a[b][c]'], FieldNames::entryPrefixes('a[b][c]'));
        // A bracket closed inside a key is no entry of its own.
        self::assertSame(['a[b]x]'], FieldNames::entryPrefixes('a[b]x]'));
        self::assertSame([], FieldNames::entryPrefixes('plain'));
        self::assertSame(['a[@b]', 'a[b][@c]'], FieldNames::membersAround('a[b][c]'));
        self::assertSame(['x]'], FieldNames::membersAround('x]'));
    }
}
