<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Push;

use Doctrine\ORM\EntityManagerInterface;
use Janalis\Collaborate\Notification\Entity\PushSubscription;
use Psr\Clock\ClockInterface;

/** The browsers a user asked to be pushed to. Not final: the setup command's tests double it. */
readonly class PushSubscriptions
{
    /** The content encodings a push service may ask for; `aesgcm` is the draft one older browsers use. */
    public const array ENCODINGS = ['aes128gcm', 'aesgcm'];

    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {}

    /** @return list<PushSubscription> */
    public function of(int $recipient): array
    {
        return $this->em->getRepository(PushSubscription::class)->findBy(['recipient' => $recipient], ['id' => 'ASC']);
    }

    public function find(int $id): ?PushSubscription
    {
        return $this->em->find(PushSubscription::class, $id);
    }

    /**
     * Saves the subscription of a browser for a user: one already saved for its endpoint is given to that
     * user, with its keys as the browser sends them now.
     */
    public function save(
        int $recipient,
        string $endpoint,
        string $p256dh,
        string $auth,
        string $encoding,
    ): PushSubscription {
        $subscription = $this->byEndpoint($endpoint);
        if (null === $subscription) {
            $subscription = new PushSubscription($recipient, $endpoint, $p256dh, $auth, $encoding, $this->clock->now());
            $this->em->persist($subscription);
        } else {
            $subscription->recipient = $recipient;
            $subscription->p256dh = $p256dh;
            $subscription->auth = $auth;
            $subscription->encoding = $encoding;
        }
        $this->em->flush();

        return $subscription;
    }

    /** Removes the subscription of an endpoint, when it is the user's. */
    public function remove(int $recipient, string $endpoint): void
    {
        $subscription = $this->byEndpoint($endpoint);
        if (null !== $subscription && $subscription->recipient === $recipient) {
            $this->em->remove($subscription);
            $this->em->flush();
        }
    }

    /** A push service said the browser unsubscribed. */
    public function expire(PushSubscription $subscription): void
    {
        $this->em->remove($subscription);
        $this->em->flush();
    }

    public function used(PushSubscription $subscription): void
    {
        $subscription->lastUsedAt = $this->clock->now();
        $this->em->flush();
    }

    /** Removes every subscription: what rotating the VAPID keys makes useless. */
    public function removeAll(): int
    {
        return (int) $this->em->getConnection()->executeStatement('DELETE FROM collaborate_push_subscription');
    }

    private function byEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->em
            ->getRepository(PushSubscription::class)
            ->findOneBy(['endpointHash' => PushSubscription::hash($endpoint)]);
    }
}
