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
        if (\is_array($anchor)) {
            $location = self::location($anchor);
            $x = self::ratio($anchor['x'] ?? null);
            $y = self::ratio($anchor['y'] ?? null);
            if (null !== $location && null !== $x && null !== $y) {
                return $location + ['x' => $x, 'y' => $y];
            }
        }

        throw new BadRequestHttpException('Invalid cursor anchor.');
    }

    /** @return array{selector: string, region: string, start: int, end: int}|null */
    public static function caret(mixed $caret): ?array
    {
        if (null === $caret) {
            return null;
        }
        if (\is_array($caret)) {
            $location = self::location($caret);
            $start = self::offset($caret['start'] ?? null);
            $end = self::offset($caret['end'] ?? null);
            if (null !== $location && null !== $start && null !== $end) {
                return $location + ['start' => $start, 'end' => $end];
            }
        }

        throw new BadRequestHttpException('Invalid cursor caret.');
    }

    /**
     * @param array<array-key, mixed> $position
     *
     * @return array{selector: string, region: string}|null
     */
    private static function location(array $position): ?array
    {
        if (
            !\is_string($position['selector'] ?? null)
            || \strlen($position['selector']) > self::MAX_SELECTOR_BYTES
            || !\in_array($position['region'] ?? null, self::REGIONS, true)
        ) {
            return null;
        }

        return ['selector' => $position['selector'], 'region' => $position['region']];
    }

    /** A fraction of the anchor element's box, from 0 to 1: NaN and the infinities compare outside it. */
    private static function ratio(mixed $value): int|float|null
    {
        return (\is_int($value) || \is_float($value)) && $value >= 0 && $value <= 1 ? $value : null;
    }

    private static function offset(mixed $value): ?int
    {
        return \is_int($value) && $value >= 0 && $value <= self::MAX_CARET_OFFSET ? $value : null;
    }
}
