<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Identity;

use Symfony\Component\Security\Core\User\UserInterface;

/** Who a signed-in user is to the people they collaborate with. */
interface CollaboratorProviderInterface
{
    /** A stable positive integer, or null when the user cannot collaborate (e.g. not persisted yet). It also picks the user's colour. */
    public function id(UserInterface $user): ?int;

    /** Plain text shown on the user's cursor, presence tooltip and draft notices. */
    public function label(UserInterface $user): string;

    /** A same-origin image URL for the header's presence avatars, or null for the user's initial. */
    public function avatarUrl(UserInterface $user): ?string;

    /**
     * Everything that admits the user (roles, group roles, permissions…). A page or field grant issued
     * before it changed is refused, and only a page that still admits the user issues a new one.
     *
     * @return list<string>
     */
    public function authorization(UserInterface $user): array;
}
