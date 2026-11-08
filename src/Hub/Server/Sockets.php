<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

use Psr\Clock\ClockInterface;
use React\Socket\ConnectionInterface;

/**
 * The sockets one listener accepted. react/http gives a request handler no socket and reads forever: this
 * tracks them, closes those that took too long to send their first headers or stayed idle too long, counts
 * those still sending headers per peer, and names the socket whose data is being parsed (a request handler
 * runs within it).
 */
final class Sockets
{
    /** The socket whose data is being read. */
    private ?ConnectionInterface $current = null;

    /** @var \SplObjectStorage<ConnectionInterface, float> when each last sent data */
    private \SplObjectStorage $open;

    /** @var \SplObjectStorage<ConnectionInterface, array{float, ?string}> accepted at, and from which peer, while they send their first headers */
    private \SplObjectStorage $waiting;

    private readonly Counter $peers;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
        /** @var \SplObjectStorage<ConnectionInterface, float> $open */
        $open = new \SplObjectStorage();
        /** @var \SplObjectStorage<ConnectionInterface, array{float, ?string}> $waiting */
        $waiting = new \SplObjectStorage();
        $this->open = $open;
        $this->waiting = $waiting;
        $this->peers = new Counter();
    }

    /**
     * Tracks a socket a listener accepted, before the HTTP server reads it.
     *
     * @param ?string $peer the address it is counted under while it sends its first headers; null for none
     */
    public function accept(ConnectionInterface $socket, ?string $peer = null): void
    {
        $now = Seconds::of($this->clock);
        $this->open->offsetSet($socket, $now);
        $this->waiting->offsetSet($socket, [$now, $peer]);
        if (null !== $peer) {
            $this->peers->add($peer);
        }
        $socket->on('data', function () use ($socket): void {
            $this->current = $socket;
            if ($this->open->offsetExists($socket)) {
                $this->open->offsetSet($socket, Seconds::of($this->clock));
            }
        });
        $socket->on('close', function () use ($socket): void {
            $this->release($socket);
        });
    }

    /** The socket of the request being handled, its headers read. */
    public function claim(): ConnectionInterface
    {
        $socket = $this->current ?? throw new \LogicException('No socket is being read.');
        $this->settle($socket);

        return $socket;
    }

    /** Stops tracking a socket: it closed, or another owns it now. */
    public function release(ConnectionInterface $socket): void
    {
        $this->open->offsetUnset($socket);
        $this->settle($socket);
    }

    /** Closes the sockets that did not send their first headers within `$timeout` seconds. */
    public function expire(float $now, float $timeout): void
    {
        foreach (iterator_to_array($this->waiting) as $socket) {
            if (($now - $this->waiting[$socket][0]) < $timeout) {
                continue;
            }
            $socket->close();
        }
    }

    /** Closes the sockets that sent nothing for `$timeout` seconds. */
    public function idle(float $now, float $timeout): void
    {
        foreach (iterator_to_array($this->open) as $socket) {
            if (($now - $this->open[$socket]) < $timeout) {
                continue;
            }
            $socket->close();
        }
    }

    public function closeAll(): void
    {
        foreach (iterator_to_array($this->open) as $socket) {
            $socket->close();
        }
    }

    public function count(): int
    {
        return \count($this->open);
    }

    /** Sockets of a peer still sending their first headers. */
    public function waiting(string $peer): int
    {
        return $this->peers->count($peer);
    }

    /** Its first headers arrived: no deadline for them any more. */
    private function settle(ConnectionInterface $socket): void
    {
        if (!$this->waiting->offsetExists($socket)) {
            return;
        }
        $peer = $this->waiting[$socket][1];
        $this->waiting->offsetUnset($socket);
        if (null !== $peer) {
            $this->peers->remove($peer);
        }
    }
}
