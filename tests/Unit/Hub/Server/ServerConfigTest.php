<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Server;

use Janalis\Collaborate\Hub\Server\Limits;
use Janalis\Collaborate\Hub\Server\ServerConfig;
use Janalis\Collaborate\Hub\Server\Timings;
use Janalis\Collaborate\Hub\Transport\WebSocket\Origin;
use Janalis\Collaborate\Hub\Transport\WebSocket\WebSocketTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\IpUtils;

#[CoversClass(ServerConfig::class)]
#[CoversClass(Limits::class)]
#[CoversClass(Timings::class)]
#[UsesClass(Origin::class)]
#[UsesClass(WebSocketTransport::class)]
final class ServerConfigTest extends TestCase
{
    /** The bundle's defaults, as `collaborate.hub.websocket` holds them. */
    private const array OPTIONS = [
        'public_url' => '/collaborate-hub',
        'path' => null,
        'publish_url' => 'http://127.0.0.1:8091',
        'publish_socket' => null,
        'listen' => '127.0.0.1:8090',
        'publish_listen' => '127.0.0.1:8091',
        'socket_mode' => 0o660,
        'secret' => null,
        'previous_secret' => null,
        'allowed_origins' => [],
        'trusted_proxies' => [],
        'ping_interval' => 25,
        'max_connections' => 10_000,
        'max_connections_per_ip' => 0,
        'max_connections_per_user' => 50,
        'max_pending' => 1000,
        'max_message_size' => 16_384,
        'max_buffer' => 1_048_576,
        'shutdown_timeout' => 10,
    ];

    public function testReadsTheBundleOptions(): void
    {
        $config = ServerConfig::fromOptions([
            'path' => '/ws',
            'socket_mode' => 0o600,
            'allowed_origins' => ['HTTPS://App.Example:443'],
            'trusted_proxies' => ['10.1.0.0/16', 'PRIVATE_SUBNETS'],
            'ping_interval' => 20,
            'max_connections' => 11,
            'max_connections_per_ip' => 12,
            'max_connections_per_user' => 13,
            'max_pending' => 14,
            'max_message_size' => 15,
            'max_buffer' => 16,
            'shutdown_timeout' => 17,
        ] + self::OPTIONS, 'urn:p:');

        self::assertSame('/collaborate-hub', $config->publicUrl);
        self::assertSame('urn:p:', $config->topicPrefix);
        self::assertSame('/ws', $config->path);
        self::assertSame('127.0.0.1:8090', $config->listen);
        self::assertSame('127.0.0.1:8091', $config->publishListen);
        self::assertFalse($config->allowRemotePublish);
        self::assertSame(0o600, $config->socketMode);
        self::assertSame(['https://app.example'], $config->allowedOrigins);
        self::assertSame(['10.1.0.0/16', ...IpUtils::PRIVATE_SUBNETS], $config->trustedProxies);
        self::assertEquals(new Limits(11, 12, 13, 14, 15, 16), $config->limits);
        self::assertEquals(new Timings(pingInterval: 20.0, shutdownTimeout: 17.0), $config->timings);
    }

    public function testTheCommandOverridesAddressesAndTheShutdownTimeout(): void
    {
        $config = ServerConfig::fromOptions(self::OPTIONS, 'urn:p:', 'unix:///run/hub.sock', '0.0.0.0:9000', 3, true);

        self::assertSame('unix:///run/hub.sock', $config->listen);
        self::assertSame('0.0.0.0:9000', $config->publishListen);
        self::assertTrue($config->allowRemotePublish);
        self::assertSame(3.0, $config->timings->shutdownTimeout);
    }

