<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification;

use Doctrine\ORM\EntityManagerInterface;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\Notice;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Notification\Entity\Notification;
use Janalis\Collaborate\Notification\Messenger\SendNotification;
use Janalis\Collaborate\Notification\Push\PushDispatcher;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Tells users something: the row their bell lists, then their pages told through the hub that their list
 * changed (never what it says), then their browsers pushed to when web push is on.
 *
 * Without a bus, {@see notify()} writes at once; with `notifications.bus`, it dispatches a
 * {@see SendNotification} per recipient, which its handler writes wherever the host routes it.
 */
final readonly class Notifier
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationCatalog $catalog,
        private Preferences $preferences,
        private NotificationRepository $notifications,
        private CollaboratorProviderInterface $collaborators,
        private HubPublisher $publisher,
        private Topics $topics,
        private ClockInterface $clock,
        private ?PushDispatcher $pushes = null,
        private ?MessageBusInterface $bus = null,
    ) {}

    /**
     * Notifies a user, unless they unsubscribed from the topic of the type. The row is flushed here, with
     * whatever else the entity manager holds, before the pages of its reader are told: an update published
     * before the commit would be answered without it.
     *
     * @param UserInterface|int     $to         a user, or the id {@see CollaboratorProviderInterface::id()} gives
     * @param array<string, string> $parameters the values of the placeholders of the sentence of the type
     * @param string|null           $url        where a click on the notification leads
     *
     * @throws \InvalidArgumentException for a type the configuration does not declare
     */
    public function notify(UserInterface|int $to, string $type, array $parameters = [], ?string $url = null): void
    {
        $this->catalog->type($type);
        $recipient = $to instanceof UserInterface ? $this->collaborators->id($to) : $to;
        if (null === $recipient) {
            return;
        }
        if (null !== $this->bus) {
            $this->dispatch($this->bus, $recipient, $type, $parameters, $url);

            return;
        }
        $this->write($recipient, $type, $parameters, $url, $this->clock->now());
    }

    /**
     * Notifies each of several users, as {@see notify()} does: without a bus, their subscriptions are read in one
     * query and their rows flushed at once, before any of their pages is told.
     *
     * @param iterable<UserInterface|int> $to
     * @param array<string, string>       $parameters
     */
    public function notifyAll(iterable $to, string $type, array $parameters = [], ?string $url = null): void
    {
        $topic = $this->catalog->type($type)['topic'];
        $recipients = [];
        foreach ($to as $user) {
            $recipient = $user instanceof UserInterface ? $this->collaborators->id($user) : $user;
            if (null !== $recipient) {
                $recipients[] = $recipient;
            }
        }
        if (null !== $this->bus) {
            foreach ($recipients as $recipient) {
                $this->dispatch($this->bus, $recipient, $type, $parameters, $url);
            }

            return;
        }
        $subscribed = $this->preferences->subscribers($recipients, $topic);
        $at = $this->clock->now();
        $written = [];
        foreach ($recipients as $recipient) {
            if (!isset($subscribed[$recipient])) {
                continue;
            }
            $notification = new Notification($recipient, $topic, $type, $parameters, $url, $at);
            $this->em->persist($notification);
            $written[] = $notification;
        }
        if ([] === $written) {
            return;
        }
        $this->em->flush();
        foreach ($written as $notification) {
            $this->tell($notification);
        }
    }

    /**
     * Writes a notification now, whatever the bus: what {@see notify()} and the message handler do. A key a
     * row already holds writes nothing, and only tells the pages and browsers again, which is harmless.
     *
     * @param array<string, string> $parameters
     *
     * @return Notification|null null when the user unsubscribed from the topic of the type
     */
    public function write(
        int $recipient,
        string $type,
        array $parameters,
        ?string $url,
        \DateTimeImmutable $at,
        ?string $key = null,
    ): ?Notification {
        $topic = $this->catalog->type($type)['topic'];
        if (!$this->preferences->isSubscribed($recipient, $topic)) {
            return null;
        }
        $notification = null === $key ? null : $this->notifications->findByMessage($key);
        if (null === $notification) {
            $notification = new Notification($recipient, $topic, $type, $parameters, $url, $at, $key);
            $this->em->persist($notification);
            $this->em->flush();
        }
        $this->tell($notification);

        return $notification;
    }

    /** Once its row is flushed: the pages of its reader are told, and their browsers pushed to. */
    private function tell(Notification $notification): void
    {
        $this->changed($notification->recipient);
        $this->pushes?->push($notification);
    }

    /** Tells the pages of a user that their notifications changed: they ask for them again. */
    public function changed(int $recipient): void
    {
        $this->publisher->defer(
            Notice::notifications($this->topics->notifications($recipient)),
            'Collaborate could not tell the pages of a user of their notifications.',
        );
    }

    /** @param array<string, string> $parameters */
    private function dispatch(
        MessageBusInterface $bus,
        int $recipient,
        string $type,
        array $parameters,
        ?string $url,
    ): void {
        $bus->dispatch(
            new SendNotification(
                $recipient,
                $type,
                $parameters,
                $url,
                $this->clock->now()->format(\DATE_ATOM),
                self::key(),
            ),
        );
    }

    /** A random version 4 UUID: the key of a message. */
    private static function key(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        $key = bin2hex($bytes);
        // The dashes of the 8-4-4-4-12 form, each where the ones before it moved the next.
        foreach ([8, 13, 18, 23] as $at) {
            $key = substr_replace($key, '-', $at, 0);
        }

        return $key;
    }
}
