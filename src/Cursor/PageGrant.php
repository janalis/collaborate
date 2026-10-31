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
 * one room whatever their locale or fragment.
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
    ) {}

    /** @param array<array-key, string> $authorization what admits the user: a grant issued before it changed is refused */
    public function issue(Request $request, int $userId, int $now, array $authorization = []): string
    {
        $parameters = $request->attributes->all('_route_params');
        $query = $request->query->all();
        foreach ($this->ignoredParameters as $name) {
            unset($parameters[$name], $query[$name]);
        }
        $identity = json_encode(
            [$request->attributes->get('_route'), $this->canonical($parameters), $this->canonical($query)],
            \JSON_THROW_ON_ERROR,
        );
        $room = hash_hmac('sha256', $identity, $this->secret);
        $payload =
            $userId . '.' . ($now + $this->lifetime) . '.' . Grant::authorizationHash($authorization) . '.' . $room;

        return $payload . '.' . hash_hmac('sha256', $payload, $this->secret);
    }

    /** @param array<array-key, string> $authorization */
    public function topic(string $grant, int $userId, int $now, array $authorization = []): string
    {
        $parts = explode('.', $grant);
        if (
            5 !== \count($parts)
            || $parts[0] !== (string) $userId
            // Signed by issue(), whose expiry is a canonical integer: a later one than now.
            || false === filter_var($parts[1], \FILTER_VALIDATE_INT, ['options' => ['min_range' => $now + 1]])
            || $parts[2] !== Grant::authorizationHash($authorization)
            || !preg_match('/^[a-f0-9]{64}$/D', $parts[3])
            || !hash_equals(hash_hmac('sha256', implode('.', \array_slice($parts, 0, 4)), $this->secret), $parts[4])
        ) {
            throw new AccessDeniedHttpException('Invalid cursor page grant.');
        }

        return $this->topics->cursors($parts[3]);
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
