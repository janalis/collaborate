<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Push;

/**
 * VAPID keys (RFC 8292): a P-256 pair, the public key as the base64url of its uncompressed point (65 bytes,
 * what a browser's `applicationServerKey` reads) and the private key as the base64url of its scalar (32 bytes).
 */
final class Vapid
{
    /** @return array{publicKey: string, privateKey: string} */
    public static function generate(): array
    {
        return self::pair(openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']));
    }

    /**
     * The pair of a key OpenSSL made (what `generate()` returns), or what OpenSSL said when it made none.
     *
     * @internal
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public static function pair(\OpenSSLAsymmetricKey|false $key): array
    {
        [$point, $scalar] = self::components($key);
        $failed = static fn(): \RuntimeException => new \RuntimeException(
            // An OpenSSL that says nothing has its silence concatenated: false is the empty string there.
            // @mago-expect analysis:possibly-false-operand
            'OpenSSL could not make a P-256 key: ' . openssl_error_string(),
        );

        return [
            'publicKey' => self::encode($point ?? throw $failed()),
            'privateKey' => self::encode($scalar ?? throw $failed()),
        ];
    }

    /**
     * What is wrong with a configured pair and subject, nothing when they can sign a push.
     *
     * @return list<string>
     */
    public static function problems(?string $publicKey, ?string $privateKey, ?string $subject): array
    {
        $problems = [];
        $public = self::decode($publicKey ?? '');
        $private = self::decode($privateKey ?? '');
        if (null === $public || 65 !== \strlen($public) || "\x04" !== $public[0]) {
            $problems[] = 'The public key is not the base64url of an uncompressed P-256 point (65 bytes).';
        }
        if (null === $private || 32 !== \strlen($private)) {
            $problems[] = 'The private key is not the base64url of a P-256 scalar (32 bytes).';
        }
        if ([] === $problems && !self::matches((string) $public, (string) $private)) {
            $problems[] = 'The public key is not the one of the private key.';
        }
        if (!self::isSubject($subject)) {
            $problems[] = 'The subject is no mailto: or https: URL.';
        }

        return $problems;
    }

    public static function isSubject(?string $subject): bool
    {
        return (
            null !== $subject
            && (
                1 === preg_match('/^mailto:[^@\s]+@[^@\s]+$/D', $subject)
                || str_starts_with($subject, 'https://')
                && false !== filter_var($subject, \FILTER_VALIDATE_URL)
            )
        );
    }

    /** Whether the point is the private scalar's: OpenSSL derives it from a key holding the scalar alone. */
    private static function matches(string $public, string $private): bool
    {
        // SEC 1 ECPrivateKey: version 1, the scalar and the curve (prime256v1), without its public point.
        $der = "\x30\x31\x02\x01\x01\x04\x20" . $private . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . base64_encode($der) . "\n-----END EC PRIVATE KEY-----\n";
        [$point] = self::components(openssl_pkey_get_private($pem));

        return null !== $point && hash_equals($point, $public);
    }

    /** @return array{string|null, string|null} the uncompressed point of a key and its scalar */
    private static function components(\OpenSSLAsymmetricKey|false $key): array
    {
        $details = false === $key ? [] : openssl_pkey_get_details($key);
        $ec = \is_array($details) ? $details['ec'] ?? [] : [];
        // What a key lacks is empty: the point has both its coordinates, of 32 bytes each once padded, or none.
        $part = static fn(string $name): string => \is_string($ec[$name] ?? null) ? self::pad($ec[$name]) : '';
        $point = $part('x') . $part('y');
        $scalar = $part('d');

        return [64 === \strlen($point) ? "\x04" . $point : null, '' === $scalar ? null : $scalar];
    }

    private static function pad(string $bytes): string
    {
        return str_pad($bytes, 32, "\0", \STR_PAD_LEFT);
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $value): ?string
    {
        if ('' === $value || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            return null;
        }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);

        return false === $bytes ? null : $bytes;
    }
}
