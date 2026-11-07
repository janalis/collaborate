<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Push;

use Janalis\Collaborate\Notification\Entity\Notification;
use Janalis\Collaborate\Notification\Entity\PushSubscription;
use Janalis\Collaborate\Notification\Messenger\SendWebPush;
use Janalis\Collaborate\Notification\NotificationRepository;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Pushes a notification to the browsers of its reader once it is committed: after the response (a push
 * service is a remote request), or as one {@see SendWebPush} message per browser through the bus. A reader
 * whose page holds the stream of their notifications is pushed nothing ({@see OnlineRecipients}).
 */
final class PushDispatcher implements EventSubscriberInterface, ResetInterface
{
    /** @var list<Notification> */
    private array $pending = [];

    public function __construct(
        private readonly PushSubscriptions $subscriptions,
        private readonly NotificationRepository $notifications,
        private readonly PushSenderInterface $sender,
        private readonly PushPayload $payload,
        private readonly OnlineRecipients $online,
        private readonly ?MessageBusInterface $bus = null,
        private readonly bool $enabled = true,
        private readonly PushEndpoints $endpoints = new PushEndpoints(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        // After the hub's notices (HubPublisher, at 0): a slow push service delays nothing a page waits for.
        return [
            KernelEvents::TERMINATE => ['flush', -16],
            ConsoleEvents::TERMINATE => ['flush', -16],
            'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent' => ['flush', -16],
            'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent' => ['flush', -16],
        ];
    }

    public function push(Notification $notification): void
    {
        // An env-driven `web_push.enabled` registers the dispatcher whatever it says.
        if (!$this->enabled) {
            return;
        }
        if (null === $this->bus) {
            $this->pending[] = $notification;

            return;
        }
        $id = $notification->id;
        \assert(null !== $id, 'A notification is pushed once it is saved.');
        foreach ($this->subscriptions->of($notification->recipient) as $subscription) {
            \assert(null !== $subscription->id);
            $this->bus->dispatch(new SendWebPush($subscription->id, $id));
        }
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $notification) {
            $subscriptions = $this->subscriptions->of($notification->recipient);
            if ([] === $subscriptions || $this->online->isOnline($notification->recipient)) {
                continue;
            }
            $payload = $this->payload->of($notification);
            foreach ($subscriptions as $subscription) {
                $this->send($subscription, $payload);
            }
        }
    }

    /** What a {@see SendWebPush} asks for: nothing when the browser or the notification is gone. */
    public function sendById(int $subscription, int $notification): void
    {
        $browser = $this->subscriptions->find($subscription);
        $row = $this->notifications->find($notification);
        if (null === $browser || null === $row || $browser->recipient !== $row->recipient) {
            return;
        }
        if ($this->online->isOnline($row->recipient)) {
            return;
        }
        $this->send($browser, $this->payload->of($row));
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    private function send(PushSubscription $subscription, ?string $payload): void
    {
        // A subscription saved before its host was refused, or since the allowed hosts changed: never requested.
        if (!$this->endpoints->allows($subscription->endpoint)) {
            $this->subscriptions->expire($subscription);

            return;
        }
        match ($this->sender->send($subscription, $payload)) {
            PushResult::Sent => $this->subscriptions->used($subscription),
            PushResult::Expired => $this->subscriptions->expire($subscription),
            PushResult::Failed => null,
        };
    }
}
