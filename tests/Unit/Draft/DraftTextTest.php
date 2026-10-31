<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Janalis\Collaborate\Draft\DraftText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[CoversClass(DraftText::class)]
final class DraftTextTest extends TestCase
{
    public static function changes(): iterable
    {
        yield ['same', 'same'];
        yield ['  text  ', 'text'];
        yield ['prefix', 'pre'];
        yield ['text', ''];
        yield ['', '😀'];
        yield ['a😀b', 'a😁b'];
        yield ['😀', mb_chr(0x1_FA00)];
        yield ['😀x', '😁x'];
        yield ['x😀', 'z😁'];
        yield ['é', 'é'];
        yield ['one', 'two'];
    }

    #[DataProvider('changes')]
    public function testNormalizationReplaysWithUtf16Offsets(string $before, string $after): void
    {
        $text = new DraftText();
        $operations = $text->diff($before, $after, 2);
        self::assertSame($after, $text->apply($before, 0, $operations)['text']);
        foreach ($operations as $index => $operation) {
            self::assertSame(2 + $index, $operation['baseVersion']);
        }
        self::assertSame($after, $text->apply($after, 0, [['__className' => 'NoOperation']])['text']);
    }

    public static function invalidOperations(): iterable
    {
        yield [['__className' => 'AttributeOperation']];
        yield [[
            '__className' => 'InsertOperation',
            'position' => ['root' => 'main', 'path' => [0, -1]],
            'nodes' => [],
        ]];
        yield [['__className' => 'InsertOperation', 'position' => ['root' => 'main', 'path' => [0, 1]], 'nodes' => []]];
        yield [['__className' => 'InsertOperation', 'position' => ['root' => 'main', 'path' => [0, 8]], 'nodes' => []]];
        yield [[
            '__className' => 'InsertOperation',
            'position' => ['root' => 'other', 'path' => [0, 0]],
            'nodes' => [],
        ]];
        yield [[
            '__className' => 'InsertOperation',
            'position' => ['root' => 'main', 'path' => [0, 0]],
            'nodes' => 'bad',
        ]];
        yield [[
            '__className' => 'InsertOperation',
            'position' => ['root' => 'main', 'path' => [0, 0]],
            'nodes' => [['name' => 'paragraph']],
        ]];
        yield [[
            '__className' => 'MoveOperation',
            'sourcePosition' => ['root' => 'main', 'path' => [0, 0]],
            'targetPosition' => ['root' => '$graveyard', 'path' => [0]],
            'howMany' => 0,
        ]];
        yield [[
            '__className' => 'InsertOperation',
            'position' => ['root' => '$graveyard', 'path' => [1]],
            'nodes' => [['data' => 'X']],
        ]];
        yield [[
            '__className' => 'InsertOperation',
            'position' => ['root' => '$graveyard', 'path' => [0, 0]],
            'nodes' => [['data' => 'X']],
        ]];
    }

    /** @param array<string, mixed> $operation */
    #[DataProvider('invalidOperations')]
    public function testRejectsMalformedOrStructuredOperations(array $operation): void
    {
        $this->expectException(BadRequestHttpException::class);
        new DraftText()->apply('😀x', 0, [$operation]);
    }

    /** A local insert inside a range a peer removed is rebased by CKEditor into the graveyard, where it lands removed. */
    public function testAnInsertRebasedIntoTheGraveyardLeavesTheTextAndCountsAsRemoved(): void
    {
        $text = new DraftText();
        $removed = $text->apply('the quick brown fox', 0, [[
            '__className' => 'MoveOperation',
            'sourcePosition' => ['root' => 'main', 'path' => [0, 4]],
            'targetPosition' => ['root' => '$graveyard', 'path' => [0]],
            'howMany' => 11,
        ]]);
        self::assertSame(['text' => 'the  fox', 'graveyard' => 11], $removed);

        $rebased = $text->apply($removed['text'], $removed['graveyard'], [[
            '__className' => 'InsertOperation',
            'position' => ['root' => '$graveyard', 'path' => [5], 'stickiness' => 'toNone'],
            'nodes' => [['data' => 'X😀']],
            'shouldReceiveAttributes' => true,
        ]]);
        self::assertSame(['text' => 'the  fox', 'graveyard' => 14], $rebased);

        self::assertSame(['text' => 'the ', 'graveyard' => 18], $text->apply($rebased['text'], $rebased['graveyard'], [[
            '__className' => 'MoveOperation',
            'sourcePosition' => ['root' => 'main', 'path' => [0, 4]],
            'targetPosition' => ['root' => '$graveyard', 'path' => [0]],
            'howMany' => 4,
        ]]));
    }

