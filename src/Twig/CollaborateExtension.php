<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Twig;

use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
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

    /** Host options at their defaults, which the layer leaves out: a page of a 0.1 host renders as it did. */
    private const array DEFAULT_HOST = [
        'regions' => CollaborateBundle::REGIONS,
        'keyAttribute' => null,
        'keyScope' => 'body',
        'anchorAttributes' => CollaborateBundle::ANCHOR_ATTRIBUTES,
        'anchorClasses' => false,
        'caretRegions' => [],
        'popups' => [],
        'idle' => 0,
        'markers' => false,
        'edges' => null,
        'topLayer' => false,
        'presence' => 'avatars',
        'distinctColors' => false,
        'shareStream' => false,
        'silentAlone' => false,
    ];

    /** The sentences the cursor scripts say, translated in the collaborate domain. */
    public const array MESSAGES = [
        'showPointers' => 'Show pointers',
        'idle' => 'idle',
        'on' => 'on %name%',
        'here' => 'on this page',
        'is' => '%name% is %place%',
        'line' => 'Also open by %names%.',
        'where' => 'in %name%',
    ];

    /** @var Layer */
    private readonly array $layer;

    /** @var array<string, mixed> */
    private readonly array $host;

    /**
     * @param array{cursors?: bool, updates?: bool, throttle?: int, heartbeat?: int, expiry?: int, maxAvatars?: int, renew?: int, colors?: list<string>} $layer
     *   `throttle` in milliseconds; `heartbeat`, `expiry` and `renew` in seconds
     * @param array<string, mixed> $host the `cursors` options describing the host's pages, by their camel-cased
     *   names (`idle` in seconds, `popups` kind => {where, line})
     */
    public function __construct(
        private readonly RequestStack $requests,
        private readonly Security $security,
        private readonly CollaboratorProviderInterface $collaborators,
        private readonly PageGrant $pages,
        private readonly DraftAccess $drafts,
        private readonly string $role = 'ROLE_ADMIN',
        array $layer = [],
        array $host = [],
        private readonly ?TranslatorInterface $translator = null,
    ) {
        $this->layer = $layer + self::DEFAULT_LAYER;
        $this->host = array_intersect_key($host, self::DEFAULT_HOST) + self::DEFAULT_HOST;
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
            'config' =>
                [
                    'throttle' => $this->layer['throttle'],
                    'heartbeat' => $this->layer['heartbeat'] * 1000,
                    'expiry' => $this->layer['expiry'] * 1000,
                    'maxAvatars' => $this->layer['maxAvatars'],
                    'renew' => $this->layer['renew'] * 1000,
                    'colors' => $this->layer['colors'],
                ] + $this->hostConfig(),
        ]);
    }

    /**
     * The host options that differ from their defaults, with the sentences the scripts say once any does.
     *
     * @return array<string, mixed>
     */
    private function hostConfig(): array
    {
        $config = [];
        foreach (array_keys($this->host) as $name) {
            if ($this->host[$name] === self::DEFAULT_HOST[$name]) {
                continue;
            }
            $config[$name] = $this->hostOption($name, $this->host[$name]);
        }
        if ([] === $config) {
            return [];
        }
        $config['messages'] = array_map($this->trans(...), self::MESSAGES);

        return $config;
    }

    /** A host option as the scripts read it. */
    private function hostOption(string $name, mixed $value): mixed
    {
        return match ($name) {
            'idle' => (\is_int($value) ? $value : 0) * 1000,
            'popups' => $this->popups($value),
            default => $value,
        };
    }

    /** @return array<string, array{where: string, line: string}> */
    private function popups(mixed $popups): array
    {
        $translated = [];
        $popups = \is_array($popups) ? $popups : [];
        foreach (array_keys($popups) as $kind) {
            $messages = \is_array($popups[$kind]) ? $popups[$kind] : [];
            // @infection-ignore-all: a popup kind is a name starting with a letter, never an integer key.
            $translated[(string) $kind] = [
                'where' => $this->trans(
                    \is_string($messages['where'] ?? null) ? $messages['where'] : self::MESSAGES['where'],
                ),
                'line' => $this->trans(
                    \is_string($messages['line'] ?? null) ? $messages['line'] : self::MESSAGES['line'],
                ),
            ];
        }

        return $translated;
    }

    /** A sentence in the user's language, its placeholders left for the scripts to fill. */
    private function trans(string $message): string
    {
        return $this->translator?->trans($message, [], 'collaborate') ?? $message;
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
