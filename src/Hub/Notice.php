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
}
