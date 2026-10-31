<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** The plain-input adapter has one paragraph; its offsets, like CKEditor's, are UTF-16 units. */
final class DraftText
{
    /** @param list<array<string, mixed>> $operations
     * @return array{text: string, graveyard: int}
     */
    public function apply(string $value, int $graveyard, array $operations): array
    {
        $text = (string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        foreach ($operations as $operation) {
            if ('NoOperation' === ($operation['__className'] ?? null)) {
                continue;
            }
            if ('InsertOperation' === ($operation['__className'] ?? null)) {
                // CKEditor rebases an insert made inside a range a peer removed into the graveyard: it lands removed.
                $removed = '$graveyard' === ($operation['position']['root'] ?? null);
                $offset = $this->position($operation['position'] ?? null, $removed ? '$graveyard' : 'main', !$removed);
                if ($removed && $offset > $graveyard) {
                    throw new BadRequestHttpException('Invalid plain-text position.');
                }
                if (!$removed) {
                    $this->boundary($text, $offset);
                }
                if (!\is_array($operation['nodes'] ?? null) || !array_is_list($operation['nodes'])) {
                    throw new BadRequestHttpException('Invalid plain-text insertion.');
                }
                $inserted = '';
                foreach ($operation['nodes'] as $node) {
                    if (
                        !\is_array($node)
                        || !\is_string($node['data'] ?? null)
                        || [] !== array_diff(array_keys($node), ['data', 'attributes'])
                        || [] !== ($node['attributes'] ?? [])
                    ) {
                        throw new BadRequestHttpException('Plain text cannot contain model elements or attributes.');
                    }
                    $inserted .= $node['data'];
                }
                $units = (string) mb_convert_encoding($inserted, 'UTF-16LE', 'UTF-8');
                if ($removed) {
                    $graveyard += intdiv(\strlen($units), 2);
                } else {
                    $text = substr_replace($text, $units, $offset * 2, 0);
                }
            } elseif ('MoveOperation' === ($operation['__className'] ?? null)) {
                $offset = $this->position($operation['sourcePosition'] ?? null, 'main', true);
                $target = $this->position($operation['targetPosition'] ?? null, '$graveyard', false);
                if (!\is_int($operation['howMany'] ?? null) || $operation['howMany'] < 1 || $target > $graveyard) {
                    throw new BadRequestHttpException('Invalid plain-text removal.');
                }
                $this->boundary($text, $offset);
                $this->boundary($text, $offset + $operation['howMany']);
                $text = substr_replace($text, '', $offset * 2, $operation['howMany'] * 2);
                $graveyard += $operation['howMany'];
            } else {
                throw new BadRequestHttpException('Unsupported plain-text operation.');
            }
        }

        return ['text' => (string) mb_convert_encoding($text, 'UTF-8', 'UTF-16LE'), 'graveyard' => $graveyard];
    }

    /** @return list<array<string, mixed>> */
    public function diff(string $before, string $after, int $version): array
    {
        if ($before === $after) {
            return [];
        }
        $old = (string) mb_convert_encoding($before, 'UTF-16LE', 'UTF-8');
        $new = (string) mb_convert_encoding($after, 'UTF-16LE', 'UTF-8');
        // Trimming must preserve the existing core text, including concurrent edits to it.
        if ('' !== $new && false !== ($inside = strpos($old, $new)) && 0 === ($inside % 2)) {
            $operations = [];
            $tail = \strlen($old) - $inside - \strlen($new);
            if ($tail > 0) {
                $operations[] = $this->remove(intdiv($inside + \strlen($new), 2), intdiv($tail, 2), $version++);
            }
            if ($inside > 0) {
                $operations[] = $this->remove(0, intdiv($inside, 2), $version);
            }

            return $operations;
        }
        $prefix = 0;
        while ($prefix < min(\strlen($old), \strlen($new)) && substr($old, $prefix, 2) === substr($new, $prefix, 2)) {
            $prefix += 2;
        }
        if ($this->splits($old, intdiv($prefix, 2)) || $this->splits($new, intdiv($prefix, 2))) {
            $prefix -= 2;
        }
        $suffix = 0;
        while (
            $suffix < min(\strlen($old) - $prefix, \strlen($new) - $prefix)
            && substr($old, -$suffix - 2, 2) === substr($new, -$suffix - 2, 2)
        ) {
            $suffix += 2;
        }
        if (
            $this->splits($old, intdiv(\strlen($old) - $suffix, 2))
            || $this->splits($new, intdiv(\strlen($new) - $suffix, 2))
        ) {
            $suffix -= 2;
        }
        $operations = [];
        $removed = \strlen($old) - $prefix - $suffix;
        if ($removed > 0) {
            $operations[] = $this->remove(intdiv($prefix, 2), intdiv($removed, 2), $version++);
        }
        $inserted = substr($new, $prefix, \strlen($new) - $prefix - $suffix);
        if ('' !== $inserted) {
            $operations[] = [
                '__className' => 'InsertOperation',
                'baseVersion' => $version,
                'position' => ['root' => 'main', 'path' => [0, intdiv($prefix, 2)], 'stickiness' => 'toNone'],
                'nodes' => [['data' => mb_convert_encoding($inserted, 'UTF-8', 'UTF-16LE')]],
                'shouldReceiveAttributes' => false,
            ];
        }

        return $operations;
    }

    /** @return array<string, mixed> */
    private function remove(int $offset, int $count, int $version): array
    {
        return [
            '__className' => 'MoveOperation',
            'baseVersion' => $version,
            'howMany' => $count,
            'sourcePosition' => ['root' => 'main', 'path' => [0, $offset], 'stickiness' => 'toNext'],
            'targetPosition' => ['root' => '$graveyard', 'path' => [0], 'stickiness' => 'toNone'],
        ];
    }

    private function position(mixed $value, string $root, bool $paragraph): int
    {
        if (
            !\is_array($value)
            || ($value['root'] ?? null) !== $root
            || !\is_array($value['path'] ?? null)
            || \count($value['path']) !== ($paragraph ? 2 : 1)
            || $paragraph && 0 !== $value['path'][0]
        ) {
            throw new BadRequestHttpException('Invalid plain-text position.');
        }

        $offset = $value['path'][$paragraph ? 1 : 0];
        if (!\is_int($offset) || $offset < 0) {
            throw new BadRequestHttpException('Invalid plain-text offset.');
        }

        return $offset;
    }

    private function boundary(string $text, int $offset): void
    {
        if (($offset * 2) > \strlen($text) || $this->splits($text, $offset)) {
            throw new BadRequestHttpException('Invalid UTF-16 text boundary.');
        }
    }

    private function splits(string $text, int $offset): bool
    {
        if ($offset < 1 || ($offset * 2) >= \strlen($text)) {
            return false;
        }
        $unit = \ord($text[$offset * 2]) | (\ord($text[($offset * 2) + 1]) << 8);
        $previous = \ord($text[($offset * 2) - 2]) | (\ord($text[($offset * 2) - 1]) << 8);

        return $unit >= 0xDC00 && $unit <= 0xDFFF && $previous >= 0xD800 && $previous <= 0xDBFF;
    }
}
