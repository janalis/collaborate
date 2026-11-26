<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

/**
 * Run-based source provenance. Hidden runs retain anchors for another author's visible insertions.
 * @phpstan-type Anchor array{run: string, offset: int}
 * @phpstan-type Run array{text: string, transaction: string|null, clock: int, after: Anchor|null}
 * @phpstan-type Delete array{run: string, start: int, end: int}
 * @phpstan-type Toggle array{clock: int, id: string, active: bool}
 */
final class DraftSource
{
    public const int MAX_BYTES = 2_097_152;

    public const int MAX_RUNS = 10_000;

    /**
     * @param list<array<string, mixed>> $events trusted stored events, including their server-assigned actor
     * @param list<array<string, mixed>> $operations untrusted new operations
     * @return array{text: string, events: list<array<string, mixed>>, operations: list<array<string, mixed>>}
     */
    public function apply(string $initial, array $events, array $operations, int $actor): array
    {
        /** @var array<string, Run> $runs */
        $runs = ['initial' => ['text' => $initial, 'transaction' => null, 'clock' => 0, 'after' => null]];
        $lengths = ['initial' => mb_strlen($initial, 'UTF-8')];
        /** @var array<string, array{actor: int, deletes: list<Delete>}> $transactions */
        $transactions = [];
        /** @var array<string, Toggle> $toggles */
        $toggles = [];
        $ids = [];
        $normalized = array_map(static fn(array $operation): array => ['actor' => $actor] + $operation, $operations);
        foreach ([...$events, ...$normalized] as $event) {
            $id = \is_string($event['id'] ?? null) ? $event['id'] : '';
            $clock = \is_int($event['clock'] ?? null) ? $event['clock'] : 0;
            if (
                1 !== preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $id)
                || isset($ids[$id])
                || $clock < 1
                || $clock > 9_007_199_254_740_991
                || !\is_int($event['actor'] ?? null)
            ) {
                throw new InvalidDraftEventException('Invalid source operation identity.');
            }
            $ids[$id] = true;
            if ('SourceTransaction' === ($event['__className'] ?? null)) {
                if (!\is_array($event['inserts'] ?? null) || !\is_array($event['deletes'] ?? null)) {
                    throw new InvalidDraftEventException('Invalid source transaction.');
                }
                $inserts = $event['inserts'];
                $deletes = $event['deletes'];
                if (
                    !array_is_list($inserts)
                    || !array_is_list($deletes)
                    || (\count($inserts) + \count($deletes)) > 2048
                    || [] === $inserts && [] === $deletes
                ) {
                    throw new InvalidDraftEventException('Invalid source transaction.');
                }
                foreach (array_keys($inserts) as $index) {
                    if (!\is_array($inserts[$index])) {
                        throw new InvalidDraftEventException('Invalid source insertion.');
                    }
                    $insert = $inserts[$index];
                    if (
                        ($insert['id'] ?? null) !== $id . ':' . $index
                        || !\is_string($insert['text'] ?? null)
                        || '' === $insert['text']
                        || !mb_check_encoding($insert['text'], 'UTF-8')
                        || !array_key_exists('after', $insert)
                    ) {
                        throw new InvalidDraftEventException('Invalid source insertion.');
                    }
                    if (null !== $insert['after'] && !\is_array($insert['after'])) {
                        throw new InvalidDraftEventException('Invalid source anchor.');
                    }
                    $after = $insert['after'];
                    if (null !== $after) {
                        if (
                            !\is_string($after['run'] ?? null)
                            || !isset($runs[$after['run']])
                            || !\is_int($after['offset'] ?? null)
                            || $after['offset'] < 0
                            || $after['offset'] >= $lengths[$after['run']]
                        ) {
                            throw new InvalidDraftEventException('Invalid source anchor.');
                        }
                    }
                    if (\count($runs) >= self::MAX_RUNS) {
                        throw new DraftTooLargeException('Source run limit reached.');
                    }
                    /** @var Anchor|null $after */
                    $lengths[$id . ':' . $index] = mb_strlen($insert['text'], 'UTF-8');
                    $runs[$id . ':' . $index] = [
                        'text' => $insert['text'],
                        'after' => $after,
                        'transaction' => $id,
                        'clock' => $clock,
                    ];
                }
                foreach (array_keys($deletes) as $index) {
                    if (!\is_array($deletes[$index])) {
                        throw new InvalidDraftEventException('Invalid source deletion.');
                    }
                    $delete = $deletes[$index];
                    if (
                        !\is_string($delete['run'] ?? null)
                        || !isset($runs[$delete['run']])
                        || !\is_int($delete['start'] ?? null)
                        || !\is_int($delete['end'] ?? null)
                        || $delete['start'] < 0
                        || $delete['end'] <= $delete['start']
                        || $delete['end'] > $lengths[$delete['run']]
                    ) {
                        throw new InvalidDraftEventException('Invalid source deletion.');
                    }
                }
                /** @var list<Delete> $deletes */
                $transactions[$id] = ['actor' => $event['actor'], 'deletes' => $deletes];
            } elseif ('SourceToggle' === ($event['__className'] ?? null)) {
                $target = \is_string($event['target'] ?? null) ? $event['target'] : '';
                if (
                    !isset($transactions[$target])
                    || !\is_bool($event['active'] ?? null)
                    || $transactions[$target]['actor'] !== $event['actor']
                ) {
                    throw new InvalidDraftEventException('Source undo must name the author’s transaction.');
                }
                $previous = $toggles[$target] ?? null;
                if (
                    null === $previous
                    || $clock > $previous['clock']
                    || $clock === $previous['clock'] && strcmp($id, $previous['id']) > 0
                ) {
                    $toggles[$target] = ['clock' => $clock, 'id' => $id, 'active' => $event['active']];
                }
            } else {
                throw new InvalidDraftEventException('Unknown source operation.');
            }
        }
        /** @var array<string, array<int, list<string>>> $children */
        $children = [];
        foreach ($runs as $id => $run) {
            if ('initial' === $id) {
                continue;
            }
            $after = $run['after'];
            $parent = null === $after ? '' : $after['run'];
            $position = null === $after ? 0 : $after['offset'] + 1;
            $children[$parent][$position][] = $id;
        }
        foreach ($children as &$positions) {
            foreach ($positions as &$siblings) {
                usort($siblings, static fn(string|int $a, string|int $b): int => $runs[$b]['clock']
                    !== $runs[$a]['clock']
                        ? $runs[$b]['clock'] <=> $runs[$a]['clock']
                        : strcmp((string) $a, (string) $b));
            }
            unset($siblings);
        }
        unset($positions);
        /** @var array<string, list<array{int, int}>> $masks */
        $masks = [];
        foreach ($transactions as $id => $transaction) {
            if (false === ($toggles[$id]['active'] ?? true)) {
                continue;
            }
            foreach ($transaction['deletes'] as $delete) {
                $masks[$delete['run']][] = [$delete['start'], $delete['end']];
            }
        }
        foreach ($masks as &$intervals) {
            usort($intervals, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $merged = [];
            foreach ($intervals as [$start, $end]) {
                $last = array_key_last($merged);
                if (null !== $last && $start <= $merged[$last][1]) {
                    $merged[$last][1] = max($end, $merged[$last][1]);
                } else {
                    $merged[] = [$start, $end];
                }
            }
            $intervals = $merged;
        }
        unset($intervals);
        /** @var list<array{run: string}|array{text: string}> $tasks */
        $tasks = [['run' => 'initial']];
        foreach (array_reverse($children[''][0] ?? []) as $id) {
            $tasks[] = ['run' => $id];
        }
        $text = '';
        while ([] !== $tasks) {
            $task = array_pop($tasks);
            if (isset($task['text'])) {
                $text .= $task['text'];
                continue;
            }
            $id = $task['run'];
            $run = $runs[$id];
            $units = mb_convert_encoding($run['text'], 'UTF-32LE', 'UTF-8');
            \assert(\is_string($units));
            $length = intdiv(\strlen($units), 4);
            /** @var array<int, bool> $points */
            $points = [0 => true, $length => true];
            $nested = $children[$id] ?? [];
            foreach (array_keys($nested) as $position) {
                $points[$position] = true;
            }
            foreach ($masks[$id] ?? [] as [$start, $end]) {
                $points[$start] = true;
                $points[$end] = true;
            }
            ksort($points);
            $visible = null === $run['transaction'] || ($toggles[$run['transaction']]['active'] ?? true);
            $from = 0;
            $maskIndex = 0;
            $intervals = $masks[$id] ?? [];
            $next = [];
            foreach (array_keys($points) as $to) {
                $hidden = !$visible;
                while (isset($intervals[$maskIndex]) && $intervals[$maskIndex][1] <= $from) {
                    ++$maskIndex;
                }
                if (isset($intervals[$maskIndex]) && $intervals[$maskIndex][0] <= $from) {
                    $hidden = true;
                }
                if (!$hidden && $to > $from) {
                    $piece = mb_convert_encoding(substr($units, $from * 4, ($to - $from) * 4), 'UTF-8', 'UTF-32LE');
                    \assert(\is_string($piece));
                    $next[] = ['text' => $piece];
                }
                foreach ($nested[$to] ?? [] as $child) {
                    $next[] = ['run' => $child];
                }
                $from = $to;
            }
            array_push($tasks, ...array_reverse($next));
        }

        return ['text' => $text, 'events' => [...$events, ...$normalized], 'operations' => $normalized];
    }
}
