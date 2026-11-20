<?php

declare(strict_types=1);

namespace Drupal\Tests\collaborate\Unit;

use Drupal\collaborate\Bridge\EntityForms;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/** The pure parts of an entity form's draft: how a submitted value reads beside the record's, and what a field keeps. */
#[CoversClass(EntityForms::class)]
final class EntityFormsTest extends UnitTestCase
{
    /** @return iterable<string, array{mixed, mixed, mixed}> */
    public static function submittedValues(): iterable
    {
        yield 'a checkbox is a boolean' => ['1', false, true];
        yield 'an unchecked one too' => [0, true, false];
        yield 'a list is a list, its keys dropped' => [['a' => 'x', 'b' => 'y'], [], ['x', 'y']];
        yield 'what is no list reads as none' => ['x', [], []];
        yield 'a text is a string' => [12, '', '12'];
        yield 'a text of no scalar is empty' => [['x'], '', ''];
        yield 'a number is an integer' => ['42', 0, 42];
        yield 'a number not numeric stays as given' => ['n/a', 0, 'n/a'];
        yield 'a select of none is null' => ['', null, null];
        yield 'anything else stays as given' => ['x', null, 'x'];
    }

    #[DataProvider('submittedValues')]
    public function testASubmittedValueIsTypedAsTheRecords(mixed $submitted, mixed $record, mixed $typed): void
    {
        self::assertSame($typed, self::call('typed', $submitted, $record));
    }

    public function testADraftKeepsScalarsAndTheScalarsOfAList(): void
    {
        self::assertSame('x', self::call('value', 'x'));
        self::assertNull(self::call('value', null));
        self::assertSame([1, 'a'], self::call('value', ['k' => 1, 'o' => new \stdClass(), 'a']));
        self::assertNull(self::call('value', new \stdClass()));
    }

    public function testAnItemsWeightIsNoFieldOfItsDraft(): void
    {
        $fields = [
            'field_notes[3][value]' => ['type' => 'text', 'initial' => ''],
            'field_notes[3][_weight]' => ['type' => 'value', 'initial' => 0],
            'field_notes[4][_weight]' => ['type' => 'value', 'initial' => 1],
        ];

        // @mago-expect analysis:mixed-assignment a reflected call: the next line narrows it
        $kept = self::call('unweighted', $fields, 'field_notes', '3');
        self::assertIsArray($kept);
        self::assertSame(['field_notes[3][value]', 'field_notes[4][_weight]'], array_keys($kept));
    }

    private static function call(string $method, mixed ...$arguments): mixed
    {
        return new \ReflectionMethod(EntityForms::class, $method)->invoke(null, ...$arguments);
    }
}
