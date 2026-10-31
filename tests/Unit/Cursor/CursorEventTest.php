<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Cursor;

use Janalis\Collaborate\Cursor\CursorEvent;
use Janalis\Collaborate\Cursor\CursorPosition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[CoversClass(CursorEvent::class)]
#[CoversClass(CursorPosition::class)]
final class CursorEventTest extends TestCase
{
    private const array ANCHOR = ['selector' => 'input[name="title"]', 'region' => 'content', 'x' => 0.5, 'y' => 0.5];

    public function testKeepsOnlyTheFieldsTheSenderDecides(): void
    {
        self::assertSame(
            [
                'clientId' => '12345678-1234-1234',
                'sequence' => 1,
                'kind' => 'move',
                'style' => 'mac',
                'shape' => 'default',
                'anchor' => self::ANCHOR,
                'caret' => null,
                'field' => null,
            ],
            $this->event(['userId' => 99, 'label' => 'fake', 'anchor' => self::ANCHOR + ['extra' => 'x']]),
        );
        foreach (CursorEvent::KINDS as $kind) {
            if ('move' === $kind) {
                continue;
            }

            self::assertNull($this->event(['kind' => $kind, 'anchor' => null])['anchor']);
        }
    }

    public function testCarriesPointerShapeAndCaret(): void
    {
        $caret = ['selector' => 'textarea[name="notes"]', 'region' => 'content', 'start' => 12, 'end' => 4];
        $pointer = $this->event(['shape' => 'pointer', 'caret' => $caret]);
        self::assertSame('pointer', $pointer['shape']);
        self::assertSame($caret, $pointer['caret']);
        // A caret alone keeps a peer visible while the pointer is outside the page.
        self::assertNull($this->event(['anchor' => null, 'caret' => $caret])['anchor']);
        // Tabs loaded before shapes existed send none.
        self::assertSame('default', $this->event(['shape' => null])['shape']);
    }

    public function testAcceptsTheBoundsOfAnchorsAndCarets(): void
    {
        $anchor = ['selector' => str_repeat('a', 1024), 'region' => 'body', 'x' => 0, 'y' => 1];
        self::assertSame($anchor, $this->event(['anchor' => $anchor])['anchor']);
        $caret = ['selector' => 'x', 'region' => 'modal', 'start' => 0, 'end' => 10_485_760];
        self::assertSame($caret, $this->event(['caret' => $caret])['caret']);
        foreach (CursorPosition::REGIONS as $region) {
            self::assertSame(
                $region,
                $this->event(['anchor' => ['region' => $region] + self::ANCHOR])['anchor']['region'] ?? null,
            );
        }
    }

    public function testCarriesTheFormControlASenderIsInEvenWithoutCaretOrPointer(): void
    {
        // A select shows no caret: its field alone keeps the peer's mark.
        self::assertSame(
            'article[category]',
            $this->event(['anchor' => null, 'field' => 'article[category]'])['field'],
        );
        self::assertSame(str_repeat('a', 512), $this->event(['field' => str_repeat('a', 512)])['field']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidEvents(): iterable
    {
        $event = 'Invalid cursor event.';
        $anchor = 'Invalid cursor anchor.';
        $caret = 'Invalid cursor caret.';
        $field = 'Invalid cursor field.';
        $move = 'A cursor move requires an anchor, a caret or a field.';
        $box = static fn(array $anchor): array => ['anchor' => $anchor + ['selector' => 'x', 'region' => 'body']];
        $text = static fn(array $caret): array => ['caret' => $caret + ['selector' => 'x', 'region' => 'body']];

        yield 'client id characters' => [['clientId' => '!'], $event];
        yield 'short client id' => [['clientId' => 'abc'], $event];
        yield 'long client id' => [['clientId' => str_repeat('a', 65)], $event];
        yield 'client id type' => [['clientId' => 1], $event];
        yield 'negative sequence' => [['sequence' => -1], $event];
        yield 'string sequence' => [['sequence' => '1'], $event];
        yield 'missing sequence' => [['sequence' => null], $event];
        yield 'kind' => [['kind' => 'fake'], $event];
        yield 'kind type' => [['kind' => 1], $event];
        yield 'style' => [['style' => 'fake'], $event];
        yield 'style type' => [['style' => true], $event];
        yield 'shape' => [['shape' => 'url(evil.png)'], $event];
        yield 'shape type' => [['shape' => 1], $event];
        yield 'move without position' => [['anchor' => null], $move];
        yield 'move without anchor or caret' => [['anchor' => null, 'caret' => null], $move];
        yield 'empty field' => [['field' => ''], $field];
        yield 'field type' => [['field' => 7], $field];
        yield 'long field' => [['field' => str_repeat('a', 513)], $field];
        yield 'caret type' => [['caret' => 'fake'], $caret];
        yield 'caret without end' => [$text(['start' => 0]), $caret];
        yield 'caret selector length' => [
            $text(['selector' => str_repeat('a', 1025), 'start' => 0, 'end' => 0]),
            $caret,
        ];
        yield 'caret region' => [$text(['region' => 'evil', 'start' => 0, 'end' => 0]), $caret];
        yield 'caret selector type' => [$text(['selector' => 1, 'start' => 0, 'end' => 0]), $caret];
        yield 'negative start' => [$text(['start' => -1, 'end' => 0]), $caret];
        yield 'negative end' => [$text(['start' => 0, 'end' => -1]), $caret];
        yield 'start past the limit' => [$text(['start' => 10_485_761, 'end' => 0]), $caret];
        yield 'end past the limit' => [$text(['start' => 0, 'end' => 10_485_761]), $caret];
        yield 'fractional start' => [$text(['start' => 1.5, 'end' => 0]), $caret];
        yield 'anchor type' => [['anchor' => 'fake'], $anchor];
        yield 'anchor without position' => [['anchor' => ['selector' => str_repeat('a', 1025)]], $anchor];
        yield 'long anchor selector' => [$box(['selector' => str_repeat('a', 1025), 'x' => 0, 'y' => 0]), $anchor];
        yield 'anchor selector type' => [$box(['selector' => 1, 'x' => 0, 'y' => 0]), $anchor];
        yield 'anchor region' => [$box(['region' => 'evil', 'x' => 0, 'y' => 0]), $anchor];
        yield 'negative x' => [$box(['x' => -1, 'y' => 0]), $anchor];
        yield 'x past the box' => [$box(['x' => 1.5, 'y' => 0]), $anchor];
        yield 'negative y' => [$box(['x' => 0, 'y' => -0.5]), $anchor];
        yield 'y past the box' => [$box(['x' => 0, 'y' => 2]), $anchor];
        yield 'x type' => [$box(['x' => 'oops', 'y' => 0]), $anchor];
        yield 'y type' => [$box(['x' => 0, 'y' => 'oops']), $anchor];
        yield 'numeric string x' => [$box(['x' => '1e999', 'y' => 0]), $anchor];
        yield 'infinite x' => [$box(['x' => \INF, 'y' => 0]), $anchor];
        yield 'not a number y' => [$box(['x' => 0, 'y' => \NAN]), $anchor];
    }

    /** @param array<string, mixed> $invalid */
    #[DataProvider('invalidEvents')]
    public function testRejectsInvalidEvents(array $invalid, string $message): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage($message);
        $this->event($invalid);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return CursorEvent::fromArray(array_replace([
            'clientId' => '12345678-1234-1234',
            'sequence' => 1,
            'kind' => 'move',
            'style' => 'mac',
            'anchor' => self::ANCHOR,
        ], $overrides));
    }
}
