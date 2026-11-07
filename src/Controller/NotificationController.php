<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Controller;

use Janalis\Collaborate\Notification\Entity\Notification;
use Janalis\Collaborate\Notification\MandatoryTopicException;
use Janalis\Collaborate\Notification\NotificationAccess;
use Janalis\Collaborate\Notification\NotificationCatalog;
use Janalis\Collaborate\Notification\NotificationRepository;
use Janalis\Collaborate\Notification\Notifier;
use Janalis\Collaborate\Notification\Preferences;
use Psr\Clock\ClockInterface;
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
use Twig\Environment;

/**
 * The notifications of the signed-in user: the latest ones under the bell, the page of them all, what reads,
 * unreads or clears them, and the topics the user subscribes to. Each action reads and changes the user's
 * own alone, and tells their other pages through the hub.
 *
 * Read, unread and clear carry no token: a forged one changes the notifications of whoever was made to send
 * it, and nothing else. A topic does: a forged unsubscribe would silence its user.
 */
final readonly class NotificationController
{
    public const string TOPICS_CSRF_TOKEN_ID = 'collaborate-notification-topics';

    public function __construct(
        private NotificationAccess $access,
        private NotificationRepository $notifications,
        private NotificationCatalog $catalog,
        private Preferences $preferences,
        private Notifier $notifier,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $csrf,
        private ClockInterface $clock,
        // The configuration's (`latest`, `page_size`), which holds their defaults.
        private int $latest,
        private int $pageSize,
        private string $pageTemplate = '@JanalisCollaborate/notifications/page.html.twig',
        private string $topicsTemplate = '@JanalisCollaborate/notifications/topics.html.twig',
    ) {}

    /** `GET <prefix>/notifications`: the content of the menu of the bell, a fragment of a page. */
    public function list(): Response
    {
        $recipient = $this->recipient();

        return $this->render('@JanalisCollaborate/notifications/_list.html.twig', [
            'notifications' => $this->notifications->findLatest($recipient, $this->latest),
            'unread' => $this->notifications->countUnread($recipient),
            'total' => $this->notifications->countAll($recipient),
        ]);
    }

    /**
     * `GET <prefix>/notifications/all`: every notification of the user, the latest first, a page at a time;
     * the older ones are asked for as the reader scrolls, and answered as the rows alone.
     */
    public function page(Request $request): Response
    {
        $recipient = $this->recipient();
        $query = $request->query;
        $type = $query->has('type') ? $query->getString('type') : null;
        $topic = $query->has('topic') ? $query->getString('topic') : null;
        $before = $query->getString('before');
        if (
            null !== $type && !isset($this->catalog->types[$type])
            || null !== $topic && !isset($this->catalog->topics[$topic])
            || $query->has('before') && !ctype_digit($before)
        ) {
            throw new BadRequestHttpException(
                'The notifications are filtered by a configured type or topic, before one of them.',
            );
        }
        $unreadOnly = $query->getBoolean('unread');
        // One more than a page holds tells whether older ones exist.
        $limit = $this->pageSize;
        ++$limit;
        $found = $this->notifications->findBefore(
            $recipient,
            $query->has('before') ? (int) $before : null,
            $unreadOnly,
            $type,
            $topic,
            $limit,
        );
        $rows = \array_slice($found, 0, $this->pageSize);
        $last = $rows[$this->pageSize - 1] ?? null;
        // The router leaves a null parameter out of the query.
        $filters = ['unread' => $unreadOnly ? 1 : null, 'type' => $type, 'topic' => $topic];

        return $this->render(
            $request->isXmlHttpRequest() ? '@JanalisCollaborate/notifications/_rows.html.twig' : $this->pageTemplate,
            [
                'notifications' => $rows,
                'next' =>
                    \count($found) > $this->pageSize && $last instanceof Notification
                        ? $this->urls->generate('collaborate_notifications_page', $filters + ['before' => $last->id])
                        : null,
                'src' => $this->urls->generate('collaborate_notifications_page', $filters),
                'unread_only' => $unreadOnly,
                'type' => $type,
                'topic' => $topic,
            ],
        );
    }

    /** `POST <prefix>/notifications/{read|unread|clear}`: one notification of the user (`id`), or all of them. */
    public function change(Request $request, string $action): JsonResponse
    {
        $recipient = $this->recipient();
        // @mago-expect analysis:mixed-assignment a field of a form is mixed: the next lines narrow it
        $field = $request->request->all()['id'] ?? null;
        $id = null;
        if (\is_string($field) && ctype_digit($field)) {
            $id = (int) $field;
        } elseif (null !== $field && '' !== $field) {
            throw new BadRequestHttpException('A notification is named by its id.');
        }
        match ($action) {
            'read' => $this->notifications->markRead($recipient, $id, $this->clock->now()),
            'unread' => $this->notifications->markUnread($recipient, $id),
            default => $this->notifications->clear($recipient, $id),
        };
        $this->notifier->changed($recipient);

        return $this->counts($recipient);
    }

    /** `GET <prefix>/notifications/topics`: what the user is subscribed to; a fragment for a script. */
    public function topics(Request $request): Response
    {
        $recipient = $this->recipient();

        return $this->render(
            $request->isXmlHttpRequest()
                ? '@JanalisCollaborate/notifications/_topics.html.twig'
                : $this->topicsTemplate,
            ['subscribed' => $this->preferences->all($recipient)],
        );
    }

    /** `POST <prefix>/notifications/topics/{topic}/{subscribe|unsubscribe}`, with the token of the topics. */
    public function choose(Request $request, string $topic, string $action): JsonResponse
    {
        $recipient = $this->recipient();
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');
        if (!$this->csrf->isTokenValid(new CsrfToken(self::TOPICS_CSRF_TOKEN_ID, $token))) {
            throw new AccessDeniedHttpException('Invalid notification topics CSRF token.');
        }
        if (!isset($this->catalog->topics[$topic])) {
            throw new NotFoundHttpException(\sprintf('No notification topic "%s".', $topic));
        }
        try {
            $this->preferences->choose($recipient, $topic, 'subscribe' === $action);
        } catch (MandatoryTopicException $error) {
            throw new AccessDeniedHttpException($error->getMessage(), $error);
        }
        $this->notifier->changed($recipient);

        return new JsonResponse(['topic' => $topic, 'subscribed' => 'subscribe' === $action], headers: [
            'Cache-Control' => 'no-store',
        ]);
    }

    /** The signed-in user's recipient id: 404 while notifications are off, 403 without their role. */
    private function recipient(): int
    {
        if (!$this->access->enabled) {
            throw new NotFoundHttpException('Notifications are off.');
        }

        // An AccessDeniedException sends a visitor to the entry point of the firewall: the sign-in form.
        return $this->access->recipient() ?? throw new AccessDeniedException('Notifications require a signed-in user.');
    }

    private function counts(int $recipient): JsonResponse
    {
        return new JsonResponse([
            'unread' => $this->notifications->countUnread($recipient),
            'total' => $this->notifications->countAll($recipient),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context): Response
    {
        return new Response($this->twig->render($template, $context), headers: ['Cache-Control' => 'no-store']);
    }
}
