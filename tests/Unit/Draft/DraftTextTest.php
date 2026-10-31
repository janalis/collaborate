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
}
