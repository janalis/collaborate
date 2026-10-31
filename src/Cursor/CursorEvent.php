<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Cursor;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** The part of a cursor event its sender decides, validated; the server adds who sent it. */
final class CursorEvent
{
    /** Pointer shapes every client can draw; `cursors/geometry.js` holds their paths. */
    public const array SHAPES = [
        'default',
        'pointer',
        'text',
        'vertical-text',
        'grab',
        'grabbing',
        'move',
        'not-allowed',
        'help',
        'wait',
        'progress',
        'crosshair',
        'col-resize',
        'row-resize',
        'ew-resize',
        'ns-resize',
        'nesw-resize',
        'nwse-resize',
    ];

    public const array KINDS = ['move', 'hide', 'hello', 'heartbeat', 'leave'];
    public const array STYLES = ['mac', 'windows', 'ubuntu', 'touch'];
    public const string CLIENT_ID_PATTERN = '/^[a-zA-Z0-9-]{16,64}$/D';
    public const int MAX_FIELD_BYTES = 512;

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array{clientId: string, sequence: int, kind: string, style: string, shape: string, anchor: array{selector: string, region: string, x: int|float, y: int|float}|null, caret: array{selector: string, region: string, start: int, end: int}|null, field: string|null}
     */
    public static function fromArray(array $data): array
    {
        if (
            !\is_string($data['clientId'] ?? null)
            || !\is_int($data['sequence'] ?? null)
            || $data['sequence'] < 0
            || !preg_match(self::CLIENT_ID_PATTERN, $data['clientId'])
            || !\is_string($data['kind'] ?? null)
            || !\in_array($data['kind'], self::KINDS, true)
            || !\is_string($data['style'] ?? null)
            || !\in_array($data['style'], self::STYLES, true)
            || !\in_array($data['shape'] ?? 'default', self::SHAPES, true)
        ) {
            throw new BadRequestHttpException('Invalid cursor event.');
        }
        // Tabs loaded before shapes existed send none.
        $shape = \is_string($data['shape'] ?? null) ? $data['shape'] : 'default';
        $anchor = CursorPosition::anchor($data['anchor'] ?? null);
        $caret = CursorPosition::caret($data['caret'] ?? null);
        $field = self::field($data['field'] ?? null);
        if ('move' === $data['kind'] && null === $anchor && null === $caret && null === $field) {
            throw new BadRequestHttpException('A cursor move requires an anchor, a caret or a field.');
        }

        return [
            'clientId' => $data['clientId'],
            'sequence' => $data['sequence'],
            'kind' => $data['kind'],
            'style' => $data['style'],
            'shape' => $shape,
            'anchor' => $anchor,
            'caret' => $caret,
            'field' => $field,
        ];
    }

    /** The name of the shared form control the sender is in, for a control that shows no caret too. */
    private static function field(mixed $field): ?string
    {
        if (null === $field) {
            return null;
        }
        if (!\is_string($field) || '' === $field || \strlen($field) > self::MAX_FIELD_BYTES) {
            throw new BadRequestHttpException('Invalid cursor field.');
        }

        return $field;
    }
}
