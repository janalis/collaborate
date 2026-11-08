<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The address the per-IP limits count a browser under: the peer, unless it is a trusted proxy (a unix socket
 * peer always is one); then `X-Forwarded-For` read right to left, up to the first hop no trusted proxy added. No
 * other forwarding header is read: one family only, so no header can contradict another.
 *
 * An IPv6 client is counted by its /64, which one host gets whole. A trusted proxy that names no client counts
 * its requests under its own address, a unix proxy under `unix`: the limits never silently stop applying.
 */
final readonly class ClientAddress
{
    /** What the clients of a unix socket proxy naming none are counted under. */
    public const string UNIX = 'unix';

    /** @param list<string> $trustedProxies IPs and CIDRs */
    public function __construct(
        private array $trustedProxies,
    ) {}

    /** What the limits count a request under. */
    public function resolve(ServerRequestInterface $request): string
    {
        // @mago-expect analysis:mixed-assignment a server parameter is mixed: the next line narrows it
        $peer = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $peer = \is_string($peer) ? trim($peer, '[]') : null;
        if (null !== $peer && !$this->trusted($peer)) {
            return self::key($peer);
        }
        $hops = array_map(trim(...), explode(',', implode(',', $request->getHeader('X-Forwarded-For'))));
        $address = $peer;
        foreach (array_reverse($hops) as $hop) {
            if (false === filter_var($hop, \FILTER_VALIDATE_IP)) {
                break;
            }
            $address = $hop;
            if (!$this->trusted($hop)) {
                break;
            }
        }

        return null === $address ? self::UNIX : self::key($address);
    }

    /**
     * What a socket is counted under before its request is read: its peer's address, unless the peer is a
     * trusted proxy or a unix socket (null: its requests name their clients).
     */
    public function peer(?string $remote): ?string
    {
        $host = trim((string) parse_url((string) $remote, \PHP_URL_HOST), '[]');
        if (!str_starts_with((string) $remote, 'tcp://') || $this->trusted($host)) {
            return null;
        }

        return self::key($host);
    }

    private function trusted(string $ip): bool
    {
        return IpUtils::checkIp($ip, $this->trustedProxies);
    }

    /** An IPv4 address as is, an IPv6 one as its /64. */
    private static function key(string $ip): string
    {
        return IpUtils::anonymize($ip, 0, 8);
    }
}
