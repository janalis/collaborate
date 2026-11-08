<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Transport\WebSocket;

use Janalis\Collaborate\Hub\Transport\WebSocket\Keys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Keys::class)]
final class KeysTest extends TestCase
{
    private const string SECRET = 'a-secret-of-thirty-two-bytes-ok!';
    private const string PREVIOUS = 'the-previous-secret-of-32-bytes!';

    public function testDerivesASubscribeAndAPublishKeyOfTheirOwnFromTheSecret(): void
    {
        $keys = new Keys(self::SECRET);

        self::assertSame(hash_hkdf('sha256', self::SECRET, 32, 'collaborate-hub-subscribe'), $keys->subscribeKey());
        self::assertSame(hash_hkdf('sha256', self::SECRET, 32, 'collaborate-hub-publish'), $keys->publishKey());
        self::assertNotSame($keys->subscribeKey(), $keys->publishKey());
        self::assertSame(32, \strlen($keys->subscribeKey()));
        self::assertSame([$keys->subscribeKey()], $keys->subscribeKeys());
        self::assertSame([$keys->publishKey()], $keys->publishKeys());
    }

    public function testThePreviousSecretVerifiesAfterTheCurrentOneAndNeverSigns(): void
    {
        $keys = new Keys(self::SECRET, self::PREVIOUS);
        $previous = new Keys(self::PREVIOUS);

        self::assertSame(new Keys(self::SECRET)->subscribeKey(), $keys->subscribeKey());
        self::assertSame(new Keys(self::SECRET)->publishKey(), $keys->publishKey());
        self::assertSame([$keys->subscribeKey(), $previous->subscribeKey()], $keys->subscribeKeys());
        self::assertSame([$keys->publishKey(), $previous->publishKey()], $keys->publishKeys());
        self::assertSame([$keys->publishKey()], new Keys(self::SECRET, '')->publishKeys());
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function weakSecrets(): iterable
    {
        yield 'missing' => [null, null, 'collaborate.hub.websocket.secret must be a secret of 32 bytes or more'];
        yield 'short' => [str_repeat('x', 31), null, 'collaborate.hub.websocket.secret must'];
        yield 'short previous' => [self::SECRET, str_repeat('x', 31), 'collaborate.hub.websocket.previous_secret must'];
    }

    #[DataProvider('weakSecrets')]
    public function testRefusesASecretShorterThan32Bytes(
        #[\SensitiveParameter]
        ?string $secret,
        #[\SensitiveParameter]
        ?string $previous,
        string $message,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Keys($secret, $previous);
    }

    public function testAcceptsASecretOfExactly32Bytes(): void
    {
        self::assertCount(2, new Keys(str_repeat('x', 32), str_repeat('y', 32))->subscribeKeys());
    }
}
