<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * What an appended event brings into a field's state, checked before any of it is applied: the journal
 * stores operations, values and labels as the page sent them, and replays them to every peer.
 *
 * @internal
 */
final class EventValidator
{
    /**
     * @param int $maxValueDepth nesting a value may hold: the draft view wraps it in a few more levels, which JSON caps at 512
     * @param int $labelLength   the longest label a choice event names for one of its ids
     */
    public function __construct(
        private readonly int $maxValueDepth,
        private readonly int $labelLength,
    ) {}

    /** Operations that follow on from the field's model version, one version each.
     *
     * @return list<array<string, mixed>>
     */
    public function operations(mixed $operations, int $version): array
    {
        if (
            !\is_array($operations)
            || [] === $operations
            || \count($operations) > 2048
            || !array_is_list($operations)
        ) {
            throw new BadRequestHttpException('Invalid Collaborate operations.');
        }
        if (!array_all(
            $operations,
            static fn(mixed $operation, int $offset): bool => (
                \is_array($operation)
                && ($operation['baseVersion'] ?? null) === ($version + $offset)
                && \is_string($operation['__className'] ?? null)
            ),
        )) {
            throw new BadRequestHttpException('Invalid Collaborate operation version.');
        }
        /** @var list<array<string, mixed>> $operations */

        return $operations;
    }

    /** The value of a `value` event, and the labels it names, for a field of `$type`.
     *
     * @param array<string, mixed> $event an event that holds a value
     *
     * @return array{value: mixed, labels?: mixed}
     */
    public function value(array $event, string $type): array
    {
        if (
            !\is_scalar($event['value']) && null !== $event['value'] && !\is_array($event['value'])
            || !$this->shallow($event['value'])
        ) {
            throw new BadRequestHttpException('Invalid Collaborate value.');
        }
        $payload = ['value' => $event['value']];
        // A choice names what its value shows: a peer's autocomplete holds no option for it.
        if (\array_key_exists('labels', $event)) {
            if ('value' !== $type || !$this->labels($event['labels'], $event['value'])) {
                throw new BadRequestHttpException('Invalid Collaborate labels.');
            }
            $payload['labels'] = $event['labels'];
        }
        if ('member' === $type) {
            if (
                !\is_array($event['value'])
                || !\is_bool($event['value']['alive'] ?? null)
                || !\is_int($event['value']['order'] ?? null) && !\is_float($event['value']['order'] ?? null)
            ) {
                throw new BadRequestHttpException('Invalid collection membership.');
            }
        }

        return $payload;
    }

    /** Whether a value encodes within MAX_VALUE_DEPTH levels. */
    private function shallow(mixed $value): bool
    {
        try {
            json_encode($value, \JSON_THROW_ON_ERROR, $this->maxValueDepth);
        } catch (\JsonException) {
            return false;
        }

        return true;
    }

    /** Labels name some of the ids a choice holds, each in at most LABEL_LENGTH characters. */
    private function labels(mixed $labels, mixed $value): bool
    {
        if (!\is_array($labels)) {
            return false;
        }
        $ids = array_map(strval(...), array_filter(\is_array($value) ? $value : [$value], \is_scalar(...)));

        return array_all(
            $labels,
            fn(mixed $label, int|string $id): bool => (
                \is_string($label)
                && mb_strlen($label) <= $this->labelLength
                && \in_array((string) $id, $ids, true)
            ),
        );
    }
}
