<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Transport\WebSocket;

use Janalis\Collaborate\Hub\Transport\WebSocket\Keys;
use Janalis\Collaborate\Hub\Transport\WebSocket\RequestSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestSigner::class)]
#[UsesClass(Keys::class)]
final class RequestSignerTest extends TestCase
{
    private const string SECRET = 'a-secret-of-thirty-two-bytes-ok!';
    private const string PREVIOUS = 'the-previous-secret-of-32-bytes!';
    private const int NOW = 1_800_000_000;
    private const string NONCE = '0123456789abcdef0123456789abcdef';

    public function testSignsTheTimeTheMethodTheTargetAndTheBodyWithThePublishKey(): void
    {
        $keys = new Keys(self::SECRET);
        $header = new RequestSigner($keys)->sign('post', '/publish', '{"a":1}', self::NOW, self::NONCE);

        self::assertSame(
            't=' . self::NOW . ',n=' . self::NONCE . ',s='
                . hash_hmac(
                    'sha256',
                    self::NOW . "\n" . self::NONCE . "\nPOST\n/publish\n" . hash('sha256', '{"a":1}'),
                    $keys->publishKey(),
                ),
            $header,
        );
        self::assertSame('X-Collaborate-Signature', RequestSigner::HEADER);
    }

    public function testEachSignatureCarriesARandomNonceSoIdenticalRequestsOfOneSecondDiffer(): void
    {
        $signer = new RequestSigner(new Keys(self::SECRET));
        $first = $signer->sign('GET', '/subscriptions?topic=t', '', self::NOW);
        $second = $signer->sign('GET', '/subscriptions?topic=t', '', self::NOW);

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^t=' . self::NOW . ',n=[0-9a-f]{32},s=[0-9a-f]{64}$/D', $first);
        self::assertTrue($signer->verify($first, 'GET', '/subscriptions?topic=t', '', self::NOW));
        self::assertTrue($signer->verify($second, 'GET', '/subscriptions?topic=t', '', self::NOW));
        // The nonce is signed: another one does not verify.
        $swapped = preg_replace('/n=[0-9a-f]{32}/', 'n=' . self::NONCE, $first);
        self::assertIsString($swapped);
        self::assertFalse($signer->verify($swapped, 'GET', '/subscriptions?topic=t', '', self::NOW));
    }

    public function testVerifiesWhatItSignedWithinTheWindowOnly(): void
    {
        $signer = new RequestSigner(new Keys(self::SECRET));
        $header = $signer->sign('POST', '/publish', 'body', self::NOW);

        self::assertTrue($signer->verify($header, 'POST', '/publish', 'body', self::NOW));
        self::assertTrue($signer->verify($header, 'post', '/publish', 'body', self::NOW + 60));
        self::assertTrue($signer->verify($header, 'POST', '/publish', 'body', self::NOW - 60));
        self::assertFalse($signer->verify($header, 'POST', '/publish', 'body', self::NOW + 61));
        self::assertFalse($signer->verify($header, 'POST', '/publish', 'body', self::NOW - 61));
        self::assertFalse($signer->verify($header, 'GET', '/publish', 'body', self::NOW));
        self::assertFalse($signer->verify($header, 'POST', '/publish?x', 'body', self::NOW));
        self::assertFalse($signer->verify($header, 'POST', '/publish', 'body!', self::NOW));
    }

    public function testRefusesAnotherKeyAndMalformedHeaders(): void
    {
        $signer = new RequestSigner(new Keys(self::SECRET));
        $other = new RequestSigner(new Keys(self::PREVIOUS))->sign('GET', '/subscriptions', '', self::NOW);
        $valid = $signer->sign('GET', '/subscriptions', '', self::NOW, self::NONCE);
        self::assertTrue($signer->verify($valid, 'GET', '/subscriptions', '', self::NOW));

        self::assertFalse($signer->verify($other, 'GET', '/subscriptions', '', self::NOW));
        foreach ([
            '',
            ' ' . $valid,
            $valid . ' ',
            $valid . "\n",
            strtoupper($valid),
            str_replace('t=', 'T=', $valid),
            't=,n=' . self::NONCE . ',s=' . substr($valid, -64),
            't=1234567890123,n=' . self::NONCE . ',s=' . substr($valid, -64),
            't=' . self::NOW . ',s=' . substr($valid, -64),
            't=' . self::NOW . ',n=' . substr(self::NONCE, 1) . ',s=' . substr($valid, -64),
            't=' . self::NOW . ',n=' . strtoupper(self::NONCE) . ',s=' . substr($valid, -64),
            't=' . self::NOW . ',n=' . self::NONCE . ',s=' . substr($valid, -63),
            't=' . self::NOW . ',n=' . self::NONCE . ',s=' . substr($valid, -64) . '0',
            't=' . self::NOW . ';n=' . self::NONCE . ',s=' . substr($valid, -64),
            // The same time written otherwise would be another header for the same signature: a replay.
            't=0' . self::NOW . ',n=' . self::NONCE . ',s=' . substr($valid, -64),
            't=+' . self::NOW . ',n=' . self::NONCE . ',s=' . substr($valid, -64),
        ] as $header) {
            self::assertFalse($signer->verify($header, 'GET', '/subscriptions', '', self::NOW), $header);
        }
    }

    public function testAServerRotatingItsSecretAcceptsBothWhileTheApplicationSignsWithTheNewOne(): void
    {
        $server = new RequestSigner(new Keys(self::SECRET, self::PREVIOUS));
        $old = new RequestSigner(new Keys(self::PREVIOUS))->sign('POST', '/publish', '', self::NOW);
        $new = new RequestSigner(new Keys(self::SECRET))->sign('POST', '/publish', '', self::NOW);

        self::assertTrue($server->verify($old, 'POST', '/publish', '', self::NOW));
        self::assertTrue($server->verify($new, 'POST', '/publish', '', self::NOW));
        self::assertSame(
            new RequestSigner(new Keys(self::SECRET))->sign('POST', '/publish', '', self::NOW, self::NONCE),
            $server->sign('POST', '/publish', '', self::NOW, self::NONCE),
        );
    }
}
