<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Integration;

use Janalis\Collaborate\Bridge\Bridge;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Controller\PushController;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\DependencyInjection\Configuration;
use Janalis\Collaborate\Doctrine\ChangeCollector;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Draft\EventValidator;
use Janalis\Collaborate\Draft\FormDraft;
use Janalis\Collaborate\Form\CollaborateFormExtension;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\HubSession;
use Janalis\Collaborate\Hub\HubSessions;
use Janalis\Collaborate\Hub\Notice;
use Janalis\Collaborate\Hub\RenewSubscriber;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Hub\Transport\Mercure\MercureTransport;
use Janalis\Collaborate\Hub\Transport\PublishFailedException;
use Janalis\Collaborate\Hub\Transport\Subscription;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Notification\Entity\Notification;
use Janalis\Collaborate\Notification\Entity\PushSubscription;
use Janalis\Collaborate\Notification\Messenger\SendNotification;
use Janalis\Collaborate\Notification\Messenger\SendNotificationHandler;
use Janalis\Collaborate\Notification\Messenger\SendWebPush;
use Janalis\Collaborate\Notification\Messenger\SendWebPushHandler;
use Janalis\Collaborate\Notification\NotificationAccess;
use Janalis\Collaborate\Notification\NotificationCatalog;
use Janalis\Collaborate\Notification\NotificationRepository;
use Janalis\Collaborate\Notification\Notifier;
use Janalis\Collaborate\Notification\Preferences;
use Janalis\Collaborate\Notification\Push\OnlineRecipients;
use Janalis\Collaborate\Notification\Push\PushDispatcher;
use Janalis\Collaborate\Notification\Push\PushPayload;
use Janalis\Collaborate\Notification\Push\PushSubscriptions;
use Janalis\Collaborate\Security\SymfonyCurrentUser;
use Janalis\Collaborate\Tests\Application\Host\HostBusKernel;
use Janalis\Collaborate\Twig\CollaborateExtension;
use Janalis\Collaborate\Twig\NotificationRuntime;
use Janalis\Collaborate\Updates\UpdatesSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/**
 * Notifications through Messenger: notify() dispatches one message per recipient, a worker writes each once
 * whatever its deliveries, tells the pages once the message is handled, and sends one push message per browser.
 */
#[CoversClass(CollaborateBundle::class)]
#[CoversClass(Bridge::class)]
#[CoversClass(Notifier::class)]
#[CoversClass(SendNotification::class)]
#[CoversClass(SendNotificationHandler::class)]
#[CoversClass(SendWebPush::class)]
#[CoversClass(SendWebPushHandler::class)]
#[CoversClass(PushDispatcher::class)]
#[CoversClass(HubPublisher::class)]
#[UsesClass(PushController::class)]
#[UsesClass(PageGrant::class)]
#[UsesClass(Configuration::class)]
#[UsesClass(ChangeCollector::class)]
#[UsesClass(RecordIdentifier::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(DraftJournal::class)]
#[UsesClass(DraftSave::class)]
#[UsesClass(EventValidator::class)]
#[UsesClass(UpdatesSubscriber::class)]
#[UsesClass(CollaborateFormExtension::class)]
#[UsesClass(Grant::class)]
#[UsesClass(RenewSubscriber::class)]
#[UsesClass(Topics::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
#[UsesClass(Notification::class)]
#[UsesClass(PushSubscription::class)]
#[UsesClass(NotificationAccess::class)]
#[UsesClass(NotificationCatalog::class)]
#[CoversClass(NotificationRepository::class)]
#[UsesClass(Preferences::class)]
#[UsesClass(OnlineRecipients::class)]
#[UsesClass(PushPayload::class)]
#[CoversClass(PushSubscriptions::class)]
#[UsesClass(CollaborateExtension::class)]
#[UsesClass(NotificationRuntime::class)]
#[UsesClass(\Janalis\Collaborate\Notification\Push\PushEndpoints::class)]
#[UsesClass(Notice::class)]
#[UsesClass(HubSessions::class)]
#[UsesClass(HubSession::class)]
#[UsesClass(MercureTransport::class)]
#[UsesClass(Subscription::class)]
#[UsesClass(PublishFailedException::class)]
#[UsesClass(SymfonyCurrentUser::class)]
#[UsesClass(FormDraft::class)]
final class NotificationsMessengerTest extends NotificationsTestCase
{
    protected static function kernel(): string
    {
        return HostBusKernel::class;
    }

