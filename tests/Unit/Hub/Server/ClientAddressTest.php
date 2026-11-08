<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Server;

use Janalis\Collaborate\Hub\Server\ClientAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

#[CoversClass(ClientAddress::class)]
final class ClientAddressTest extends TestCase
{
    /** @return iterable<string, array{?string, list<string>, string}> */
    public static function requests(): iterable
    {
        yield 'a direct client' => ['203.0.113.7', [], '203.0.113.7'];
        yield 'forwarding headers of an untrusted peer are ignored' => ['203.0.113.7', ['198.51.100.1'], '203.0.113.7'];
        yield 'a trusted proxy names its client' => ['10.0.0.2', ['198.51.100.1'], '198.51.100.1'];
        yield 'hops a client wrote are not believed' => ['10.0.0.2', ['1.1.1.1, 198.51.100.1'], '198.51.100.1'];
        yield 'chained trusted proxies are skipped' => [
            '10.0.0.2',
            ['198.51.100.1, 10.0.0.9', '10.0.0.3'],
            '198.51.100.1',
        ];
        yield 'every hop trusted: the first' => ['10.0.0.2', ['10.0.0.8, 10.0.0.9'], '10.0.0.8'];
        yield 'a trusted proxy without header' => ['10.0.0.2', [], '10.0.0.2'];
        yield 'a malformed hop ends the walk' => ['10.0.0.2', ['198.51.100.1, garbage, 10.0.0.9'], '10.0.0.9'];
        yield 'a malformed last hop' => ['10.0.0.2', ['garbage'], '10.0.0.2'];
        yield 'a unix peer is a trusted proxy' => [null, ['198.51.100.1'], '198.51.100.1'];
        yield 'a unix peer without header: counted under unix' => [null, [], 'unix'];
        yield 'a unix peer with a malformed header' => [null, ['garbage'], 'unix'];
        yield 'an IPv6 peer, by its /64' => ['[2001:db8:0:1:ff00:bb:cc:dd]', [], '2001:db8:0:1::'];
        yield 'an IPv6 client of a proxy, by its /64' => ['10.0.0.2', ['2001:db8:0:2:1::5'], '2001:db8:0:2::'];
        yield 'an IPv4 address whole' => ['203.0.113.255', [], '203.0.113.255'];
    }

    /** @param list<string> $forwarded */
    #[DataProvider('requests')]
    public function testResolvesTheClient(?string $peer, array $forwarded, string $expected): void
    {
        $request = new ServerRequest(
            'GET',
            'http://localhost/hub',
            ['X-Forwarded-For' => $forwarded],
            '',
            '1.1',
            null === $peer ? [] : ['REMOTE_ADDR' => $peer],
        );

        self::assertSame($expected, new ClientAddress(['10.0.0.0/8'])->resolve($request));
    }

    public function testATrustedIpv6ProxyIsMatchedWithoutBrackets(): void
    {
        $request = new ServerRequest('GET', 'http://localhost/hub', ['X-Forwarded-For' => '198.51.100.1'], '', '1.1', [
            'REMOTE_ADDR' => '[::1]',
        ]);

        self::assertSame('198.51.100.1', new ClientAddress(['::1'])->resolve($request));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function peers(): iterable
    {
        yield 'an untrusted IPv4 peer' => ['tcp://203.0.113.7:5000', '203.0.113.7'];
        yield 'an untrusted IPv6 peer, by its /64' => ['tcp://[2001:db8:0:1::7]:5000', '2001:db8:0:1::'];
        yield 'a trusted proxy' => ['tcp://10.0.0.2:5000', null];
        yield 'a unix peer' => ['unix:///run/hub.sock', null];
        yield 'an unknown peer' => [null, null];
    }

    #[DataProvider('peers')]
    public function testNamesThePeerOfASocketBeforeItsRequest(?string $remote, ?string $expected): void
    {
        self::assertSame($expected, new ClientAddress(['10.0.0.0/8'])->peer($remote));
    }
}
