<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Transport\WebSocket;

/**
 * Signs the application's requests to the private side of `collaborate:hub:serve`, which the network between
 * them may carry in clear (separate containers): `X-Collaborate-Signature: t=<unix time>,n=<nonce>,s=<hex
 * HMAC-SHA256>` of the time, a random nonce, the method, the request target (`/publish`, `/subscriptions?topic=…`)
 * and the SHA-256 of the body. A signature older or newer than {@see WINDOW} seconds is refused; the server
 * refuses one it saw already, and the nonce tells two identical requests of the same second apart.
 */
final readonly class RequestSigner
{
    public const string HEADER = 'X-Collaborate-Signature';
    public const int WINDOW = 60;

    public function __construct(
        private Keys $keys,
    ) {}

    /** @param ?string $nonce 32 hexadecimal digits; random unless given */
    public function sign(string $method, string $target, string $body, int $now, ?string $nonce = null): string
    {
        $nonce ??= bin2hex(random_bytes(16));

        return 't='
        . $now
        . ',n='
        . $nonce
        . ',s='
        . self::mac($this->keys->publishKey(), $now, $nonce, $method, $target, $body);
    }

    public function verify(string $header, string $method, string $target, string $body, int $now): bool
    {
        $parts = [];
        if (1 !== preg_match('/^t=([1-9]\d{0,11}),n=([0-9a-f]{32}),s=([0-9a-f]{64})$/D', $header, $parts)) {
            return false;
        }
        $time = (int) $parts[1];
        if (abs($now - $time) > self::WINDOW) {
            return false;
        }
        foreach ($this->keys->publishKeys() as $key) {
            if (hash_equals(self::mac($key, $time, $parts[2], $method, $target, $body), $parts[3])) {
                return true;
            }
        }

        return false;
    }

    private static function mac(
        string $key,
        int $time,
        string $nonce,
        string $method,
        string $target,
        string $body,
    ): string {
        return hash_hmac(
            'sha256',
            $time . "\n" . $nonce . "\n" . strtoupper($method) . "\n" . $target . "\n" . hash('sha256', $body),
            $key,
        );
    }
}
