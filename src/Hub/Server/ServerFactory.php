<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

use Janalis\Collaborate\Hub\Transport\WebSocket\RequestSigner;
use Janalis\Collaborate\Hub\Transport\WebSocket\SubscriberToken;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use Symfony\Component\Clock\NativeClock;

/** Builds the hub for a loop. Nothing here is a container service: the bundle loads without ReactPHP. */
final class ServerFactory
{
    /** Sockets stream_select() watches safely: FD_SETSIZE (1024) less the listeners, stdio and logs. */
    public const int SELECT_SOCKETS = 1000;

    public static function create(
        LoopInterface $loop,
        ServerConfig $config,
        SubscriberToken $tokens,
        RequestSigner $signer,
        LoggerInterface $logger = new NullLogger(),
        ClockInterface $clock = new NativeClock(),
        ?Cursors $cursors = null,
    ): Server {
        $router = new Router();
        $browserSockets = new Sockets($clock);
        $publishSockets = new Sockets($clock);
        $limits = $config->limits;

        return new Server(
            $loop,
            $config,
            new BrowserEndpoint(
                $config,
                $tokens,
                $router,
                new ClientAddress($config->trustedProxies),
                $browserSockets,
                $clock,
                self::perIp($config, $logger),
                self::browserSockets($loop, $limits, $logger),
                $cursors,
            ),
            $browserSockets,
            new PublishEndpoint(
                $signer,
                $router,
                $config->topicPrefix,
                $clock,
                $publishSockets,
                $limits->maxPublishSockets,
            ),
            $router,
            $publishSockets,
            $clock,
            $logger,
        );
    }

    /**
     * Sockets the browser side holds at most: its connections and those still sending their request. On
     * stream_select() they share {@see SELECT_SOCKETS} with the publish side's.
     */
    private static function browserSockets(LoopInterface $loop, Limits $limits, LoggerInterface $logger): int
    {
        $sockets = $limits->maxConnections + $limits->maxPending;
        $budget = self::SELECT_SOCKETS - $limits->maxPublishSockets;
        if (!$loop instanceof StreamSelectLoop || $sockets <= $budget) {
            return $sockets;
        }
        $logger->warning(
            'stream_select() watches {budget} browser sockets at most, under the {sockets} of max_connections and max_pending: install ext-ev or ext-event to hold more.',
            ['budget' => $budget, 'sockets' => $sockets],
        );

        return $budget;
    }

    /**
     * Whether the per-IP limit can tell clients apart. Behind a local proxy no trusted_proxies names, every
     * client has the proxy's address: one limit for all would lock everyone out.
     */
    private static function perIp(ServerConfig $config, LoggerInterface $logger): bool
    {
        if (0 === $config->limits->maxConnectionsPerIp) {
            return false;
        }
        if (
            null === ServerConfig::unixPath($config->listen)
            && [] === $config->trustedProxies
            && ServerConfig::local($config->listen)
        ) {
            $logger->warning(
                'max_connections_per_ip is off: every client connects through a local proxy. Name it in trusted_proxies to count its clients apart.',
            );

            return false;
        }

        return true;
    }
}
