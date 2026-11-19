<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification;

use Doctrine\ORM\EntityManagerInterface;
use Janalis\Collaborate\Notification\Entity\NotificationPreference;
use Psr\Clock\ClockInterface;

/**
 * The topics a user is subscribed to: their choice where they made one, the topic's default otherwise, and
 * always a mandatory topic. Unsubscribed, a user is told nothing of the topic: no row, no bell, no push.
 */
final readonly class Preferences
{
    /** The recipients {@see subscribers()} reads the choices of in one query. */
    private const int CHUNK = 1000;

    public function __construct(
        private EntityManagerInterface $em,
        private NotificationCatalog $catalog,
        private ClockInterface $clock,
    ) {}

    public function isSubscribed(int $recipient, string $topic): bool
    {
        $definition = $this->catalog->topic($topic);
        if (null === $definition) {
            return false;
        }
        if ($definition['mandatory']) {
            return true;
        }

        return $this->choice($recipient, $topic)->subscribed ?? $definition['default'];
    }

    /**
     * Which of several users are subscribed to a configured topic, read in one query: what {@see isSubscribed()}
     * answers for each.
     *
     * @param list<int> $recipients
     *
     * @return array<int, true> the subscribed recipients, as keys
     */
    public function subscribers(array $recipients, string $topic): array
    {
        $definition = $this->catalog->topic($topic);
        if (null === $definition) {
            return [];
        }
        $choices = [];
        // In chunks: a database binds a bounded number of parameters (SQLite 32766, PostgreSQL 65535).
        $chunks = $definition['mandatory'] ? [] : array_chunk(array_unique($recipients), self::CHUNK);
        foreach ($chunks as $chunk) {
            foreach ($this->em
                ->getRepository(NotificationPreference::class)
                ->findBy(['recipient' => $chunk, 'topic' => $topic]) as $choice) {
                $choices[$choice->recipient] = $choice->subscribed;
            }
        }
        $subscribed = [];
        foreach ($recipients as $recipient) {
            if (!$definition['mandatory'] && !($choices[$recipient] ?? $definition['default'])) {
                continue;
            }
            $subscribed[$recipient] = true;
        }

        return $subscribed;
    }

    /**
     * Every topic, in the configured order, with whether the user is subscribed.
     *
     * @return array<string, bool>
     */
    public function all(int $recipient): array
    {
        $choices = [];
        foreach ($this->em
            ->getRepository(NotificationPreference::class)
            ->findBy(['recipient' => $recipient]) as $choice) {
            $choices[$choice->topic] = $choice->subscribed;
        }
        $states = [];
        foreach ($this->catalog->topics as $name => $topic) {
            $states[$name] = $topic['mandatory'] || ($choices[$name] ?? $topic['default']);
        }

        return $states;
    }

    /**
     * @throws \InvalidArgumentException       for a topic the configuration does not declare
     * @throws MandatoryTopicException for a topic nobody may unsubscribe from
     */
    public function choose(int $recipient, string $topic, bool $subscribed): void
    {
        $definition = $this->catalog->topic($topic) ?? throw new \InvalidArgumentException(\sprintf(
            'The notification topic "%s" is not configured.',
            $topic,
        ));
        if ($definition['mandatory']) {
            throw new MandatoryTopicException($topic);
        }
        $choice = $this->choice($recipient, $topic);
        if (null === $choice) {
            $this->em->persist(new NotificationPreference($recipient, $topic, $subscribed, $this->clock->now()));
        } else {
            $choice->subscribed = $subscribed;
            $choice->updatedAt = $this->clock->now();
        }
        $this->em->flush();
    }

    private function choice(int $recipient, string $topic): ?NotificationPreference
    {
        return $this->em->find(NotificationPreference::class, ['recipient' => $recipient, 'topic' => $topic]);
    }
}
