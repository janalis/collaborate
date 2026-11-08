<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Transport\WebSocket;

use Janalis\Collaborate\Hub\Transport\WebSocket\Origin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Origin::class)]
final class OriginTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function origins(): iterable
    {
        yield 'https' => ['https://example.com', 'https://example.com'];
        yield 'uppercase' => ['HTTPS://Example.COM', 'https://example.com'];
        yield 'default https port' => ['https://example.com:443', 'https://example.com'];
        yield 'default http port' => ['http://example.com:80', 'http://example.com'];
        yield 'other port' => ['https://example.com:8443', 'https://example.com:8443'];
        yield 'http on https port' => ['http://example.com:443', 'http://example.com:443'];
        yield 'trailing slash' => ['https://example.com/', 'https://example.com'];
        yield 'ipv6' => ['http://[::1]:8000', 'http://[::1]:8000'];
        yield 'opaque' => ['null', null];
        yield 'empty' => ['', null];
        yield 'file' => ['file:///etc/passwd', null];
        yield 'other scheme' => ['chrome-extension://abc', null];
        yield 'path' => ['https://example.com/admin', null];
        yield 'query' => ['https://example.com?x=1', null];
        yield 'fragment' => ['https://example.com#x', null];
        yield 'credentials' => ['https://user:pass@example.com', null];
        yield 'no host' => ['https:', null];
        yield 'malformed' => ['http://:80', null];
    }

    #[DataProvider('origins')]
    public function testNormalizesAWebOriginAndRefusesAnythingElse(string $origin, ?string $normalized): void
    {
        self::assertSame($normalized, Origin::normalize($origin));
    }
}
