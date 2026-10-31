<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Cursor;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Where a cursor event points: an anchor in a page region, or a caret in a text control. */
final class CursorPosition
{
    public const array REGIONS = ['header', 'mobile-header', 'sidebar', 'content', 'modal', 'body'];
    public const int MAX_CARET_OFFSET = 10_485_760;
    public const int MAX_SELECTOR_BYTES = 1024;

    /** @return array{selector: string, region: string, x: int|float, y: int|float}|null */
    public static function anchor(mixed $anchor): ?array
    {
        if (null === $anchor) {
            return null;
        }
        if (
            !\is_array($anchor)
            || !self::isRatio($anchor['x'] ?? null)
            || !self::isRatio($anchor['y'] ?? null)
            || !self::isLocated($anchor)
        ) {
            throw new BadRequestHttpException('Invalid cursor anchor.');
        }
        \assert(\is_string($anchor['selector']) && \is_string($anchor['region']), 'isLocated() checked both.');
        \assert(\is_int($anchor['x']) || \is_float($anchor['x']), 'isRatio() checked it.');
        \assert(\is_int($anchor['y']) || \is_float($anchor['y']), 'isRatio() checked it.');

        return [
            'selector' => $anchor['selector'],
            'region' => $anchor['region'],
            'x' => $anchor['x'],
            'y' => $anchor['y'],
        ];
    }

    /** @return array{selector: string, region: string, start: int, end: int}|null */
    public static function caret(mixed $caret): ?array
    {
        if (null === $caret) {
            return null;
        }
        if (
            !\is_array($caret)
            || !self::isOffset($caret['start'] ?? null)
            || !self::isOffset($caret['end'] ?? null)
            || !self::isLocated($caret)
        ) {
            throw new BadRequestHttpException('Invalid cursor caret.');
        }
        \assert(\is_string($caret['selector']) && \is_string($caret['region']), 'isLocated() checked both.');
        \assert(\is_int($caret['start']) && \is_int($caret['end']), 'isOffset() checked both.');

        return [
            'selector' => $caret['selector'],
            'region' => $caret['region'],
            'start' => $caret['start'],
            'end' => $caret['end'],
        ];
    }

    /** @param array<array-key, mixed> $position */
    private static function isLocated(array $position): bool
    {
        return (
            \is_string($position['selector'] ?? null)
            && \strlen($position['selector']) <= self::MAX_SELECTOR_BYTES
            && \in_array($position['region'] ?? null, self::REGIONS, true)
        );
    }

    /** A finite fraction of the anchor element's box, from 0 to 1. */
    private static function isRatio(mixed $value): bool
    {
        return (\is_int($value) || \is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1;
    }

    private static function isOffset(mixed $value): bool
    {
        return \is_int($value) && $value >= 0 && $value <= self::MAX_CARET_OFFSET;
    }
}