    public function testDefaults(): void
    {
        $config = new ServerConfig('/hub', 'urn:p:');

        self::assertSame('127.0.0.1:8090', $config->listen);
        self::assertSame('127.0.0.1:8091', $config->publishListen);
        self::assertSame(0o660, $config->socketMode);
        self::assertSame([], $config->allowedOrigins);
        self::assertSame([], $config->trustedProxies);
        self::assertEquals(new Limits(1000, 0, 50, 1000, 16_384, 1_048_576, 10, 64), $config->limits);
        self::assertEquals(new Timings(25.0, 10.0, 10.0, 10.0, 5.0, 1.0, 300.0, 60.0, 2.0, 30.0), $config->timings);
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function paths(): iterable
    {
        yield 'a relative public URL' => ['/collaborate-hub', null, '/collaborate-hub'];
        yield 'an absolute one' => ['wss://hub.example/ws?x=1', null, '/ws'];
        yield 'an absolute one without path' => ['wss://hub.example', null, '/'];
        yield 'an explicit path' => ['/collaborate-hub', '/hub', '/hub'];
    }

    #[DataProvider('paths')]
    public function testThePathDefaultsToThatOfThePublicUrl(string $publicUrl, ?string $path, string $expected): void
    {
        self::assertSame($expected, new ServerConfig($publicUrl, 'urn:p:', $path)->path);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'a public URL in clear' => [['public_url' => 'ws://hub.example/ws'], 'public_url'];
        yield 'a relative path' => [['path' => 'hub'], 'must be an absolute path'];
        yield 'one address for both sides' => [['publish_listen' => 'tcp://127.0.0.1:8090'], 'must differ'];
        yield 'a remote publish address' => [['publish_listen' => '0.0.0.0:8091'], '--allow-remote-publish'];
        yield 'a private publish address' => [['publish_listen' => '10.0.0.1:8091'], '--allow-remote-publish'];
        yield 'a publish host name' => [['publish_listen' => 'hub.internal:8091'], '--allow-remote-publish'];
        yield 'no origin' => [['allowed_origins' => ['null']], '"null" is no origin'];
        yield 'an option of the wrong kind' => [
            ['listen' => ['127.0.0.1:1']],
            'collaborate.hub.websocket.listen must be a scalar',
        ];
        yield 'origins not a list' => [['allowed_origins' => 'https://a.example'], 'allowed_origins must be a list'];
        yield 'an origin not a string' => [['allowed_origins' => [1]], 'allowed_origins must list strings'];
        yield 'a proxy not a string' => [['trusted_proxies' => [1]], 'trusted_proxies must list strings'];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalid')]
    public function testRefuses(array $options, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        ServerConfig::fromOptions($options + self::OPTIONS, 'urn:p:');
    }

    /** @return iterable<string, array{string}> */
    public static function local(): iterable
    {
        yield 'a unix socket' => ['unix:///run/hub.sock'];
        yield 'loopback' => ['127.0.0.1:8091'];
        yield 'another loopback address' => ['tcp://127.9.0.1:8091'];
        yield 'IPv6 loopback' => ['[::1]:8091'];
        yield 'localhost' => ['localhost:8091'];
    }

    #[DataProvider('local')]
    public function testAcceptsALocalPublishAddress(string $address): void
    {
        self::assertTrue(ServerConfig::local($address));
        self::assertSame($address, new ServerConfig('/hub', 'urn:p:', publishListen: $address)->publishListen);
    }

    public function testListsOfOptionsAreReindexed(): void
    {
        $config = ServerConfig::fromOptions(['allowed_origins' => ['a' => 'https://a.example']]
        + self::OPTIONS, 'urn:p:');

        self::assertSame(['https://a.example'], $config->allowedOrigins);
        self::assertSame(
            [],
            ServerConfig::fromOptions(['allowed_origins' => null] + self::OPTIONS, 'urn:p:')->allowedOrigins,
        );
    }

    public function testOptionsOfAnotherScalarTypeAreCast(): void
    {
        $config = ServerConfig::fromOptions(['max_connections' => '11', 'listen' => 8090] + self::OPTIONS, 'urn:p:');

        self::assertSame(11, $config->limits->maxConnections);
        self::assertSame('8090', $config->listen);
        $this->expectExceptionMessage('must be an absolute path');
        ServerConfig::fromOptions(['path' => 5] + self::OPTIONS, 'urn:p:');
    }

    public function testARemotePublishAddressNeedsToBeAllowed(): void
    {
        $this->expectExceptionMessage('--allow-remote-publish');
        new ServerConfig('/hub', 'urn:p:', publishListen: '0.0.0.0:8091');
    }

    public function testNamesTheSocketPathOfAUnixAddress(): void
    {
        self::assertSame('/run/hub.sock', ServerConfig::unixPath('unix:///run/hub.sock'));
        self::assertNull(ServerConfig::unixPath('127.0.0.1:8090'));
        self::assertNull(ServerConfig::unixPath('/run/unix://x'));
    }
}
