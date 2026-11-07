<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification\Push;

use Janalis\Collaborate\Notification\Push\Vapid;
use Minishlink\WebPush\VAPID as LibraryVapid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Vapid::class)]
final class VapidTest extends TestCase
{
    public function testAGeneratedPairIsAP256PairTheLibrarySignsWith(): void
    {
        $keys = Vapid::generate();
        $public = Vapid::decode($keys['publicKey']);
        $private = Vapid::decode($keys['privateKey']);
        self::assertNotNull($public);
        self::assertNotNull($private);
        self::assertSame(65, \strlen($public));
        self::assertSame("\x04", $public[0]);
        self::assertSame(32, \strlen($private));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $keys['publicKey'] . $keys['privateKey']);
        self::assertSame([], Vapid::problems($keys['publicKey'], $keys['privateKey'], 'mailto:ops@example.org'));
        self::assertNotSame($keys, Vapid::generate());
        // The library reads the pair as its own.
        $validated = LibraryVapid::validate(['subject' => 'mailto:ops@example.org'] + $keys);
        self::assertSame($public, $validated['publicKey']);
        self::assertSame($private, $validated['privateKey']);
    }

    public function testAPairTheLibraryMadeChecks(): void
    {
        $keys = LibraryVapid::createVapidKeys();
        self::assertIsString($keys['publicKey'] ?? null);
        self::assertIsString($keys['privateKey'] ?? null);
        self::assertSame([], Vapid::problems($keys['publicKey'], $keys['privateKey'], 'https://example.org/contact'));
    }

    public function testThePublicKeyOfAnotherPairIsRefused(): void
    {
        self::assertSame(
            ['The public key is not the one of the private key.'],
            Vapid::problems(Vapid::generate()['publicKey'], Vapid::generate()['privateKey'], 'mailto:a@b.c'),
        );
    }

    /** @return iterable<string, array{string|null, string|null, string|null, list<string>}> */
    public static function problems(): iterable
    {
        $keys = Vapid::generate();
        $point = 'The public key is not the base64url of an uncompressed P-256 point (65 bytes).';
        $scalar = 'The private key is not the base64url of a P-256 scalar (32 bytes).';
        $subject = 'The subject is no mailto: or https: URL.';
        yield 'nothing' => [null, null, null, [$point, $scalar, $subject]];
        yield 'not base64url' => ['a+b', 'a/b', 'mailto:a@b.c', [$point, $scalar]];
        yield 'a compressed point' => [
            Vapid::encode("\x02" . str_repeat('a', 64)),
            $keys['privateKey'],
            'mailto:a@b.c',
            [$point],
        ];
        yield 'a short point' => [
            Vapid::encode("\x04" . str_repeat('a', 63)),
            $keys['privateKey'],
            'mailto:a@b.c',
            [$point],
        ];
        yield 'a long scalar' => [$keys['publicKey'], Vapid::encode(str_repeat('a', 33)), 'mailto:a@b.c', [$scalar]];
        yield 'a short scalar' => [$keys['publicKey'], Vapid::encode(str_repeat('a', 31)), 'mailto:a@b.c', [$scalar]];
        yield 'a scalar of zero' => [
            $keys['publicKey'],
            Vapid::encode(str_repeat("\0", 32)),
            'mailto:a@b.c',
            ['The public key is not the one of the private key.'],
        ];
        yield 'a scalar off the curve' => [
            $keys['publicKey'],
            Vapid::encode(str_repeat("\xff", 32)),
            'mailto:a@b.c',
            ['The public key is not the one of the private key.'],
        ];
        yield 'an http subject' => [$keys['publicKey'], $keys['privateKey'], 'http://example.org', [$subject]];
        yield 'an address without mailto' => [$keys['publicKey'], $keys['privateKey'], 'ops@example.org', [$subject]];
        yield 'a mailto without address' => [$keys['publicKey'], $keys['privateKey'], 'mailto:ops', [$subject]];
        yield 'a mailto with a space' => [
            $keys['publicKey'],
            $keys['privateKey'],
            'mailto:o ps@example.org',
            [$subject],
        ];
        yield 'a mailto with a line after it' => [
            $keys['publicKey'],
            $keys['privateKey'],
            "mailto:ops@example.org\n",
            [$subject],
        ];
        yield 'an https url that is none' => [$keys['publicKey'], $keys['privateKey'], 'https://', [$subject]];
    }

    /** @param list<string> $expected */
    #[DataProvider('problems')]
    public function testSaysWhatIsWrong(?string $public, ?string $private, ?string $subject, array $expected): void
    {
        self::assertSame($expected, Vapid::problems($public, $private, $subject));
    }

    public function testDecodesBase64UrlAlone(): void
    {
        self::assertSame("\xfb\xff", Vapid::decode('-_8'));
        self::assertSame('-_8', Vapid::encode("\xfb\xff"));
        self::assertNull(Vapid::decode(''));
        self::assertNull(Vapid::decode('ab=c'));
        self::assertNull(Vapid::decode("ab\n"));
        self::assertNull(Vapid::decode('+abc'));
        // A length base64 cannot end on.
        self::assertNull(Vapid::decode('a'));
    }

    public function testAShortCoordinateIsPadded(): void
    {
        // About one key in 256 has a coordinate whose first byte is zero, which OpenSSL leaves out.
        for ($index = 0; $index < 600; ++$index) {
            $keys = Vapid::generate();
            self::assertSame(65, \strlen((string) Vapid::decode($keys['publicKey'])));
            self::assertSame(32, \strlen((string) Vapid::decode($keys['privateKey'])));
        }
    }

    public function testSaysWhatOpenSslSaidWhenItMadeNoKey(): void
    {
        // A key OpenSSL could not read leaves what it said in its queue of errors.
        self::assertFalse(openssl_pkey_get_private('not a key'));
        try {
            Vapid::pair(false);
            self::fail('A failure of OpenSSL is no pair.');
        } catch (\RuntimeException $exception) {
            self::assertStringStartsWith('OpenSSL could not make a P-256 key: ', $exception->getMessage());
            self::assertGreaterThan(\strlen('OpenSSL could not make a P-256 key: '), \strlen($exception->getMessage()));
        }
    }

    public function testAKeyOfAnotherTypeIsNoPair(): void
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
        self::assertNotFalse($key);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenSSL could not make a P-256 key: ');
        Vapid::pair($key);
    }

    public function testAPublicKeyAloneIsNoPair(): void
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key'] ?? null);
        $public = openssl_pkey_get_public($details['key']);
        self::assertNotFalse($public);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenSSL could not make a P-256 key: ');
        Vapid::pair($public);
    }

    public function testTellsASubjectThePushServicesMayContact(): void
    {
        self::assertTrue(Vapid::isSubject('mailto:ops@example.org'));
        self::assertTrue(Vapid::isSubject('https://example.org/contact'));
        self::assertFalse(Vapid::isSubject('http://example.org'));
        self::assertFalse(Vapid::isSubject(null));
    }
}