    /** @return iterable<string, array{string, string, list<list<bool|int|string>>}> before, after, the operations (`move` offset count, `insert` offset text) */
    public static function exactDiffs(): iterable
    {
        yield 'replaced middle' => ['abcdef', 'abXYef', [['move', 2, 2], ['insert', 2, 'XY']]];
        yield 'appended' => ['abc', 'abcd', [['insert', 3, 'd']]];
        yield 'appended to a repeat' => ['aaa', 'aaaa', [['insert', 3, 'a']]];
        yield 'removed inside' => ['abcd', 'abd', [['move', 2, 1]]];
        yield 'trimmed both ends' => ['xxabcyy', 'abc', [['move', 5, 2], ['move', 0, 2]]];
        yield 'trimmed head' => ['xxabc', 'abc', [['move', 0, 2]]];
        yield 'trimmed tail' => ['abcyy', 'abc', [['move', 3, 2]]];
        yield 'trimmed a repeat' => ['abab', 'ab', [['move', 2, 2]]];
        yield 'trimmed to a wide character' => ["a\u{6200}b", "\u{6200}", [['move', 2, 1], ['move', 0, 1]]];
        // Its UTF-16 code unit is found in the old text at an odd byte offset, across two characters.
        yield 'no character boundary' => [
            'ab',
            "\u{6200}",
            [
                ['move',   0, 2],
                ['insert', 0, "\u{6200}"],
            ],
        ];
        yield 'cleared' => ['abc', '', [['move', 0, 3]]];
        yield 'same high surrogate' => ['😀', '😁', [['move', 0, 2], ['insert', 0, '😁']]];
        yield 'same low surrogate' => [
            '😀',
            "\u{1FA00}",
            [
                ['move',   0, 2],
                ['insert', 0, "\u{1FA00}"],
            ],
        ];
        yield 'surrogates inside' => [
            '1😀2',
            '1' . "\u{1FA00}" . '2',
            [
                ['move',   1, 2],
                ['insert', 1, "\u{1FA00}"],
            ],
        ];
        yield 'pair after the change' => ['x😀', 'y😀', [['move', 0, 1], ['insert', 0, 'y']]];
        yield 'pair inside a prefix' => ['ab😀cd', 'ab😁cd', [['move', 2, 2], ['insert', 2, '😁']]];
        yield 'grown into a repeat' => ['ab', 'abab', [['insert', 2, 'ab']]];
        // The common prefix and suffix overlap in the shorter text: the suffix keeps what the prefix left.
        yield 'shrunk across a repeat' => ['aaXaa', 'aaa', [['move', 2, 2]]];
        // U+0061 and U+0161 share their UTF-16LE low byte.
        yield 'same low byte' => ['a', 'š', [['move', 0, 1], ['insert', 0, 'š']]];
        yield 'highest high surrogate' => [
            "a\u{10FFFD}b",
            "a\u{10FFFC}b",
            [
                ['move',   1, 2],
                ['insert', 1, "\u{10FFFC}"],
            ],
        ];
    }

    /** @param list<list<bool|int|string>> $expected */
    #[DataProvider('exactDiffs')]
    public function testDiffsIntoTheSmallestOperationsOnCharacterBoundaries(
        string $before,
        string $after,
        array $expected,
    ): void {
        $operations = new DraftText()->diff($before, $after, 5);
        $compact = [];
        foreach ($operations as $index => $operation) {
            self::assertSame(5 + $index, $operation['baseVersion']);
            if ('MoveOperation' === $operation['__className']) {
                self::assertSame(
                    ['root' => '$graveyard', 'path' => [0], 'stickiness' => 'toNone'],
                    $operation['targetPosition'],
                );
                /** @var array{path: array{0: int, 1: int}} $source */
                $source = $operation['sourcePosition'];
                $compact[] = ['move', $source['path'][1], (int) $operation['howMany']];
                continue;
            }
            self::assertSame('InsertOperation', $operation['__className']);
            self::assertFalse($operation['shouldReceiveAttributes']);
            /** @var array{path: array{0: int, 1: int}} $position */
            $position = $operation['position'];
            /** @var list<array{data: string}> $nodes */
            $nodes = $operation['nodes'];
            $compact[] = ['insert', $position['path'][1], $nodes[0]['data']];
        }
        self::assertSame($expected, $compact);
        self::assertSame($after, new DraftText()->apply($before, 0, $operations)['text']);
    }

