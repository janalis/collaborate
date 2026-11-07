<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Integration;

use Janalis\Collaborate\Controller\NotificationController;
use Janalis\Collaborate\Controller\PushController;
use Janalis\Collaborate\Notification\NotificationAccess;
use Janalis\Collaborate\Tests\Application\Plain\PlainKernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/** An application whose notifications are off: their routes are none, and nobody is a recipient. */
#[CoversClass(NotificationController::class)]
#[CoversClass(PushController::class)]
#[CoversClass(NotificationAccess::class)]
#[UsesClass(\Janalis\Collaborate\Cursor\PageGrant::class)]
#[UsesClass(\Janalis\Collaborate\Doctrine\ChangeCollector::class)]
#[UsesClass(\Janalis\Collaborate\Doctrine\RecordIdentifier::class)]
#[UsesClass(\Janalis\Collaborate\Draft\DraftAccess::class)]
#[UsesClass(\Janalis\Collaborate\Draft\DraftJournal::class)]
#[UsesClass(\Janalis\Collaborate\Draft\DraftSave::class)]
#[UsesClass(\Janalis\Collaborate\Draft\EventValidator::class)]
#[UsesClass(\Janalis\Collaborate\EasyAdmin\UpdatesSubscriber::class)]
#[UsesClass(\Janalis\Collaborate\Hub\HubPublisher::class)]
#[UsesClass(\Janalis\Collaborate\Hub\RenewSubscriber::class)]
#[UsesClass(\Janalis\Collaborate\Hub\Topics::class)]
#[UsesClass(\Janalis\Collaborate\Notification\NotificationCatalog::class)]
#[UsesClass(\Janalis\Collaborate\Notification\NotificationRepository::class)]
#[UsesClass(\Janalis\Collaborate\Notification\Notifier::class)]
#[UsesClass(\Janalis\Collaborate\Notification\Preferences::class)]
#[UsesClass(\Janalis\Collaborate\Notification\Push\PushSubscriptions::class)]
#[UsesClass(\Janalis\Collaborate\Twig\CollaborateExtension::class)]
#[UsesClass(\Janalis\Collaborate\CollaborateBundle::class)]
#[UsesClass(\Janalis\Collaborate\DependencyInjection\Configuration::class)]
#[UsesClass(\Janalis\Collaborate\Form\CollaborateFormExtension::class)]
#[UsesClass(\Janalis\Collaborate\Notification\Push\PushEndpoints::class)]
final class NotificationsOffTest extends ApplicationTestCase
{
    protected static function kernel(): string
    {
        return PlainKernel::class;
    }

    protected function editUrl(int $id): string
    {
        return '/admin/articles/' . $id . '/edit';
    }

    protected function newUrl(): string
    {
        return '/admin/articles/new';
    }

    protected function saveButton(bool $new): string
    {
        return 'Save';
    }

    public function testEveryRouteOfTheNotificationsIsNone(): void
    {
        $this->signIn('alice');
        foreach ([
            '/admin/notifications',
            '/admin/notifications/all',
            '/admin/notifications/topics',
            '/admin/notifications/push/worker.js',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
        }
        foreach ([
            '/admin/notifications/read',
            '/admin/notifications/push/subscribe',
            '/admin/notifications/topics/a/subscribe',
        ] as $url) {
            $this->client->request('POST', $url);
            self::assertResponseStatusCodeSame(404);
        }
        $access = static::getContainer()->get(NotificationAccess::class);
        self::assertInstanceOf(NotificationAccess::class, $access);
        self::assertFalse($access->enabled);
        self::assertNull($access->recipient());
    }
}
