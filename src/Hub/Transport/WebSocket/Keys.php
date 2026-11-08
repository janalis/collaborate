<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Transport\WebSocket;

/**
 * The keys of the WebSocket hub, derived from its dedicated secret: one signs subscriber tokens, the other the
 * application's publish requests, so a subscriber token tells nothing of the publish key. The secret being
 * rotated out (`previous_secret`) still verifies, never signs.
 *
 * Checked when the service is built, not when the container is compiled: the secret is an environment variable.
 */
final readonly class Keys
{
    public const int MIN_SECRET_BYTES = 32;

    /** @var non-empty-list<string> current first */
    private array $subscribe;

    /** @var non-empty-list<string> current first */
    private array $publish;

    public function __construct(#[\SensitiveParameter] ?string $secret, #[\SensitiveParameter] ?string $previous = null)
    {
        $secrets = [self::checked($secret, 'secret')];
        if (null !== $previous && '' !== $previous) {
            $secrets[] = self::checked($previous, 'previous_secret');
        }
        $subscribe = [];
        $publish = [];
        foreach ($secrets as $current) {
            $subscribe[] = self::derive($current, 'subscribe');
            $publish[] = self::derive($current, 'publish');
        }
        $this->subscribe = $subscribe;
        $this->publish = $publish;
    }

    public function subscribeKey(): string
    {
        return $this->subscribe[0];
    }

    /** @return non-empty-list<string> */
    public function subscribeKeys(): array
    {
        return $this->subscribe;
    }

    public function publishKey(): string
    {
        return $this->publish[0];
    }

    /** @return non-empty-list<string> */
    public function publishKeys(): array
    {
        return $this->publish;
    }

    /** @return non-empty-string */
    private static function checked(#[\SensitiveParameter] ?string $secret, string $option): string
    {
        if (null === $secret || \strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new \InvalidArgumentException(\sprintf(
                'collaborate.hub.websocket.%s must be a secret of %d bytes or more, of its own (not kernel.secret).',
                $option,
                self::MIN_SECRET_BYTES,
            ));
        }

        return $secret;
    }

    /** @param non-empty-string $secret */
    private static function derive(#[\SensitiveParameter] string $secret, string $use): string
    {
        return hash_hkdf('sha256', $secret, 32, 'collaborate-hub-' . $use);
    }
}
