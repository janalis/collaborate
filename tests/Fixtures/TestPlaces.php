<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures;

use Janalis\Collaborate\Cursor\PlaceProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/** The place a request's `_place` attribute names, which a user may enter until it is revoked. */
final class TestPlaces implements PlaceProviderInterface
{
    /** @var list<array<array-key, mixed>> */
    public array $revoked = [];

    /** @var list<array{UserInterface, array<array-key, mixed>}> */
    public array $asked = [];

    public function place(Request $request): ?array
    {
        $place = $request->attributes->get('_place');

        return \is_array($place) ? $place : null;
    }

    public function canEnter(UserInterface $user, array $place): bool
    {
        $this->asked[] = [$user, $place];

        return !\in_array($place, $this->revoked, true);
    }
}
