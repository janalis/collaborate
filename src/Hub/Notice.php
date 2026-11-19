<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub;

/**
 * What the bundle tells the pages listening to some topics: a JSON payload naming no content. Every notice is
 * private: only a subscriber the application granted one of its topics hears it.
 */
final readonly class Notice
{
    /** @var non-empty-list<string> */
    public array $topics;

    /**
     * @param string|list<string> $topics one topic, or the topics a notice reaches at once (each subscriber once)
     * @param string $data a JSON document
     */
    public function __construct(
        string|array $topics,
        public string $data,
    ) {
        $topics = array_values(array_unique(\is_string($topics) ? [$topics] : $topics));
        if ([] === $topics) {
            throw new \InvalidArgumentException('A notice names at least one topic.');
        }
        $this->topics = $topics;
    }

    /**
     * A draft changed (`kind`: append, discard, reconcile, saved): its pages replay it. An append names its place
     * in the journal, so that a page that read it already replays nothing.
     *
     * @param array{generation?: int, revision?: int} $place
     */
    public static function draft(string $topic, string $draft, string $kind, array $place = []): self
    {
        return new self($topic, self::json(['channel' => 'draft', 'draft' => $draft, 'kind' => $kind] + $place));
    }

    /**
     * Records saved outside their drafts' forms: every draft session of a page compares its form again. One
     * notice names several drafts, so it carries none.
     *
     * @param list<string> $topics
     */
    public static function records(array $topics): self
    {
        return new self($topics, self::json(['channel' => 'draft', 'kind' => 'record']));
    }

    /**
     * Saved lists and details of these entities (short class names) are stale.
     *
     * @param list<string> $entities
     */
    public static function updates(string $topic, array $entities): self
    {
        return new self($topic, self::json(['channel' => 'updates', 'kind' => 'saved', 'entities' => $entities]));
    }

    /** A user's notifications changed: their pages ask for them again. */
    public static function notifications(string $topic): self
    {
        return new self($topic, self::json(['channel' => 'notifications']));
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR);
    }
}
