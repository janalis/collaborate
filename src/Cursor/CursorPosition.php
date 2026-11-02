<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Cursor;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Where a cursor event points: an anchor in a page region, a caret in a text control, or a record's popup. */
final class CursorPosition
{
    public const array REGIONS = ['header', 'mobile-header', 'sidebar', 'content', 'modal', 'body'];
    public const int MAX_CARET_OFFSET = 10_485_760;
    public const int MAX_SELECTOR_BYTES = 1024;
    public const int MAX_KEY_BYTES = 255;

    /**
     * @param list<string> $regions the region names of the configuration
     *
     * @return array{selector: string, region: string, x: int|float, y: int|float}|null
     */
    public static function anchor(mixed $anchor, array $regions = self::REGIONS): ?array
    {
        if (null === $anchor) {
            return null;
        }
        if (\is_array($anchor)) {
            $location = self::location($anchor, $regions);
            $x = self::ratio($anchor['x'] ?? null);
            $y = self::ratio($anchor['y'] ?? null);
            if (null !== $location && null !== $x && null !== $y) {
                return $location + ['x' => $x, 'y' => $y];
            }
        }

        throw new BadRequestHttpException('Invalid cursor anchor.');
    }

    /**
     * @param list<string> $regions the regions whose fields share their caret
     *
     * @return array{selector: string, region: string, start: int, end: int}|null
     */
    public static function caret(mixed $caret, array $regions = self::REGIONS): ?array
    {
        if (null === $caret) {
            return null;
        }
        if (\is_array($caret)) {
            $location = self::location($caret, $regions);
            $start = self::offset($caret['start'] ?? null);
            $end = self::offset($caret['end'] ?? null);
            if (null !== $location && null !== $start && null !== $end) {
                return $location + ['start' => $start, 'end' => $end];
            }
        }

        throw new BadRequestHttpException('Invalid cursor caret.');
    }

    /**
     * The popup of a record a peer has open: one of the configured kinds, and the record's key.
     *
     * @param list<string> $kinds
     *
     * @return array{kind: string, key: string}|null
     */
    public static function popup(mixed $popup, array $kinds = []): ?array
    {
        if (null === $popup) {
            return null;
        }
        if (
            \is_array($popup)
            && \in_array($popup['kind'] ?? null, $kinds, true)
            && \is_string($popup['key'] ?? null)
            && '' !== $popup['key']
            && \strlen($popup['key']) <= self::MAX_KEY_BYTES
        ) {
            return ['kind' => $popup['kind'], 'key' => $popup['key']];
        }

        throw new BadRequestHttpException('Invalid cursor popup.');
    }

    /**
     * @param array<array-key, mixed> $position
     * @param list<string> $regions
     *
     * @return array{selector: string, region: string}|null
     */
    private static function location(array $position, array $regions): ?array
    {
        if (
            !\is_string($position['selector'] ?? null)
            || \strlen($position['selector']) > self::MAX_SELECTOR_BYTES
            || !\in_array($position['region'] ?? null, $regions, true)
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
