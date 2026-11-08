<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

/** What the server lets browsers use: connections, message and buffer sizes, subscribe messages. */
final readonly class Limits
{
    public function __construct(
        public int $maxConnections = 1000,
        /** 0 for no per-IP limit. */
        public int $maxConnectionsPerIp = 0,
        public int $maxConnectionsPerUser = 50,
        /** Connections that have not subscribed yet. */
        public int $maxPending = 1000,
        /** Bytes of a message a browser sends. */
        public int $maxMessageSize = 16_384,
        /** Bytes a client may lag behind before it is dropped. */
        public int $maxBuffer = 1_048_576,
        /** Subscribe messages a connection may send per {@see Timings::$subscribeWindow}: renewals, `online`, `visibilitychange`. */
        public int $maxSubscribes = 10,
        /** Sockets the application may keep open to the publish side. */
        public int $maxPublishSockets = 64,
    ) {}
}
