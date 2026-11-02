<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Cursor;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The place a page is in, for an application whose rooms are not its routes: two pages whose addresses
 * differ may show the same place (a folder reached from two homes), and a user may lose a place while
 * their grant lasts (a folder revoked). Configured as `cursors.places`.
 */
interface PlaceProviderInterface
{
    /**
     * The place of the page a request renders, as JSON-encodable data whose canonical form names its room,
     * or null for a page that is in none (no layer, no grant).
     *
     * @return array<array-key, mixed>|null
     */
    public function place(Request $request): ?array;

    /**
     * Whether the user may be in a place, asked at every hub request its page
     * makes: a grant to a place the user lost is refused before it expires.
     *
     * @param array<array-key, mixed> $place what {@see place()} answered when the grant was issued
     */
    public function canEnter(UserInterface $user, array $place): bool;
}
