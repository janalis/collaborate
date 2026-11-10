<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Bridge\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityUpdatedEvent;
use Janalis\Collaborate\Bridge\EasyAdmin\SaveSubscriber;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Tests\Fixtures\Draft\Article;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SaveSubscriber::class)]
final class SaveSubscriberTest extends TestCase
{
    public function testACreatedOrUpdatedRecordCompletesTheLockedSave(): void
    {
        $created = new Article();
        $updated = new Article();
        $save = $this->createMock(DraftSave::class);
        $completed = [];
        $save
            ->expects($this->exactly(2))
            ->method('completed')
            ->willReturnCallback(static function (object $entity) use (&$completed): void {
                $completed[] = $entity;
            });
        $subscriber = new SaveSubscriber($save);

        $subscriber->completed(new AfterEntityPersistedEvent($created));
        $subscriber->completed(new AfterEntityUpdatedEvent($updated));

        self::assertSame([$created, $updated], $completed);
        self::assertSame(
            [AfterEntityPersistedEvent::class => 'completed', AfterEntityUpdatedEvent::class => 'completed'],
            SaveSubscriber::getSubscribedEvents(),
        );
    }
}
