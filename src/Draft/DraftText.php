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
        $text = $this->convert($value, 'UTF-16LE', 'UTF-8');
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
                $inserted = implode('', array_map(self::node(...), $operation['nodes']));
                $units = $this->convert($inserted, 'UTF-16LE', 'UTF-8');
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

        return ['text' => $this->convert($text, 'UTF-8', 'UTF-16LE'), 'graveyard' => $graveyard];
    }

    /** @return list<array<string, mixed>> */
    public function diff(string $before, string $after, int $version): array
    {
        $old = $this->convert($before, 'UTF-16LE', 'UTF-8');
        $new = $this->convert($after, 'UTF-16LE', 'UTF-8');
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
        // Both texts are valid UTF-16: a common prefix that ends inside a surrogate pair does so in both.
        $prefix = $this->common($old, $new);
        if ($this->splits($old, intdiv($prefix, 2))) {
            $prefix -= 2;
        }
        $suffix = min($this->common(strrev($old), strrev($new)), \strlen($old) - $prefix, \strlen($new) - $prefix);
        if ($this->splits($old, intdiv(\strlen($old) - $suffix, 2))) {
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
                'nodes' => [['data' => $this->convert($inserted, 'UTF-8', 'UTF-16LE')]],
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

        $index = $paragraph ? 1 : 0;
        if (!\is_int($value['path'][$index]) || $value['path'][$index] < 0) {
            throw new BadRequestHttpException('Invalid plain-text offset.');
        }

        return $value['path'][$index];
    }

    /** An inserted node's text: plain text holds text nodes only. */
    private static function node(mixed $node): string
    {
        if (
            !\is_array($node)
            || !\is_string($node['data'] ?? null)
            || [] !== array_diff(array_keys($node), ['data', 'attributes'])
            || [] !== ($node['attributes'] ?? [])
        ) {
            throw new BadRequestHttpException('Plain text cannot contain model elements or attributes.');
        }

        return $node['data'];
    }

    private function boundary(string $text, int $offset): void
    {
        if (($offset * 2) > \strlen($text) || $this->splits($text, $offset)) {
            throw new BadRequestHttpException('Invalid UTF-16 text boundary.');
        }
    }

    /** The bytes of the whole UTF-16 units two texts start with. */
    private function common(string $first, string $second): int
    {
        return intdiv(strspn($first ^ $second, "\0"), 2) * 2;
    }

    /** Whether an offset falls inside a surrogate pair: the unit before it is a high surrogate (0xD800-0xDBFF). */
    private function splits(string $text, int $offset): bool
    {
        if ($offset < 1) {
            return false;
        }
        $high = \ord($text[($offset * 2) - 1]);

        return $high >= 0xD8 && $high <= 0xDB;
    }

    /** mbstring converts any string between UTF-8 and UTF-16LE: it answers false only to an unknown encoding name. */
    private function convert(string $text, string $to, string $from): string
    {
        $converted = mb_convert_encoding($text, $to, $from);
        \assert(\is_string($converted));

        return $converted;
    }
}
