<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Twig;

use Janalis\Collaborate\Bridge\Bridge;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Notification\NotificationAccess;
use Janalis\Collaborate\Security\CurrentUserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
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

    /**
     * Host options at their defaults, which the layer leaves out: a page of a 0.1 host renders as it did. The
     * regions default to the bridge's, which its script profile holds too.
     */
    private const array DEFAULT_HOST = [
        'regions' => [],
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

    /** @var array<string, mixed> */
    private readonly array $defaultHost;

    private readonly Bridge $bridge;

    /**
     * @param array{cursors?: bool, updates?: bool, throttle?: int, heartbeat?: int, expiry?: int, maxAvatars?: int, renew?: int, colors?: list<string>} $layer
     *   `throttle` in milliseconds; `heartbeat`, `expiry` and `renew` in seconds
     * @param array<string, mixed> $host the `cursors` options describing the host's pages, by their camel-cased
     *   names (`idle` in seconds, `popups` kind => {where, line})
     * @param string $bridge the {@see Bridge} the pages belong to, which picks their script profile
     */
    public function __construct(
        private readonly RequestStack $requests,
        private readonly CurrentUserInterface $security,
        private readonly CollaboratorProviderInterface $collaborators,
        private readonly PageGrant $pages,
        private readonly DraftAccess $drafts,
        private readonly string $role = 'ROLE_ADMIN',
        array $layer = [],
        array $host = [],
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?NotificationAccess $notifications = null,
        string $bridge = 'none',
        private readonly ?CsrfTokenManagerInterface $csrf = null,
    ) {
        $this->bridge = Bridge::from($bridge);
        $this->layer = $layer + self::DEFAULT_LAYER;
        $this->defaultHost = ['regions' => $this->bridge->regions()] + self::DEFAULT_HOST;
        $host = array_intersect_key($host, $this->defaultHost);
        if ([] === ($host['regions'] ?? [])) {
            unset($host['regions']);
        }
        $this->host = $host + $this->defaultHost;
    }

    /** The bundle's templates translate and draw tokens through these: they render under Drupal's Twig too. */
    public function getFilters(): array
    {
        return [new TwigFilter('collaborate_trans', $this->translate(...))];
    }

    /**
     * A sentence of the bundle in the user's language (domain `collaborate`), its parameters filled.
     *
     * @param array<string, mixed> $parameters
     */
    public function translate(string $message, array $parameters = [], string $domain = 'collaborate'): string
    {
        if (null === $this->translator) {
            return strtr($message, array_map(static fn(mixed $value): string => \is_scalar($value)
                ? (string) $value
                : '', $parameters));
        }

        return $this->translator->trans($message, $parameters, $domain);
    }

    /** The CSRF token of an id, empty without a token manager. */
    public function csrf(string $id): string
    {
        return null === $this->csrf ? '' : (string) $this->csrf->getToken($id);
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('collaborate_csrf', $this->csrf(...)),
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
            // Notifications: their runtime is made only when a template calls one.
            new TwigFunction(
                'collaborate_notifications',
                [NotificationRuntime::class, 'bell'],
                [
                    'needs_environment' => true,
                    'is_safe' => ['html'],
                ],
            ),
            new TwigFunction('collaborate_notification_text', [NotificationRuntime::class, 'text']),
            new TwigFunction('collaborate_notification_icon', [NotificationRuntime::class, 'icon']),
            new TwigFunction('collaborate_notification_types', [NotificationRuntime::class, 'types']),
            new TwigFunction('collaborate_notification_topics', [NotificationRuntime::class, 'topics']),
            new TwigFunction('collaborate_notifications_settings', [NotificationRuntime::class, 'settings']),
            new TwigFunction('collaborate_notifications_layout', [NotificationRuntime::class, 'layout']),
        ];
    }

    /** Whether shared drafts are on. */
    public function enabled(): bool
    {
        return $this->drafts->enabled;
    }

    /**
     * The page grant of the current page, or null when the signed-in user may not collaborate on it. A page
     * in no place, or a user who may not collaborate, still gets a bare grant when notifications are on: its
     * stream carries the notifications of its user alone.
     */
    public function grant(): ?string
    {
        $collaborator = $this->collaborator();
        if (null !== $collaborator) {
            [$request, $user, $id] = $collaborator;
            $grant = $this->pages->issue($request, $id, time(), $this->collaborators->authorization($user));
            if (null !== $grant) {
                return $grant;
            }
        }
        $recipient = $this->notifications?->streams() ? $this->notifications->recipient() : null;
        $user = $this->security->getUser();
        if (null === $recipient || null === $user) {
            return null;
        }

        return $this->pages->issueBare($recipient, time(), $this->collaborators->authorization($user));
    }

    public function layer(Environment $twig): string
    {
        $grant = $this->grant();
        if (null === $grant) {
            return '';
        }

        // A bare grant names no room: no cursors nor saved views on its page.
        $bare = PageGrant::isBare($grant);

        return $twig->render('@JanalisCollaborate/layer.html.twig', [
            'grant' => $grant,
            'cursors' => $this->layer['cursors'] && !$bare,
            'updates' => $this->layer['updates'] && !$bare,
            'config' =>
                [
                    'bridge' => $this->bridge->value,
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
            if ($this->host[$name] === $this->defaultHost[$name]) {
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
