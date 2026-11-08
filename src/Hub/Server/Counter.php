<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

/** Connections per key (a client IP, a user), forgetting a key once none is left. */
final class Counter
{
    /** @var array<array-key, int> */
    private array $counts = [];

    public function add(int|string $key): void
    {
        $this->counts[$key] = $this->count($key) + 1;
    }

    public function remove(int|string $key): void
    {
        if (1 === $this->counts[$key]--) {
            unset($this->counts[$key]);
        }
    }

    public function count(int|string $key): int
    {
        return $this->counts[$key] ?? 0;
    }

    /** Keys counted. */
    public function keys(): int
    {
        return \count($this->counts);
    }
}
