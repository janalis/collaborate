<?php

declare(strict_types=1);

namespace Drupal\collaborate\Bridge;

use Drupal\collaborate\Security\DrupalUser;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Janalis\Collaborate\Cursor\PlaceProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The room of a Drupal page: its route and its raw parameters (an entity's id, not the entity Drupal loaded for
 * it), with the query that changes what it shows. Two editors of the same node are in the same room, whatever the
 * destination they came from; one who loses access to the page leaves it before the grant expires.
 */
final readonly class DrupalPlaces implements PlaceProviderInterface
{
    public function __construct(
        private AccessManagerInterface $access,
    ) {}

    /** Query parameters that do not change what a page shows. */
    private const array IGNORED = ['destination', 'draft', '_wrapper_format', 'ajax_form', '_format', 'page'];

    public function place(Request $request): ?array
    {
        $route = $request->attributes->get(RouteObjectInterface::ROUTE_NAME);
        if (!\is_string($route) || '' === $route) {
            return null;
        }
        $raw = $request->attributes->get('_raw_variables');
        $parameters = \is_object($raw) && method_exists($raw, 'all') ? $raw->all() : [];
        $query = array_diff_key($request->query->all(), array_flip(self::IGNORED));
        ksort($parameters);
        ksort($query);

        return ['route' => $route, 'parameters' => $parameters, 'query' => $query];
    }

    /** Whether the user may still open the page: its route's access, checked at every hub request of its page. */
    public function canEnter(UserInterface $user, array $place): bool
    {
        $route = $place['route'] ?? null;
        $parameters = $place['parameters'] ?? [];
        if (!$user instanceof DrupalUser || !\is_string($route) || !\is_array($parameters)) {
            return false;
        }
        try {
            return $this->access->checkNamedRoute($route, $parameters, $user->account);
        } catch (\Throwable) {
            // A route gone, or an entity deleted since: no page to share.
            return false;
        }
    }
}
