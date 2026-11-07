<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Twig;

use Janalis\Collaborate\Controller\NotificationController;
use Janalis\Collaborate\Controller\PushController;
use Janalis\Collaborate\Notification\Entity\Notification;
use Janalis\Collaborate\Notification\NotificationAccess;
use Janalis\Collaborate\Notification\NotificationCatalog;
use Janalis\Collaborate\Notification\NotificationRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * The bell of the notifications (`collaborate_notifications()`), and what its templates and the host's say
 * of a notification: its sentence, its icon, the types and topics a page filters by. A runtime, which Twig
 * makes the first time a template calls one of them: a page without notifications reads none of it.
 */
final class NotificationRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly NotificationAccess $access,
        private readonly NotificationRepository $notifications,
        private readonly NotificationCatalog $catalog,
        private readonly UrlGeneratorInterface $urls,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly int $latest = 20,
        private readonly int $maxCount = 99,
        private readonly bool $push = false,
        private readonly ?string $publicKey = null,
        private readonly ?string $serviceWorker = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly string $layout = 'base.html.twig',
    ) {}

    /** The bell of the signed-in user, its count and latest notifications; nothing for whoever is told none. */
    public function layout(): string
    {
        return $this->layout;
    }

    public function bell(Environment $twig): string
    {
        $recipient = $this->access->recipient();
        if (null === $recipient) {
            return '';
        }

        return $twig->render('@JanalisCollaborate/notifications/bell.html.twig', [
            'notifications' => $this->notifications->findLatest($recipient, $this->latest),
            'unread' => $this->notifications->countUnread($recipient),
            'total' => $this->notifications->countAll($recipient),
            'settings' => $this->settings(),
        ]);
    }

    /**
     * What the scripts of the bell, of the page and of the topics read: where they ask, and web push.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'src' => $this->urls->generate('collaborate_notifications'),
            'page' => $this->urls->generate('collaborate_notifications_page'),
            'topics' => $this->urls->generate('collaborate_notification_topics'),
            'read' => $this->urls->generate('collaborate_notifications_change', ['action' => 'read']),
            'unread' => $this->urls->generate('collaborate_notifications_change', ['action' => 'unread']),
            'clear' => $this->urls->generate('collaborate_notifications_change', ['action' => 'clear']),
            'topicsToken' => $this->csrf->getToken(NotificationController::TOPICS_CSRF_TOKEN_ID)->getValue(),
            'maxCount' => $this->maxCount,
            'push' => $this->push && null !== $this->publicKey
                ? [
                    'key' => $this->publicKey,
                    'worker' => $this->serviceWorker ?? $this->urls->generate('collaborate_push_worker'),
                    'subscribe' => $this->urls->generate('collaborate_push_subscription', ['action' => 'subscribe']),
                    'unsubscribe' => $this->urls->generate('collaborate_push_subscription', [
                        'action' => 'unsubscribe',
                    ]),
                    'token' => $this->csrf->getToken(PushController::CSRF_TOKEN_ID)->getValue(),
                ]
                : null,
        ];
    }

    /** The sentence of a notification, in the language of the page. */
    public function text(Notification $notification): string
    {
        $type = $this->catalog->types[$notification->type] ?? null;
        if (null === $type) {
            return '';
        }

        return (
            $this->translator?->trans($type['sentence'], $notification->parameters, $type['domain']) ?? strtr(
                $type['sentence'],
                $notification->parameters,
            )
        );
    }

    public function icon(Notification $notification): ?string
    {
        return $this->catalog->types[$notification->type]['icon'] ?? null;
    }

    /**
     * The types a page filters by, name => label, those with one.
     *
     * @return array<string, string>
     */
    public function types(): array
    {
        $labels = [];
        foreach ($this->catalog->types as $name => $type) {
            if (null === $type['label']) {
                continue;
            }
            $labels[$name] = $this->translator?->trans($type['label'], [], $type['domain']) ?? $type['label'];
        }

        return $labels;
    }

    /**
     * The topics, name => their label and description translated, and whether nobody may leave them.
     *
     * @return array<string, array{label: string, description: string|null, mandatory: bool}>
     */
    public function topics(): array
    {
        $topics = [];
        foreach ($this->catalog->topics as $name => $topic) {
            $trans = fn(string $message): string => $this->translator?->trans($message, [], $topic['domain'])
            ?? $message;
            $topics[$name] = [
                'label' => $trans($topic['label']),
                'description' => null === $topic['description'] ? null : $trans($topic['description']),
                'mandatory' => $topic['mandatory'],
            ];
        }

        return $topics;
    }
}
