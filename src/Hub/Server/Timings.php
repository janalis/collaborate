<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

/** Seconds the server waits for things, and how often its shared timer runs. Tests shorten them. */
final readonly class Timings
{
    public function __construct(
        /** Between pings; a client unheard of for two is dropped. */
        public float $pingInterval = 25.0,
        /** Clients have to close on shutdown. */
        public float $shutdownTimeout = 10.0,
        /** A new connection has to subscribe. */
        public float $subscribeTimeout = 10.0,
        /** A client may stay congested before it is dropped. */
        public float $congestionTimeout = 10.0,
        /** A client has to send its request headers. */
        public float $headerTimeout = 5.0,
        /** Between two runs of the shared timer. */
        public float $tickInterval = 1.0,
        /** Between two counter logs. */
        public float $statsInterval = 300.0,
        /** The window {@see Limits::$maxSubscribes} counts over. */
        public float $subscribeWindow = 60.0,
        /** A closing client has to read its close frame before its socket is closed under it. */
        public float $closeTimeout = 2.0,
        /** A publish socket may stay idle between two requests. */
        public float $idleTimeout = 30.0,
    ) {}
}