    public function testAppliesEveryOperationAfterANoOperationAndJoinsInsertedNodes(): void
    {
        self::assertSame(['text' => 'aXYb', 'graveyard' => 0], new DraftText()->apply('ab', 0, [
            ['__className' => 'NoOperation'],
            [
                '__className' => 'InsertOperation',
                'position' => ['root' => 'main', 'path' => [0, 1]],
                'nodes' => [['data' => 'X'], ['data' => 'Y', 'attributes' => []]],
            ],
        ]));
        // At the end of the text, and at the graveyard's end.
        self::assertSame('abZ', new DraftText()->apply('ab', 0, [self::insert(2)])['text']);
        self::assertSame(['text' => 'ab', 'graveyard' => 4], new DraftText()->apply('ab', 3, [[
            '__className' => 'InsertOperation',
            'position' => ['root' => '$graveyard', 'path' => [3]],
            'nodes' => [['data' => 'Z']],
        ]]));
    }

    /** @return iterable<string, array{string, list<array<string, mixed>>}> */
    public static function invalidPositions(): iterable
    {
        yield 'past the end' => ['ab', [self::insert(3)]];
        foreach ([
            'U+10000' => "\u{10000}",
            'U+1F600' => "\u{1F600}",
            'U+10FFFF' => "\u{10FFFF}",
        ] as $name => $character) {
            yield 'inside ' . $name => ['a' . $character, [self::insert(2)]];
        }
        yield 'inside a later pair' => ['abc😀', [self::insert(4)]];
        yield 'removal from inside a pair' => ['😀a', [self::move(1, 2)]];
        yield 'removal to inside a pair' => ['a😀', [self::move(0, 2)]];
        yield 'attributed node' => [
            'ab',
            [[
                '__className' => 'InsertOperation',
                'position' => ['root' => 'main', 'path' => [0, 0]],
                'nodes' => [['data' => 'X', 'attributes' => ['bold' => true]]],
            ]],
        ];
        yield 'node with another key' => [
            'ab',
            [[
                '__className' => 'InsertOperation',
                'position' => ['root' => 'main', 'path' => [0, 0]],
                'nodes' => [['data' => 'X', 'name' => 'paragraph']],
            ]],
        ];
        yield 'node without text' => [
            'ab',
            [[
                '__className' => 'InsertOperation',
                'position' => ['root' => 'main', 'path' => [0, 0]],
                'nodes' => [['data' => 7]],
            ]],
        ];
        yield 'node that is no object' => [
            'ab',
            [[
                '__className' => 'InsertOperation',
                'position' => ['root' => 'main', 'path' => [0, 0]],
                'nodes' => ['X'],
            ]],
        ];
    }

    /** @param list<array<string, mixed>> $operations */
    #[DataProvider('invalidPositions')]
    public function testRefusesPositionsOffTheTextOrInsideASurrogatePair(string $text, array $operations): void
    {
        $this->expectException(BadRequestHttpException::class);
        new DraftText()->apply($text, 0, $operations);
    }

    public function testInsertsNextToASurrogatePair(): void
    {
        foreach (["\u{10000}", "\u{10FFFF}", "\u{D7FF}", "\u{E000}"] as $character) {
            self::assertSame('a' . $character . 'Z', new DraftText()->apply('a' . $character, 0, [self::insert(intdiv(
                \strlen((string) mb_convert_encoding('a' . $character, 'UTF-16LE', 'UTF-8')),
                2,
            ))])['text']);
            self::assertSame('aZ' . $character, new DraftText()->apply('a' . $character, 0, [self::insert(1)])['text']);
        }
        self::assertSame('😀', new DraftText()->apply('😀ab', 0, [self::move(2, 2)])['text']);
        self::assertSame('abcZ😀', new DraftText()->apply('abc😀', 0, [self::insert(3)])['text']);
        self::assertSame('ab😀Z😀', new DraftText()->apply('ab😀😀', 0, [self::insert(4)])['text']);
    }

    /** @return array<string, mixed> */
    private static function insert(int $offset): array
    {
        return [
            '__className' => 'InsertOperation',
            'position' => ['root' => 'main', 'path' => [0, $offset]],
            'nodes' => [['data' => 'Z']],
        ];
    }

    /** @return array<string, mixed> */
    private static function move(int $offset, int $count): array
    {
        return [
            '__className' => 'MoveOperation',
            'sourcePosition' => ['root' => 'main', 'path' => [0, $offset]],
            'targetPosition' => ['root' => '$graveyard', 'path' => [0]],
            'howMany' => $count,
        ];
    }
}
