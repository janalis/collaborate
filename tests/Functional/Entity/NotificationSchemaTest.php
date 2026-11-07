<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Functional\Entity;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Janalis\Collaborate\Notification\Entity\Notification;
use Janalis\Collaborate\Notification\Entity\NotificationPreference;
use Janalis\Collaborate\Notification\Entity\PushSubscription;
use Janalis\Collaborate\Tests\Functional\Draft\DraftJournalTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The mapping of the notifications describes the tables of docs/schema.sql, which applications copy into a migration. */
#[CoversClass(Notification::class)]
#[CoversClass(NotificationPreference::class)]
#[CoversClass(PushSubscription::class)]
final class NotificationSchemaTest extends TestCase
{
    public function testTheMappingMatchesTheDocumentedSchema(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfig([
            \dirname(__DIR__, 3) . '/src/Notification/Entity',
        ], true);
        $configuration->enableNativeLazyObjects(true);
        $em = new EntityManager(DraftJournalTest::connection(), $configuration);
        $classes = array_map(
            $em->getClassMetadata(...),
            [Notification::class, NotificationPreference::class, PushSubscription::class],
        );
        $documented = array_values(array_filter(
            DraftJournalTest::schema(),
            static fn(string $sql): bool => !str_contains($sql, 'CREATE TABLE collaborate_draft'),
        ));
        sort($documented);
        $created = new SchemaTool($em)->getCreateSchemaSql($classes);
        sort($created);
        self::assertSame($created, array_map(
            static fn(string $sql): string => (string) strstr($sql, ') DEFAULT CHARACTER SET', true) . ')',
            $documented,
        ));
        self::assertSame(
            ['collaborate_notification', 'collaborate_notification_preference', 'collaborate_push_subscription'],
            array_map(static fn($metadata): string => $metadata->getTableName(), $classes),
        );
    }

    public function testANotificationIsUnreadUntilItIsReadAndAnEndpointIsKeyedByItsHash(): void
    {
        $notification = new Notification(1, 'shares', 'shared', [], null, new \DateTimeImmutable());
        self::assertTrue($notification->isUnread());
        self::assertNull($notification->messageKey);
        $notification->readAt = new \DateTimeImmutable();
        self::assertFalse($notification->isUnread());
        $subscription = new PushSubscription(
            1,
            'https://fcm.googleapis.com/fcm/send/x',
            'k',
            'a',
            'aes128gcm',
            new \DateTimeImmutable(),
        );
        self::assertSame(hash('sha256', 'https://fcm.googleapis.com/fcm/send/x'), $subscription->endpointHash);
        self::assertSame(PushSubscription::hash('https://fcm.googleapis.com/fcm/send/x'), $subscription->endpointHash);
        $preference = new NotificationPreference(1, 'shares', false, new \DateTimeImmutable('2026-01-01'));
        self::assertSame([1, 'shares', false], [$preference->recipient, $preference->topic, $preference->subscribed]);
    }
}
