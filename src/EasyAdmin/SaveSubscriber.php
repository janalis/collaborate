<?php

declare(strict_types=1);

namespace Janalis\Collaborate\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityUpdatedEvent;
use Janalis\Collaborate\Draft\DraftSave;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Tells the locked save of a shared draft that EasyAdmin has persisted its record. */
final readonly class SaveSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DraftSave $save,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            AfterEntityPersistedEvent::class => 'completed',
            AfterEntityUpdatedEvent::class => 'completed',
        ];
    }

    /**
     * @template TEntity of object
     *
     * @param AfterEntityPersistedEvent<TEntity>|AfterEntityUpdatedEvent<TEntity> $event
     */
    public function completed(AfterEntityPersistedEvent|AfterEntityUpdatedEvent $event): void
    {
        $this->save->completed($event->getEntityInstance());
    }
}
