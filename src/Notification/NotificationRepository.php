<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Janalis\Collaborate\Notification\Entity\Notification;

/** The notifications of the users, each of them reading and changing their own alone. */
final readonly class NotificationRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * The latest notifications of a user, the most recent first.
     *
     * @return list<Notification>
     */
    public function findLatest(int $recipient, int $limit): array
    {
        return $this->em->getRepository(Notification::class)->findBy(
            ['recipient' => $recipient],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
            $limit,
        );
    }

    public function countUnread(int $recipient): int
    {
        return $this->em->getRepository(Notification::class)->count(['recipient' => $recipient, 'readAt' => null]);
    }

    public function countAll(int $recipient): int
    {
        return $this->em->getRepository(Notification::class)->count(['recipient' => $recipient]);
    }

    public function find(int $id): ?Notification
    {
        return $this->em->find(Notification::class, $id);
    }

    /** The notification a message wrote, so that its redelivery writes nothing. */
    public function findByMessage(string $key): ?Notification
    {
        return $this->em->getRepository(Notification::class)->findOneBy(['messageKey' => $key]);
    }

    /**
     * The notifications of a user older than one of them (the latest without one), the most recent first, by
     * their id: the cursor the page of all of them scrolls by. Only the unread ones, or those of a type or a
     * topic, when asked.
     *
     * @return list<Notification>
     */
    public function findBefore(
        int $recipient,
        ?int $before,
        bool $unreadOnly,
        ?string $type,
        ?string $topic,
        int $limit,
    ): array {
        $builder = $this->em
            ->createQueryBuilder()
            ->select('n')
            ->from(Notification::class, 'n')
            ->where('n.recipient = :recipient')
            ->setParameter('recipient', $recipient)
            ->orderBy('n.id', 'DESC')
            ->setMaxResults($limit);
        if (null !== $before) {
            $builder->andWhere('n.id < :before')->setParameter('before', $before);
        }
        if ($unreadOnly) {
            $builder->andWhere('n.readAt IS NULL');
        }
        if (null !== $type) {
            $builder->andWhere('n.type = :type')->setParameter('type', $type);
        }
        if (null !== $topic) {
            $builder->andWhere('n.topic = :topic')->setParameter('topic', $topic);
        }

        /** @var list<Notification> */
        return $builder->getQuery()->getResult();
    }

    /** Marks one notification of a user read, or all of them; another user's is left as it is. */
    public function markRead(int $recipient, ?int $id, \DateTimeImmutable $at): void
    {
        $this->execute(
            $this
                ->update()
                ->set('n.readAt', ':at')
                ->andWhere('n.readAt IS NULL')
                ->setParameter('at', $at, 'datetime_immutable'),
            $recipient,
            $id,
        );
    }

    /** Marks one notification of a user unread, or all of them. */
    public function markUnread(int $recipient, ?int $id): void
    {
        $this->execute($this->update()->set('n.readAt', 'NULL'), $recipient, $id);
    }

    /** Deletes one notification of a user, or all of them. */
    public function clear(int $recipient, ?int $id): void
    {
        $this->execute($this->em->createQueryBuilder()->delete(Notification::class, 'n'), $recipient, $id);
    }

    /** Deletes every notification of a user, as an application does when it removes the user. */
    public function forget(int $recipient): void
    {
        $this->clear($recipient, null);
    }

    private function update(): QueryBuilder
    {
        return $this->em->createQueryBuilder()->update(Notification::class, 'n');
    }

    private function execute(QueryBuilder $builder, int $recipient, ?int $id): void
    {
        $builder->andWhere('n.recipient = :recipient')->setParameter('recipient', $recipient);
        if (null !== $id) {
            $builder->andWhere('n.id = :id')->setParameter('id', $id);
        }
        $builder->getQuery()->execute();
    }
}
