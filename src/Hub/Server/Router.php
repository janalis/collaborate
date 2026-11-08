<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

use Janalis\Collaborate\Hub\Transport\WebSocket\TokenClaims;

/**
 * Who hears what: topic → subscribed connections, connection → its topics. A notice reaches each connection of
 * any of its topics once, and only while the token it subscribed with is valid: expiry is checked at send, so a
 * lagging timer delivers nothing late.
 */
final class Router
{
    /** @var array<string, array<int, Connection>> */
    private array $topics = [];

    /** @var array<int, list<string>> */
    private array $subscriptions = [];

    /** Replaces the topics, expiry and cursor room of a connection with those of a token, at once. */
    public function subscribe(Connection $connection, TokenClaims $claims): void
    {
        $this->remove($connection);
        foreach ($claims->topics as $topic) {
            $this->topics[$topic][$connection->id] = $connection;
        }
        $this->subscriptions[$connection->id] = $claims->topics;
        $connection->exp = $claims->exp;
        $connection->claims = $claims;
    }

    public function remove(Connection $connection): void
    {
        foreach ($this->subscriptions[$connection->id] ?? [] as $topic) {
            unset($this->topics[$topic][$connection->id]);
            if ([] === $this->topics[$topic]) {
                unset($this->topics[$topic]);
            }
        }
        unset($this->subscriptions[$connection->id]);
    }

    /**
     * Writes a frame once to each connection subscribed to any of the topics with a token valid at `$now`.
     *
     * @param list<string> $topics
     *
     * @return int the connections written to
     */
    public function dispatch(array $topics, string $frame, float $now): int
    {
        $targets = [];
        foreach ($topics as $topic) {
            $targets += $this->topics[$topic] ?? [];
        }
        $sent = 0;
        foreach ($targets as $connection) {
            if ($connection->exp <= $now) {
                continue;
            }
            $connection->send($frame, $now);
            ++$sent;
        }

        return $sent;
    }

    /** Topics someone subscribed to. */
    public function topics(): int
    {
        return \count($this->topics);
    }

    /** Whether a connection with a token valid at `$now` listens to a topic. */
    public function listening(string $topic, float $now): bool
    {
        foreach ($this->topics[$topic] ?? [] as $connection) {
            if ($connection->exp > $now) {
                return true;
            }
        }

        return false;
    }
}