    public function testANotificationIsWrittenByTheWorkerOnceWhateverItsDeliveries(): void
    {
        $this->signIn('alice');
        $this->subscribe($this->pushToken($this->open('/admin/library/music')));
        $this->share(self::ALICE);
        self::assertSame([], $this->rows());
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendNotification::class, $message);
        self::assertSame(self::ALICE, $message->recipient);
        self::assertSame('record_shared', $message->type);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $message->key,
        );
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $message->at));

        $this->consume();
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame($message->key, $rows[0]->messageKey);
        self::assertEquals(new \DateTimeImmutable($message->at), $rows[0]->createdAt);
        self::assertSame(
            [['channel' => 'notifications']],
            self::on($this->published(), 'urn:collaborate:notifications:15'),
        );
        // One push message per browser, which the worker sends.
        $pushes = array_values(array_filter(
            $this->transport()->getSent(),
            static fn(Envelope $envelope): bool => $envelope->getMessage() instanceof SendWebPush,
        ));
        self::assertCount(1, $pushes);
        $push = $pushes[0]->getMessage();
        self::assertInstanceOf(SendWebPush::class, $push);
        self::assertSame($rows[0]->id, $push->notification);
        $this->consume();
        self::assertCount(1, $this->pushes()->sent);

        // Delivered again, the message writes nothing more.
        $this->transport()->send(new Envelope($message));
        $this->consume();
        self::assertCount(1, $this->rows());
        // Another message writes another row.
        $this->share(self::ALICE, 'Another');
        // The push of the redelivery is handled first.
        $this->consume();
        $this->consume();
        self::assertCount(2, $this->rows());
    }

    public function testAPushOfABrowserOrANotificationThatIsGoneIsDropped(): void
    {
        $this->signIn('alice');
        $this->subscribe($this->pushToken($this->open('/admin/library/music')));
        $this->notifier()->write(
            self::ALICE,
            'record_shared',
            ['%name%' => 'Bob', '%record%' => 'X'],
            null,
            new \DateTimeImmutable(),
        );
        $notification = $this->rows()[0]->id;
        $subscription = $this->em()->getRepository(PushSubscription::class)->findOneBy([])?->id;
        self::assertNotNull($notification);
        self::assertNotNull($subscription);
        $pushes = static::getContainer()->get(PushDispatcher::class);
        self::assertInstanceOf(PushDispatcher::class, $pushes);
        $pushes->sendById($subscription + 1, $notification);
        $pushes->sendById($subscription, $notification + 1);
        self::assertSame([], $this->pushes()->sent);
        // A notification another user was told is never pushed to this browser.
        $this->notifier()->write(
            self::BOB,
            'record_shared',
            ['%name%' => 'Al', '%record%' => 'Y'],
            null,
            new \DateTimeImmutable(),
        );
        $pushes->sendById($subscription, (int) $this->rows(self::BOB)[0]->id);
        self::assertSame([], $this->pushes()->sent);
        // Online, the reader was told by the hub.
        \Janalis\Collaborate\Tests\Application\Host\Push\HubSubscriptions::$online = [
            'urn:collaborate:notifications:15',
        ];
        $pushes->sendById($subscription, $notification);
        self::assertSame([], $this->pushes()->sent);
        \Janalis\Collaborate\Tests\Application\Host\Push\HubSubscriptions::$online = [];
        $pushes->sendById($subscription, $notification);
        self::assertCount(1, $this->pushes()->sent);
    }

    private function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.' . HostBusKernel::TRANSPORT);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * Runs a worker until the transport holds no message it did not handle. Not messenger:consume: the console
     * boots the kernel again, which resets the in-memory transport.
     */
    private function consume(): void
    {
        $bus = static::getContainer()->get('messenger.default_bus');
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $limit = iterator_count(
            (static fn(iterable $envelopes): \Generator => yield from $envelopes)($this->transport()->get()),
        );
        $listener = new StopWorkerOnMessageLimitListener($limit);
        $dispatcher->addSubscriber($listener);
        try {
            new Worker([HostBusKernel::TRANSPORT => $this->transport()], $bus, $dispatcher)->run(['sleep' => 0]);
        } finally {
            $dispatcher->removeSubscriber($listener);
        }
    }
}
