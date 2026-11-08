<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

use Janalis\Collaborate\Cursor\CursorEvents;

/**
 * Cursor events pages send over their socket: checked and stamped as `POST /cursors/events` would, then relayed
 * to their room at once. A page may send twice the client throttle's rate, in bursts as large.
 */
final readonly class Cursors
{
    public function __construct(
        public CursorEvents $events,
        /** Bytes of one event, encoded. */
        public int $maxEventBytes,
        /** Milliseconds between two events of a page, as the client throttles them. */
        public int $throttle,
    ) {}

    /** Events a page may send per second, and in one burst. */
    public function rate(): float
    {
        return (2 * 1000) / $this->throttle;
    }
}
