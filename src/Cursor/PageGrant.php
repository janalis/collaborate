<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Cursor;

use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Topics;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A page's cursor capability, issued only by the authorized layout, never from a client-supplied URL.
 *
 * Its room is the HMAC of the route, its parameters and the query, so pages showing the same view share
 * one room whatever their locale or fragment. With a {@see PlaceProviderInterface}, the room is the HMAC
 * of the place it answers, which the grant carries for the provider to check at every request.
 */
final readonly class PageGrant
{
    /** @param list<string> $ignoredParameters route and query parameters that do not separate rooms */
    public function __construct(
        #[\SensitiveParameter]
        private string $secret,
        private Topics $topics,
        private int $lifetime = 36_000,
        private array $ignoredParameters = ['_locale', '_token', '_switch_user'],
        private ?PlaceProviderInterface $places = null,
    ) {}

    /**
     * @param array<array-key, string> $authorization what admits the user: a grant issued before it changed is refused
     *
     * @return string|null null for a page the place provider puts in no place
     */
    public function issue(Request $request, int $userId, int $now, array $authorization = []): ?string
    {
        if (null !== $this->places) {
            $place = $this->places->place($request);
            if (null === $place) {
                return null;
            }
            $place = json_encode($this->canonical($place), \JSON_THROW_ON_ERROR);

            return $this->sign([
                (string) $userId,
                (string) ($now + $this->lifetime),
                Grant::authorizationHash($authorization),
                hash_hmac('sha256', $place, $this->secret),
                self::encode($place),
            ]);
        }
        $parameters = $request->attributes->all('_route_params');
        $query = $request->query->all();
        foreach ($this->ignoredParameters as $name) {
            unset($parameters[$name], $query[$name]);
        }
        $identity = json_encode(
            [$request->attributes->get('_route'), $this->canonical($parameters), $this->canonical($query)],
            \JSON_THROW_ON_ERROR,
        );

        return $this->sign([
            (string) $userId,
            (string) ($now + $this->lifetime),
            Grant::authorizationHash($authorization),
            hash_hmac('sha256', $identity, $this->secret),
        ]);
    }

    /** @param array<array-key, string> $authorization */
    public function topic(string $grant, int $userId, int $now, array $authorization = []): string
    {
        return $this->verify($grant, $userId, $now, $authorization)['topic'];
    }

    /**
     * The room a grant proves, and the place it was issued for when a place provider is configured.
     *
     * @param array<array-key, string> $authorization
     *
     * @return array{topic: string, place: array<array-key, mixed>|null}
     */
    public function verify(string $grant, int $userId, int $now, array $authorization = []): array
    {
        $parts = explode('.', $grant);
        $signed = null === $this->places ? 4 : 5;
        if (
            ($signed + 1) !== \count($parts)
            || $parts[0] !== (string) $userId
            // Signed by issue(), whose expiry is a canonical integer: a later one than now.
            || false === filter_var($parts[1], \FILTER_VALIDATE_INT, ['options' => ['min_range' => $now + 1]])
            || $parts[2] !== Grant::authorizationHash($authorization)
            || !preg_match('/^[a-f0-9]{64}$/D', $parts[3])
            || !hash_equals(
                hash_hmac('sha256', implode('.', \array_slice($parts, 0, $signed)), $this->secret),
                $parts[$signed],
            )
        ) {
            throw new AccessDeniedHttpException('Invalid cursor page grant.');
        }

        return [
            'topic' => $this->topics->cursors($parts[3]),
            'place' => 5 === $signed ? self::decode($parts[4]) : null,
        ];
    }

    /**
     * @param list<string> $parts
     */
    private function sign(array $parts): string
    {
        $payload = implode('.', $parts);

        return $payload . '.' . hash_hmac('sha256', $payload, $this->secret);
    }

    /** Base64url without padding: a grant is a dot-separated token. */
    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Only reached for a grant this server signed: its place is the JSON object issue() encoded.
     *
     * @return array<array-key, mixed>
     */
    private static function decode(string $value): array
    {
        $place = json_decode(
            (string) base64_decode(strtr($value, '-_', '+/'), true),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        \assert(\is_array($place), 'issue() encodes the array a place provider answered.');

        return $place;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function canonical(array $values): array
    {
        ksort($values);
        foreach (array_keys($values) as $key) {
            if (!\is_array($values[$key])) {
                continue;
            }
            $values[$key] = $this->canonical($values[$key]);
        }

        return $values;
    }
}
