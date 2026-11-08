<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Transport\WebSocket;

use Janalis\Collaborate\Cursor\CursorIdentity;
use Janalis\Collaborate\Hub\Transport\WebSocket\Keys;
use Janalis\Collaborate\Hub\Transport\WebSocket\Origin;
use Janalis\Collaborate\Hub\Transport\WebSocket\SubscriberToken;
use Janalis\Collaborate\Hub\Transport\WebSocket\TokenClaims;
use Janalis\Collaborate\Tests\Fixtures\Arrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubscriberToken::class)]
#[CoversClass(TokenClaims::class)]
#[UsesClass(Keys::class)]
#[UsesClass(CursorIdentity::class)]
#[UsesClass(Origin::class)]
final class SubscriberTokenTest extends TestCase
{
    private const string SECRET = 'a-secret-of-thirty-two-bytes-ok!';
    private const string PREVIOUS = 'the-previous-secret-of-32-bytes!';
    private const string AUDIENCE = '/collaborate-hub';
    private const int NOW = 1_800_000_000;

    public function testATokenItSignedGrantsItsTopicsToItsOriginAndUserUntilItExpires(): void
    {
        $tokens = self::tokens();
        $token = $tokens->sign(15, 'HTTPS://Admin.Example.com:443', ['urn:c:cursors:r', 'urn:c:draft:d'], self::NOW);

        $claims = $tokens->verify($token, self::NOW);
        self::assertNotNull($claims);
        self::assertSame(15, $claims->sub);
        self::assertSame('https://admin.example.com', $claims->origin);
        self::assertSame(['urn:c:cursors:r', 'urn:c:draft:d'], $claims->topics);
        self::assertSame(self::NOW + 300, $claims->exp);
        self::assertNotNull($tokens->verify($token, self::NOW + 299));
        self::assertNull($tokens->verify($token, self::NOW + 300));
        // Signed by a clock a little ahead of the server's.
        self::assertNotNull($tokens->verify($token, self::NOW - 5));
        self::assertNull($tokens->verify($token, self::NOW - 6));
    }

    public function testTheClaimsAreReadableJsonSignedWithTheSubscribeKey(): void
    {
        $keys = new Keys(self::SECRET);
        $token = self::tokens($keys)->sign(7, 'https://example.com', ['urn:c:updates'], self::NOW);
        [$payload, $signature] = explode('.', $token);

        self::assertSame(
            [
                'v' => 1,
                'aud' => '/collaborate-hub',
                'origin' => 'https://example.com',
                'sub' => 7,
                'topics' => ['urn:c:updates'],
                'iat' => self::NOW,
                'exp' => self::NOW + 300,
            ],
            json_decode(self::decode($payload), true),
        );
        self::assertSame(hash_hmac('sha256', $payload, $keys->subscribeKey(), true), self::decode($signature));
        self::assertStringNotContainsString('=', $token);
        self::assertStringNotContainsString('\/', self::decode($payload));
    }

    public function testAPageInACursorRoomIsTokenedWhoItIsThere(): void
    {
        $tokens = self::tokens();
        $identity = new CursorIdentity(15, 'Alice', 'red', '/avatars/15.png');
        $token = $tokens->sign(
            15,
            'https://e.com',
            ['urn:c:cursors:r', 'urn:c:updates'],
            self::NOW,
            'urn:c:cursors:r',
            $identity,
        );

        $claims = $tokens->verify($token, self::NOW);
        self::assertNotNull($claims);
        self::assertSame('urn:c:cursors:r', $claims->cursorRoom);
        self::assertEquals($identity, $claims->cursor);
        self::assertTrue(SubscriberToken::claimsCursor($token));
        $payload = Arrays::json(self::decode(explode('.', $token)[0]));
        self::assertSame(
            ['room' => 'urn:c:cursors:r', 'label' => 'Alice', 'color' => 'red', 'avatar' => '/avatars/15.png'],
            $payload['cur'],
        );
        // Without its room, or without its identity, a page sends its events over HTTP.
        foreach ([
            [null,              $identity],
            ['urn:c:cursors:r', null],
        ] as [$room, $cursor]) {
            $plain = $tokens->sign(15, 'https://e.com', ['urn:c:cursors:r'], self::NOW, $room, $cursor);
            self::assertFalse(SubscriberToken::claimsCursor($plain));
            $claims = $tokens->verify($plain, self::NOW);
            self::assertNotNull($claims);
            self::assertNull($claims->cursorRoom);
            self::assertNull($claims->cursor);
        }
        self::assertFalse(SubscriberToken::claimsCursor('garbage'));
        self::assertFalse(SubscriberToken::claimsCursor('.x'));
    }

