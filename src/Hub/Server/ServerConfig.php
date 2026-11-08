<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

use Janalis\Collaborate\Hub\Transport\WebSocket\Origin;
use Janalis\Collaborate\Hub\Transport\WebSocket\WebSocketTransport;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * What `collaborate:hub:serve` runs with: the `collaborate.hub.websocket` options, the command's overrides and the
 * timings the bundle does not expose (tests shorten them). Checked once built: a misconfigured server never binds.
 */
final readonly class ServerConfig
{
    /** The path upgrades are accepted on. */
    public string $path;

    /** @var list<string> normalized */
    public array $allowedOrigins;

    /** @var list<string> IPs and CIDRs, PRIVATE_SUBNETS expanded */
    public array $trustedProxies;

    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $trustedProxies
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public string $publicUrl,
        public string $topicPrefix,
        ?string $path = null,
        public string $listen = '127.0.0.1:8090',
        public string $publishListen = '127.0.0.1:8091',
        public bool $allowRemotePublish = false,
        public int $socketMode = 0o660,
        array $allowedOrigins = [],
        array $trustedProxies = [],
        public Limits $limits = new Limits(),
        public Timings $timings = new Timings(),
    ) {
        WebSocketTransport::checkPublicUrl($publicUrl);
        $parsed = parse_url($publicUrl, \PHP_URL_PATH);
        $path ??= \is_string($parsed) ? $parsed : '/';
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('collaborate.hub.websocket.path must be an absolute path.');
        }
        $this->path = $path;
        if (self::address($listen) === self::address($publishListen)) {
            throw new \InvalidArgumentException('collaborate.hub.websocket.listen and publish_listen must differ.');
        }
        if (!$allowRemotePublish && !self::local($publishListen)) {
            throw new \InvalidArgumentException(\sprintf(
                'The publish address %s is not a loopback address nor a unix socket: pass --allow-remote-publish, and keep it on a private network.',
                $publishListen,
            ));
        }
        $this->allowedOrigins = array_map(
            static fn(string $origin): string => (
                Origin::normalize($origin) ?? throw new \InvalidArgumentException(\sprintf(
                    'collaborate.hub.websocket.allowed_origins: "%s" is no origin (scheme://host[:port]).',
                    $origin,
                ))
            ),
            $allowedOrigins,
        );
        $proxies = [];
        foreach ($trustedProxies as $proxy) {
            array_push($proxies, ...'PRIVATE_SUBNETS' === $proxy ? IpUtils::PRIVATE_SUBNETS : [$proxy]);
        }
        $this->trustedProxies = $proxies;
    }

    /**
     * @param array<array-key, mixed> $options the `collaborate.hub.websocket` configuration
     *
     * @throws \InvalidArgumentException
     */
    public static function fromOptions(
        array $options,
        string $topicPrefix,
        ?string $listen = null,
        ?string $publishListen = null,
        ?int $shutdownTimeout = null,
        bool $allowRemotePublish = false,
    ): self {
        $string = static fn(string $key): string => (string) self::scalar($options, $key);
        $int = static fn(string $key): int => (int) self::scalar($options, $key);
        $path = self::scalar($options, 'path');

        return new self(
            publicUrl: $string('public_url'),
            topicPrefix: $topicPrefix,
            path: null === $path ? null : (string) $path,
            listen: $listen ?? $string('listen'),
            publishListen: $publishListen ?? $string('publish_listen'),
            allowRemotePublish: $allowRemotePublish,
            socketMode: $int('socket_mode'),
            allowedOrigins: self::strings($options, 'allowed_origins'),
            trustedProxies: self::strings($options, 'trusted_proxies'),
            limits: new Limits(
                maxConnections: $int('max_connections'),
                maxConnectionsPerIp: $int('max_connections_per_ip'),
                maxConnectionsPerUser: $int('max_connections_per_user'),
                maxPending: $int('max_pending'),
                maxMessageSize: $int('max_message_size'),
                maxBuffer: $int('max_buffer'),
            ),
            timings: new Timings(
                pingInterval: $int('ping_interval'),
                shutdownTimeout: $shutdownTimeout ?? $int('shutdown_timeout'),
            ),
        );
    }

    /** The socket path of a `unix://` address; null for host:port. */
    public static function unixPath(string $address): ?string
    {
        return str_starts_with($address, 'unix://') ? substr($address, 7) : null;
    }

    /** Whether only this machine reaches an address: a unix socket or a loopback IP. */
    public static function local(string $address): bool
    {
        if (null !== self::unixPath($address)) {
            return true;
        }
        $host = trim((string) parse_url('tcp://' . self::address($address), \PHP_URL_HOST), '[]');

        return 'localhost' === $host || IpUtils::checkIp($host, ['127.0.0.0/8', '::1']);
    }

    private static function address(string $address): string
    {
        return str_starts_with($address, 'tcp://') ? substr($address, 6) : $address;
    }

    /** @param array<array-key, mixed> $options */
    private static function scalar(array $options, string $key): string|int|float|bool|null
    {
        // @mago-expect analysis:mixed-assignment an option is mixed: the next line narrows it
        $value = $options[$key] ?? null;
        if (null !== $value && !\is_scalar($value)) {
            throw new \InvalidArgumentException(\sprintf('collaborate.hub.websocket.%s must be a scalar.', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return list<string>
     */
    private static function strings(array $options, string $key): array
    {
        // @mago-expect analysis:mixed-assignment an option is mixed: the next line narrows it
        $values = $options[$key] ?? [];
        if (!\is_array($values)) {
            throw new \InvalidArgumentException(\sprintf('collaborate.hub.websocket.%s must be a list.', $key));
        }

        return array_values(array_map(static fn(mixed $value): string => \is_string($value)
            ? $value
            : throw new \InvalidArgumentException(\sprintf(
                'collaborate.hub.websocket.%s must list strings.',
                $key,
            )), $values));
    }
}
