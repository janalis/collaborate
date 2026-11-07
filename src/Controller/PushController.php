<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Controller;

use Janalis\Collaborate\Notification\NotificationAccess;
use Janalis\Collaborate\Notification\Push\PushSubscriptions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Web push: the service worker of the bundle, and the subscription of a browser to the pushes of its user.
 *
 * A subscription carries a token: a forged one would hand the notifications of its user to the endpoint of
 * whoever forged it.
 */
final readonly class PushController
{
    public const string CSRF_TOKEN_ID = 'collaborate-push';

    /** Longest endpoint a push service hands a browser; theirs are well under it. */
    private const int MAX_ENDPOINT = 2048;

    public function __construct(
        private NotificationAccess $access,
        private PushSubscriptions $subscriptions,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
        private string $worker,
        private bool $enabled = false,
        private ?string $icon = null,
        private ?TranslatorInterface $translator = null,
    ) {}

    /**
     * `GET <prefix>/notifications/push/worker.js`: the service worker, allowed to control every page of the
     * origin, preceded by what it needs to know of the host.
     */
    public function worker(): Response
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException('Web push is off.');
        }
        $settings = [
            'read' => $this->urls->generate('collaborate_notifications_change', ['action' => 'read']),
            'title' => $this->translator?->trans('New notification', [], 'collaborate') ?? 'New notification',
            'body' =>
                $this->translator?->trans('You have a new notification.', [], 'collaborate')
                    ?? 'You have a new notification.',
            'icon' => $this->icon,
        ];

        return new Response(
            'self.collaborateNotifications = '
                . json_encode($settings, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG)
                . ";\n"
                // The script of the bundle, which it reads whole.
                // @mago-expect analysis:possibly-false-operand
                . file_get_contents($this->worker),
            headers: [
                'Content-Type' => 'text/javascript; charset=utf-8',
                'Service-Worker-Allowed' => '/',
                'Cache-Control' => 'no-cache',
            ],
        );
    }

    /** `POST <prefix>/notifications/push/{subscribe|unsubscribe}`: the JSON of a `PushSubscription`. */
    public function subscription(Request $request, string $action): JsonResponse
    {
        if (!$this->enabled || !$this->access->enabled) {
            throw new NotFoundHttpException('Web push is off.');
        }
        $recipient = $this->access->recipient() ?? throw new AccessDeniedException(
            'Web push requires a signed-in user.',
        );
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->headers->get('X-CSRF-Token')))) {
            throw new AccessDeniedHttpException('Invalid web push CSRF token.');
        }
        if (\strlen($request->getContent()) > (4 * self::MAX_ENDPOINT)) {
            throw new BadRequestHttpException('A push subscription is a few hundred bytes.');
        }
        $data = $request->toArray();
        $endpoint = self::string($data, 'endpoint');
        if (
            null === $endpoint
            || \strlen($endpoint) > self::MAX_ENDPOINT
            || !str_starts_with($endpoint, 'https://')
            || false === filter_var($endpoint, \FILTER_VALIDATE_URL)
        ) {
            throw new BadRequestHttpException('A push subscription names the https endpoint of its push service.');
        }
        if ('unsubscribe' === $action) {
            $this->subscriptions->remove($recipient, $endpoint);

            return new JsonResponse(['subscribed' => false], headers: ['Cache-Control' => 'no-store']);
        }
        $keys = \is_array($data['keys'] ?? null) ? $data['keys'] : [];
        $p256dh = self::string($keys, 'p256dh');
        $auth = self::string($keys, 'auth');
        $encoding = self::string($data, 'encoding') ?? 'aes128gcm';
        if (
            null === $p256dh
            || 1 !== preg_match('/^[A-Za-z0-9_-]{80,100}={0,2}$/D', $p256dh)
            || null === $auth
            || 1 !== preg_match('/^[A-Za-z0-9_-]{16,32}={0,2}$/D', $auth)
            || !\in_array($encoding, PushSubscriptions::ENCODINGS, true)
        ) {
            throw new BadRequestHttpException(
                'A push subscription carries its p256dh and auth keys, and a known encoding.',
            );
        }
        $this->subscriptions->save($recipient, $endpoint, $p256dh, $auth, $encoding);

        return new JsonResponse(['subscribed' => true], headers: ['Cache-Control' => 'no-store']);
    }

    /** @param array<array-key, mixed> $data */
    private static function string(array $data, string $key): ?string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : null;
    }
}