    public function testACursorClaimThatWouldMakeTheTokenTooLongIsLeftOut(): void
    {
        $tokens = self::tokens();
        $long = new CursorIdentity(15, 'Alice', 'red', 'data:image/png;base64,' . str_repeat('A', 8000));
        $token = $tokens->sign(15, 'https://e.com', ['urn:c:cursors:r'], self::NOW, 'urn:c:cursors:r', $long);

        self::assertLessThanOrEqual(SubscriberToken::MAX_LENGTH, \strlen($token));
        self::assertFalse(SubscriberToken::claimsCursor($token));
        self::assertNotNull($tokens->verify($token, self::NOW));
        // A claim that makes the token exactly the longest is kept.
        $exact = null;
        for ($length = 5000; $length < 6500 && null === $exact; $length++) {
            $candidate = $tokens->sign(
                15,
                'https://e.com',
                ['urn:c:cursors:r'],
                self::NOW,
                'urn:c:cursors:r',
                new CursorIdentity(15, 'Alice', 'red', str_repeat('a', $length)),
            );
            if (SubscriberToken::MAX_LENGTH === \strlen($candidate)) {
                $exact = $candidate;
            }
        }
        self::assertNotNull($exact);
        self::assertTrue(SubscriberToken::claimsCursor($exact));
        // The longest claim that fits is kept.
        $fits = new CursorIdentity(15, 'Alice', 'red', str_repeat('a', 5800));
        $kept = $tokens->sign(15, 'https://e.com', ['urn:c:cursors:r'], self::NOW, 'urn:c:cursors:r', $fits);
        self::assertTrue(SubscriberToken::claimsCursor($kept));
    }

    public function testRefusesToSignTopicsTooLongForTheHub(): void
    {
        $tokens = self::tokens();
        // Topics that make the token exactly the longest are signed, and verify.
        $fits = null;
        for ($length = 5800; $length < 6300 && null === $fits; $length++) {
            try {
                $token = $tokens->sign(1, 'https://e.com', ['urn:c:' . str_repeat('t', $length)], self::NOW);
            } catch (\LengthException) {
                break;
            }
            if (SubscriberToken::MAX_LENGTH === \strlen($token)) {
                $fits = $token;
            }
        }
        self::assertNotNull($fits);
        self::assertNotNull($tokens->verify($fits, self::NOW));

        $this->expectException(\LengthException::class);
        $this->expectExceptionMessage(
            'The subscriber token for 1 topics is longer than the 8192 bytes the hub accepts: lower collaborate.hub.max_drafts or shorten collaborate.hub.topic_prefix.',
        );
        $tokens->sign(1, 'https://e.com', ['urn:c:' . str_repeat('t', 6300)], self::NOW);
    }

    public function testRefusesToSignForAnOriginThatIsNone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid page origin.');

