<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Transport\WebSocket;

use Janalis\Collaborate\Cursor\CursorIdentity;

/**
 * The credential a page subscribes with: `base64url(claims).base64url(HMAC-SHA256)`, signed by the application,
 * verified by `collaborate:hub:serve`. The claims are readable, not secret; they name exact topics (no
 * wildcard), the hub (`aud`, the configured public URL as written), the page's origin, the user and a lifetime
 * of `subscription_ttl` at most.
 */
final readonly class SubscriberToken
{
    public const int VERSION = 1;

    /** Seconds a token may be issued in the future of the verifying clock. */
    public const int LEEWAY = 5;

    /** Bytes: a token of the most topics a page subscribes to stays far below. */
    public const int MAX_LENGTH = 8192;

    /** Topics of a page besides its drafts: its cursors, its saved views and its user's notifications. */
    private const int PAGE_TOPICS = 3;

    public function __construct(
        private Keys $keys,
        private string $audience,
        private string $topicPrefix,
        private int $ttl,
        /** Drafts one page subscription carries (`hub.max_drafts`). */
        private int $maxDrafts,
    ) {}

    /**
     * A page in a cursor room with cursors on may send its cursor events over its socket: its room and who it is
     * there are claimed too (`cur`), so the hub stamps its events as the controller would. A claim that would
     * make the token too long is left out: the page then sends its events over HTTP.
     *
     * @param non-empty-list<string> $topics
     */
    public function sign(
        int $userId,
        string $origin,
        array $topics,
        int $now,
        ?string $cursorRoom = null,
        ?CursorIdentity $cursor = null,
    ): string {
        $claims = [
            'v' => self::VERSION,
            'aud' => $this->audience,
            'origin' => Origin::normalize($origin) ?? throw new \InvalidArgumentException('Invalid page origin.'),
            'sub' => $userId,
            'topics' => $topics,
            'iat' => $now,
            'exp' => $now + $this->ttl,
        ];
        if (null !== $cursorRoom && null !== $cursor) {
            $token = $this->signed(
                $claims
                + ['cur' => [
                    'room' => $cursorRoom,
                    'label' => $cursor->label,
                    'color' => $cursor->color,
                    'avatar' => $cursor->avatar,
                ]],
            );
            if (\strlen($token) <= self::MAX_LENGTH) {
                return $token;
            }
        }

        return $this->signed($claims);
    }

    /** Whether a token this hub just signed lets its page send cursor events over its socket. */
    public static function claimsCursor(#[\SensitiveParameter] string $token): bool
    {
        $json = base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true);
        /** @var mixed $claims */
        $claims = false === $json ? null : json_decode($json, true);

        return \is_array($claims) && \array_key_exists('cur', $claims);
    }

    /** The claims of a token this hub signed, valid at `$now`; null for any other. Claims nest one list deep. */
    public function verify(#[\SensitiveParameter] string $token, int $now): ?TokenClaims
    {
        $parts = [];
        if (
            \strlen($token) > self::MAX_LENGTH
            || 1 !== preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})$/D', $token, $parts)
        ) {
            return null;
        }
        // The signature of the bytes received, before anything of them is decoded.
        if (!$this->verified($parts[1], $parts[2])) {
            return null;
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);

        return false === $json ? null : $this->claims($json, $now);
    }

    private function verified(string $payload, string $signature): bool
    {
        foreach ($this->keys->subscribeKeys() as $key) {
            if (hash_equals(self::encode(hash_hmac('sha256', $payload, $key, true)), $signature)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $claims */
    private function signed(array $claims): string
    {
        $payload = self::encode(json_encode($claims, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        return $payload . '.' . self::encode(hash_hmac('sha256', $payload, $this->keys->subscribeKey(), true));
    }

    private function claims(string $json, int $now): ?TokenClaims
    {
        try {
            /** @var array<array-key, mixed>|scalar|null $claims */
            $claims = json_decode($json, true, 3, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (
            !\is_array($claims)
            || self::VERSION !== ($claims['v'] ?? null)
            || $this->audience !== ($claims['aud'] ?? null)
            || !\is_string($claims['origin'] ?? null)
            || $claims['origin'] !== Origin::normalize($claims['origin'])
            || !\is_int($claims['sub'] ?? null)
            || !\is_int($claims['iat'] ?? null)
            || !\is_int($claims['exp'] ?? null)
            || $claims['iat'] > ($now + self::LEEWAY)
            || $claims['exp'] <= $now
            || $claims['exp'] <= $claims['iat']
            || ($claims['exp'] - $claims['iat']) > $this->ttl
            || !$this->topics($claims['topics'] ?? null)
        ) {
            return null;
        }
        /** @var non-empty-list<string> $topics checked by topics() */
        $topics = $claims['topics'];
        if (!\array_key_exists('cur', $claims)) {
            return new TokenClaims($claims['sub'], $claims['origin'], $topics, $claims['exp']);
        }
        /** @var mixed $cursor */
        $cursor = $claims['cur'];
        // @infection-ignore-all: the checks below refuse a claim that is no object too (its offsets read null); this one types it.
        if (!\is_array($cursor)) {
            return null;
        }
        if (
            !\is_string($cursor['room'] ?? null)
            || !\in_array($cursor['room'], $topics, true)
            || !\is_string($cursor['label'] ?? null)
            || !\is_string($cursor['color'] ?? null)
            || !(null === ($cursor['avatar'] ?? null) || \is_string($cursor['avatar']))
        ) {
            return null;
        }

        return new TokenClaims(
            $claims['sub'],
            $claims['origin'],
            $topics,
            $claims['exp'],
            $cursor['room'],
            new CursorIdentity($claims['sub'], $cursor['label'], $cursor['color'], $cursor['avatar'] ?? null),
        );
    }

    /** Exact topics of this hub: a non-empty list of distinct strings with the prefix, few enough for one page. */
    private function topics(mixed $topics): bool
    {
        return (
            \is_array($topics)
            && [] !== $topics
            && array_is_list($topics)
            && \count($topics) <= ($this->maxDrafts + self::PAGE_TOPICS)
            && [] === array_filter(
                $topics,
                fn(mixed $topic): bool => !\is_string($topic) || !str_starts_with($topic, $this->topicPrefix),
            )
            && array_unique($topics) === $topics
        );
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
