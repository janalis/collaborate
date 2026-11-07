<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification\Push;

use Janalis\Collaborate\Notification\Push\PushEndpoints;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The server posts to every endpoint its users saved: only a push service's is one. */
#[CoversClass(PushEndpoints::class)]
final class PushEndpointsTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function endpoints(): iterable
    {
        yield 'chrome' => ['https://fcm.googleapis.com/fcm/send/abc:APA91b', true];
        yield 'firefox' => ['https://updates.push.services.mozilla.com/wpush/v2/gAAAA', true];
        yield 'safari' => ['https://web.push.apple.com/QH6c', true];
        yield 'legacy edge' => ['https://wns2-par02p.notify.windows.com/w/?token=x', true];
        yield 'the default port named' => ['https://fcm.googleapis.com:443/fcm/send/x', true];
        yield 'a host written in capitals' => ['https://FCM.googleapis.COM/fcm/send/x', true];
        yield 'plain http' => ['http://fcm.googleapis.com/fcm/send/x', false];
        yield 'another port' => ['https://fcm.googleapis.com:8443/fcm/send/x', false];
        yield 'credentials' => ['https://user:pass@fcm.googleapis.com/fcm/send/x', false];
        yield 'a user alone' => ['https://user@fcm.googleapis.com/fcm/send/x', false];
        yield 'the metadata of a cloud instance' => ['https://169.254.169.254/latest/meta-data/', false];
        yield 'the loopback' => ['https://127.0.0.1/admin', false];
        yield 'an internal name' => ['https://intranet.local/x', false];
        yield 'a host ending like one' => ['https://evilfcm.googleapis.com.attacker.test/x', false];
        yield 'a suffix without its dot' => ['https://notfcm.googleapis.com/x', false];
        yield 'no host' => ['https:///x', false];
        yield 'no url' => ['fcm.googleapis.com', false];
        yield 'nothing' => ['', false];
    }

    #[DataProvider('endpoints')]
    public function testAllowsThePushServicesAlone(string $endpoint, bool $allowed): void
    {
        self::assertSame($allowed, new PushEndpoints()->allows($endpoint));
    }

    public function testAHostMayNameItsOwnServices(): void
    {
        $endpoints = new PushEndpoints(['Push.Example.ORG']);
        self::assertTrue($endpoints->allows('https://push.example.org/x'));
        self::assertTrue($endpoints->allows('https://eu.push.example.org/x'));
        self::assertFalse($endpoints->allows('https://fcm.googleapis.com/fcm/send/x'));
        self::assertSame(
            ['fcm.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'],
            PushEndpoints::HOSTS,
        );
    }
}