        self::tokens()->sign(1, 'null', ['urn:c:updates'], self::NOW);
    }

    public function testAServerRotatingItsSecretVerifiesTokensOfBoth(): void
    {
        $server = self::tokens(new Keys(self::SECRET, self::PREVIOUS));
        $old = self::tokens(new Keys(self::PREVIOUS))->sign(1, 'https://e.com', ['urn:c:updates'], self::NOW);
        $new = self::tokens(new Keys(self::SECRET))->sign(1, 'https://e.com', ['urn:c:updates'], self::NOW);

        self::assertNotNull($server->verify($old, self::NOW));
        self::assertNotNull($server->verify($new, self::NOW));
        self::assertNull(self::tokens()->verify($old, self::NOW));
        self::assertSame($new, $server->sign(1, 'https://e.com', ['urn:c:updates'], self::NOW));
    }

    public function testRefusesATamperedOrMalformedToken(): void
    {
        $tokens = self::tokens();
        $token = $tokens->sign(1, 'https://e.com', ['urn:c:updates'], self::NOW);
        [$payload, $signature] = explode('.', $token);
        $claims = Arrays::json(self::decode($payload));
        $forged = self::encode((string) json_encode(['sub' => 2] + $claims));

        foreach ([
            '',
            $payload,
            $payload . '.',
            '.' . $signature,
            $forged . '.' . $signature,
            $payload . '.' . strrev($signature),
            $payload . '.' . $signature . '.x',
            $payload . '.' . $signature . '=',
            $payload . '.' . substr($signature, 1),
            ' ' . $token,
            $token . "\n",
            str_replace('-', '+', $token . '-+'),
            str_repeat('A', 8200) . '.' . $signature,
        ] as $candidate) {
            self::assertNull($tokens->verify($candidate, self::NOW), $candidate);
        }
        self::assertNull(self::tokens(new Keys(self::PREVIOUS))->verify($token, self::NOW));
    }

    /** @return iterable<string, array{0: array<array-key, mixed>, 1?: bool}> */
    public static function invalidClaims(): iterable
    {
        $valid = [
            'v' => 1,
            'aud' => '/collaborate-hub',
            'origin' => 'https://e.com',
            'sub' => 1,
            'topics' => ['urn:c:updates'],
            'iat' => self::NOW,
            'exp' => self::NOW + 60,
        ];
        yield 'valid' => [$valid, true];
        foreach (['v', 'aud', 'origin', 'sub', 'topics', 'iat', 'exp'] as $claim) {
            $missing = $valid;
            unset($missing[$claim]);
            yield 'no ' . $claim => [$missing];
        }
        yield 'version' => [['v' => 2] + $valid];
        yield 'version as string' => [['v' => '1'] + $valid];
        yield 'another hub' => [['aud' => 'wss://other.example.com/hub'] + $valid];
        yield 'origin not normalized' => [['origin' => 'https://E.com'] + $valid];
        yield 'origin null' => [['origin' => 'null'] + $valid];
        yield 'origin not a string' => [['origin' => 1] + $valid];
        yield 'user as string' => [['sub' => '1'] + $valid];
        yield 'iat as string' => [['iat' => (string) self::NOW] + $valid];
        yield 'exp as float' => [['exp' => self::NOW + 60.0] + $valid];
        yield 'issued in the future' => [['iat' => self::NOW + 6, 'exp' => self::NOW + 60] + $valid];
        yield 'issued just ahead' => [['iat' => self::NOW + 5, 'exp' => self::NOW + 60] + $valid, true];
        yield 'expired' => [['iat' => self::NOW - 60, 'exp' => self::NOW] + $valid];
        yield 'expires before issued' => [['iat' => self::NOW + 2, 'exp' => self::NOW + 1] + $valid];
        yield 'expires when issued' => [['iat' => self::NOW + 1, 'exp' => self::NOW + 1] + $valid];
        yield 'lives longer than the ttl' => [['iat' => self::NOW, 'exp' => self::NOW + 301] + $valid];
        yield 'lives the ttl' => [['iat' => self::NOW - 1, 'exp' => self::NOW + 299] + $valid, true];
        yield 'no topic' => [['topics' => []] + $valid];
        yield 'topics as object' => [['topics' => ['a' => 'urn:c:updates']] + $valid];
        yield 'topics as string' => [['topics' => 'urn:c:updates'] + $valid];
        yield 'topic not a string' => [['topics' => [1]] + $valid];
        yield 'topic of another prefix' => [['topics' => ['urn:other:updates']] + $valid];
        yield 'wildcard topic' => [['topics' => ['*']] + $valid];
        yield 'duplicate topic' => [['topics' => ['urn:c:updates', 'urn:c:updates']] + $valid];
        yield 'max topics' => [['topics' => self::topics(5)] + $valid, true];
        yield 'too many topics' => [['topics' => self::topics(6)] + $valid];
        yield 'not an object' => [[1, 2]];
        yield 'an unknown claim' => [['x' => 'y'] + $valid, true];
        yield 'a claim nesting deeper than the topics' => [['x' => ['y' => []]] + $valid];
        $cursor = ['room' => 'urn:c:updates', 'label' => 'Alice', 'color' => 'red', 'avatar' => null];
        yield 'a cursor claim' => [['cur' => $cursor] + $valid, true];
        yield 'a cursor claim with an avatar' => [['cur' => ['avatar' => '/a.png'] + $cursor] + $valid, true];
        yield 'a cursor claim without an avatar' => [
            ['cur' => array_diff_key($cursor, ['avatar' => 1])] + $valid,
            true,
        ];
        yield 'a cursor claim that is none' => [['cur' => null] + $valid];
        yield 'a cursor claim as a string' => [['cur' => 'urn:c:updates'] + $valid];
        yield 'a cursor room outside the topics' => [['cur' => ['room' => 'urn:c:cursors:x'] + $cursor] + $valid];
        yield 'a cursor room that is none' => [['cur' => ['room' => null] + $cursor] + $valid];
        yield 'a cursor label that is none' => [['cur' => ['label' => null] + $cursor] + $valid];
        yield 'a cursor label as a number' => [['cur' => ['label' => 1] + $cursor] + $valid];
        yield 'a cursor colour that is none' => [['cur' => ['color' => null] + $cursor] + $valid];
        yield 'a cursor avatar as a number' => [['cur' => ['avatar' => 1] + $cursor] + $valid];
    }

    /** @param array<array-key, mixed> $claims */
    #[DataProvider('invalidClaims')]
    public function testVerifiesEveryClaim(array $claims, bool $valid = false): void
    {
        $keys = new Keys(self::SECRET);
        $payload = self::encode((string) json_encode($claims, \JSON_PRESERVE_ZERO_FRACTION));
        $token = $payload . '.' . self::encode(hash_hmac('sha256', $payload, $keys->subscribeKey(), true));

        self::assertSame($valid, self::tokens($keys)->verify($token, self::NOW) instanceof TokenClaims);
    }

    /** Unpadded base64url never ends 1 past a multiple of 4: the next length after the maximum is 2 past it. */
    public function testAcceptsATokenOfTheMaximumLengthOnly(): void
    {
        $keys = new Keys(self::SECRET);
        $claims = [
            'v' => 1,
            'aud' => '/collaborate-hub',
            'origin' => 'https://e.com',
            'sub' => 1,
            'topics' => ['urn:c:updates'],
            'iat' => self::NOW,
            'exp' => self::NOW + 60,
        ];
        foreach ([SubscriberToken::MAX_LENGTH => true, SubscriberToken::MAX_LENGTH + 2 => false] as $length => $valid) {
            $filler = 0;
            do {
                $payload = self::encode((string) json_encode(['x' => str_repeat('a', $filler++)] + $claims));
                $token = $payload . '.' . self::encode(hash_hmac('sha256', $payload, $keys->subscribeKey(), true));
            } while (\strlen($token) < $length);
            self::assertSame($length, \strlen($token));
            self::assertSame($valid, self::tokens($keys)->verify($token, self::NOW) instanceof TokenClaims);
        }
    }

    public function testRefusesSignedPayloadsThatAreNoClaims(): void
    {
        $keys = new Keys(self::SECRET);
        foreach (['not json', '"string"', '{"a":{"b":{"c":{"d":1}}}}', "\xff"] as $json) {
            $payload = self::encode($json);
            $token = $payload . '.' . self::encode(hash_hmac('sha256', $payload, $keys->subscribeKey(), true));
            self::assertNull(self::tokens($keys)->verify($token, self::NOW), $json);
        }
    }

    private static function tokens(?Keys $keys = null): SubscriberToken
    {
        // Two drafts: five topics with the page's cursors, saved views and notifications.
        return new SubscriberToken($keys ?? new Keys(self::SECRET), self::AUDIENCE, 'urn:c:', 300, 2);
    }

    /** @return list<string> */
    private static function topics(int $count): array
    {
        return array_map(static fn(int $index): string => 'urn:c:draft:' . $index, range(1, $count));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
