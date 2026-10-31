<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Twig;

use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The page's collaboration layer and header presence, rendered only for a collaborator holding the role.
 *
 * @phpstan-type Layer array{cursors: bool, updates: bool, throttle: int, heartbeat: int, expiry: int, maxAvatars: int, renew: int, colors: list<string>}
 */
final class CollaborateExtension extends AbstractExtension
{
    private const array DEFAULT_LAYER = [
        'cursors' => true,
        'updates' => false,
        'throttle' => 100,
        'heartbeat' => 5,
        'expiry' => 15,
        'maxAvatars' => 5,
        'renew' => 240,
        'colors' => ['blue'],
    ];

    /** @var Layer */
    private readonly array $layer;

    /**
     * @param array{cursors?: bool, updates?: bool, throttle?: int, heartbeat?: int, expiry?: int, maxAvatars?: int, renew?: int, colors?: list<string>} $layer
     *   `throttle` in milliseconds; `heartbeat`, `expiry` and `renew` in seconds
     */
    public function __construct(
        private readonly RequestStack $requests,
        private readonly Security $security,
        private readonly CollaboratorProviderInterface $collaborators,
        private readonly PageGrant $pages,
        private readonly DraftAccess $drafts,
        private readonly string $role = 'ROLE_ADMIN',
        array $layer = [],
    ) {
        $this->layer = $layer + self::DEFAULT_LAYER;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('collaborate_grant', $this->grant(...)),
            new TwigFunction('collaborate_enabled', $this->enabled(...)),
            new TwigFunction('collaborate_layer', $this->layer(...), [
                'needs_environment' => true,
                'is_safe' => ['html'],
            ]),
            new TwigFunction('collaborate_presence', $this->presence(...), [
                'needs_environment' => true,
                'is_safe' => ['html'],
            ]),
        ];
    }

    /** Whether shared drafts are on. */
    public function enabled(): bool
    {
        return $this->drafts->enabled;
    }

    /** The page grant of the current page, or null when the signed-in user may not collaborate on it. */
    public function grant(): ?string
    {
        $collaborator = $this->collaborator();
        if (null === $collaborator) {
            return null;
        }
        [$request, $user, $id] = $collaborator;

        return $this->pages->issue($request, $id, time(), $this->collaborators->authorization($user));
    }

    public function layer(Environment $twig): string
    {
        $grant = $this->grant();
        if (null === $grant) {
            return '';
        }

        return $twig->render('@JanalisCollaborate/layer.html.twig', [
            'grant' => $grant,
            'cursors' => $this->layer['cursors'],
            'updates' => $this->layer['updates'],
            'config' => [
                'throttle' => $this->layer['throttle'],
                'heartbeat' => $this->layer['heartbeat'] * 1000,
                'expiry' => $this->layer['expiry'] * 1000,
                'maxAvatars' => $this->layer['maxAvatars'],
                'renew' => $this->layer['renew'] * 1000,
                'colors' => $this->layer['colors'],
            ],
        ]);
    }

    /** Other collaborators on this page, filled in by `cursors/presence.js` from the cursor events. */
    public function presence(Environment $twig): string
    {
        return null === $this->collaborator() ? '' : $twig->render('@JanalisCollaborate/presence.html.twig');
    }

    /** @return array{Request, UserInterface, int}|null the main request and its user, when it may collaborate */
    private function collaborator(): ?array
    {
        $request = $this->requests->getMainRequest();
        $user = $this->security->getUser();
        if (null === $request || null === $user) {
            return null;
        }
        $id = $this->collaborators->id($user);
        if (null === $id || !$this->security->isGranted($this->role)) {
            return null;
        }

        return [$request, $user, $id];
    }
}
