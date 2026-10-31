<?php

declare(strict_types=1);

namespace Janalis\Collaborate;

/** Hashes what admits a user, so a grant carries it without revealing it. */
final class Grant
{
    /** @param array<array-key, string> $authorization */
    public static function authorizationHash(array $authorization): string
    {
        $authorization = array_unique($authorization);
        sort($authorization);

        return hash('sha256', json_encode($authorization, \JSON_THROW_ON_ERROR));
    }
}
