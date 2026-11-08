<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Transport\WebSocket;

/**
 * A web origin as browsers send it in `Origin`, compared exactly once normalized: lowercase scheme and host, the
 * default port dropped. `null` (opaque origins: sandboxed frames, `file:`) and anything else is no origin.
 */
final class Origin
{
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public static function normalize(string $origin): ?string
    {
        $parts = parse_url($origin);
        if (
            !\is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !\array_key_exists(strtolower($parts['scheme']), self::DEFAULT_PORTS)
            || [] !== array_diff(array_keys($parts), ['scheme', 'host', 'port', 'path'])
            || !\in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $port = null === $port || self::DEFAULT_PORTS[$scheme] === $port ? '' : ':' . $port;

        return $scheme . '://' . strtolower($parts['host']) . $port;
    }
}
