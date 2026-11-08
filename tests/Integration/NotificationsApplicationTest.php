<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Integration;

use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Controller\CursorController;
use Janalis\Collaborate\Controller\HubController;
use Janalis\Collaborate\Controller\NotificationController;
use Janalis\Collaborate\Controller\PushController;
use Janalis\Collaborate\Cursor\CursorEvents;
use Janalis\Collaborate\Cursor\CursorIdentity;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\DependencyInjection\Configuration;
use Janalis\Collaborate\Doctrine\ChangeCollector;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Draft\EventValidator;
use Janalis\Collaborate\EasyAdmin\UpdatesSubscriber;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Admission;
use Janalis\Collaborate\Hub\HubGuard;
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
use Janalis\Collaborate\Notification\Entity\NotificationPreference;
use Janalis\Collaborate\Notification\Entity\PushSubscription;
use Janalis\Collaborate\Notification\MandatoryTopicException;
use Janalis\Collaborate\Notification\NotificationAccess;
use Janalis\Collaborate\Notification\NotificationCatalog;
use Janalis\Collaborate\Notification\NotificationRepository;
use Janalis\Collaborate\Notification\Notifier;
use Janalis\Collaborate\Notification\Preferences;
use Janalis\Collaborate\Notification\Push\OnlineRecipients;
use Janalis\Collaborate\Notification\Push\PushDispatcher;
use Janalis\Collaborate\Notification\Push\PushPayload;
use Janalis\Collaborate\Notification\Push\PushResult;
use Janalis\Collaborate\Notification\Push\PushSubscriptions;
use Janalis\Collaborate\Tests\Application\Host\HostStreamGuard;
use Janalis\Collaborate\Tests\Application\Host\Push\HubSubscriptions;
use Janalis\Collaborate\Tests\Application\Shared\Hub\RecordingTransport;
use Janalis\Collaborate\Twig\CollaborateExtension;
use Janalis\Collaborate\Twig\NotificationRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Notifications in the library: the bell of every page, the page of them all, the topics a user keeps, the
 * stream a page listens to them on (a bare grant where the page is in no place), and web push.
 */
#[CoversClass(CollaborateBundle::class)]
#[CoversClass(Configuration::class)]
#[CoversClass(NotificationController::class)]
#[CoversClass(PushController::class)]
#[CoversClass(HubController::class)]
#[CoversClass(HubGuard::class)]
#[CoversClass(PageGrant::class)]
#[CoversClass(CollaborateExtension::class)]
#[CoversClass(NotificationRuntime::class)]
#[CoversClass(NotificationAccess::class)]
#[CoversClass(\Janalis\Collaborate\Notification\MandatoryTopicException::class)]
#[CoversClass(\Janalis\Collaborate\Controller\CursorController::class)]
#[CoversClass(NotificationCatalog::class)]
#[CoversClass(NotificationRepository::class)]
#[CoversClass(Notifier::class)]
#[CoversClass(Preferences::class)]
#[CoversClass(PushDispatcher::class)]
#[CoversClass(PushPayload::class)]
#[CoversClass(PushSubscriptions::class)]
#[CoversClass(OnlineRecipients::class)]
#[CoversClass(Notification::class)]
#[CoversClass(NotificationPreference::class)]
#[CoversClass(PushSubscription::class)]
#[CoversClass(Topics::class)]
#[CoversClass(HubPublisher::class)]
#[UsesClass(Admission::class)]
#[UsesClass(ChangeCollector::class)]
#[UsesClass(RecordIdentifier::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(DraftJournal::class)]
#[UsesClass(DraftSave::class)]
#[UsesClass(EventValidator::class)]
#[UsesClass(UpdatesSubscriber::class)]
#[UsesClass(Grant::class)]
#[UsesClass(RenewSubscriber::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
#[UsesClass(\Janalis\Collaborate\Form\CollaborateFormExtension::class)]
#[CoversClass(\Janalis\Collaborate\Notification\Push\PushEndpoints::class)]
#[UsesClass(Notice::class)]
#[UsesClass(HubSessions::class)]
#[UsesClass(CursorEvents::class)]
#[UsesClass(CursorIdentity::class)]
#[UsesClass(HubSession::class)]
#[UsesClass(MercureTransport::class)]
#[UsesClass(Subscription::class)]
#[UsesClass(PublishFailedException::class)]
final class NotificationsApplicationTest extends NotificationsTestCase
{
    public function testTheBellListsTheLatestNotificationsAndCountsTheUnreadOnes(): void
    {
        $this->signIn('alice');
        $this->share(self::ALICE, 'First');
        $this->share(self::ALICE, 'Second');
        $this->share(self::BOB, 'Not hers');
        $page = $this->open('/admin/library/music');
        $toggle = $page->filter('.collaborate-notifications__toggle');
        self::assertSame('Notifications, 2 unread', $toggle->attr('aria-label'));
        self::assertSame('2', trim($page->filter('.collaborate-notifications__count')->text()));
        $items = $page->filter('.collaborate-notification');
        self::assertCount(2, $items);
        // The latest first, its sentence filled in, leading where it was told to.
        self::assertSame(
            'Bob shared “Second” with you',
            $items->first()->filter('.collaborate-notification__text')->text(),
        );
        self::assertSame('/admin/library/music', $items->first()->attr('href'));
        self::assertSame('true', $items->first()->attr('data-unread'));
        self::assertSame(
            'icon-share',
            explode(' ', (string) $items->first()->filter('.collaborate-notification__icon')->attr('class'))[1],
        );
        $bell = self::bell($page);
        self::assertSame('/admin/notifications', $bell['src']);
        self::assertSame('/admin/notifications/read', $bell['read']);
        self::assertSame(99, $bell['maxCount']);
        self::assertIsArray($bell['push']);
        self::assertSame('/admin/notifications/push/worker.js', $bell['push']['worker']);
    }

    public function testTheFragmentOfTheBellIsAnsweredUncached(): void
    {
        $this->signIn('reader');
        $this->share(self::READER);
        $page = $this->open('/admin/notifications');
        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
        self::assertSame('1', $page->filter('.collaborate-notifications__header')->attr('data-unread-count'));
        self::assertSame('1', $page->filter('.collaborate-notifications__header')->attr('data-total-count'));
        self::assertCount(1, $page->filter('.collaborate-notifications__read-all'));
    }

    public function testAPageInNoPlaceAndAUserWhoMayNotCollaborateGetABareGrant(): void
    {
        $this->signIn('reader');
        $layer = self::layer($this->open('/admin/library/music'));
        self::assertTrue(PageGrant::isBare($layer['grant']));
        // No cursors nor pointers for a bare grant: the stream carries the notifications alone.
        self::assertNull($layer['events']);
        $session = $this->post($layer['session'], $layer['csrf'], [
            'grant' => $layer['grant'],
            'notifications' => true,
        ]);
        self::assertSame(['urn:collaborate:notifications:17'], $session['topics']);
        $this->post($layer['session'], $layer['csrf'], ['grant' => $layer['grant']], 403);
        $this->post('/admin/cursors/events', $layer['csrf'], ['grant' => $layer['grant'], 'kind' => 'hello'], 403);

        $this->signIn('alice');
        $page = $this->open('/admin/notifications/all');
        $layer = self::layer($page);
        self::assertTrue(PageGrant::isBare($layer['grant']));
        $session = $this->post($layer['session'], $layer['csrf'], [
            'grant' => $layer['grant'],
            'notifications' => true,
        ]);
        self::assertSame(['urn:collaborate:notifications:15'], $session['topics']);
    }

    public function testAHostWithoutAStreamGivesNoBareGrantAndKeepsItsRooms(): void
    {
        HostStreamGuard::$streams = false;
        try {
            $this->signIn('reader');
            self::assertCount(0, $this->open('/admin/library/music')->filter('[data-collaborate-layer]'));
            $this->signIn('alice');
            self::assertCount(0, $this->open('/admin/notifications/all')->filter('[data-collaborate-layer]'));
            // A page in a place keeps its room: what decides it is the place provider's.
            self::assertFalse(PageGrant::isBare(self::layer($this->open('/admin/library/music'))['grant']));
        } finally {
            HostStreamGuard::$streams = true;
        }
    }

    public function testACollaboratorsPageStreamCarriesItsRoomAndTheirNotifications(): void
    {
        $this->signIn('alice');
        $layer = self::layer($this->open('/admin/library/music'));
        self::assertFalse(PageGrant::isBare($layer['grant']));
        self::assertNotNull($layer['events']);
        $session = $this->post($layer['session'], $layer['csrf'], [
            'grant' => $layer['grant'],
            'notifications' => true,
        ]);
        self::assertIsArray($session['topics']);
        self::assertCount(2, $session['topics']);
        self::assertStringStartsWith('urn:collaborate:cursors:', (string) $session['topics'][0]);
        self::assertSame('urn:collaborate:notifications:15', $session['topics'][1]);
        $session = $this->post($layer['session'], $layer['csrf'], ['grant' => $layer['grant']]);
        self::assertIsArray($session['topics']);
        self::assertCount(1, $session['topics']);
    }

    public function testANotificationTellsThePagesOfItsReaderPrivately(): void
    {
        $this->signIn('alice');
        $this->open('/admin/library/music');
        $this->share(self::ALICE);
        $this->terminate();
        self::assertSame(
            [['channel' => 'notifications']],
            self::on($this->published(), 'urn:collaborate:notifications:15'),
        );
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(self::ALICE, $rows[0]->recipient);
        self::assertSame('shares', $rows[0]->topic);
        self::assertSame('record_shared', $rows[0]->type);
        self::assertSame(['%name%' => 'Bob', '%record%' => 'Song'], $rows[0]->parameters);
        self::assertNull($rows[0]->messageKey);
        self::assertTrue($rows[0]->isUnread());
    }

    public function testAUserIsToldThroughTheirIdAndNobodyWithoutOne(): void
    {
        $users = new \Janalis\Collaborate\Tests\Fixtures\TestUserProvider();
        $this->notifier()->notifyAll(
            [$users->loadUserByIdentifier('bob'), $users->loadUserByIdentifier('pending'), self::READER],
            'announcement',
            ['%text%' => 'Maintenance tonight'],
        );
        $rows = $this->rows();
        self::assertSame(
            [self::BOB, self::READER],
            array_map(static fn(Notification $row): int => $row->recipient, $rows),
        );
        self::assertNull($rows[0]->url);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The notification type "nope" is not configured (collaborate.notifications.types).',
        );
        $this->notifier()->notify(self::BOB, 'nope');
    }

    public function testThePageListsEveryNotificationAPageAtATimeByTheirFilters(): void
    {
        $this->signIn('alice');
        for ($index = 1; $index <= 31; ++$index) {
            $this->share(self::ALICE, 'Song ' . $index);
        }
        $this->notifier()->notify(self::ALICE, 'announcement', ['%text%' => 'Hello']);
        $page = $this->open('/admin/notifications/all');
        $rows = $page->filter('.collaborate-notification-row');
        self::assertCount(30, $rows);
        self::assertSame('Hello', $rows->first()->filter('.collaborate-notification__text')->text());
        // An announcement leads nowhere: its text is no link.
        self::assertSame('span', $rows->first()->filter('.collaborate-notification__text')->nodeName());
        $next = (string) $page->filter('.collaborate-notification-more')->attr('data-next');
        self::assertMatchesRegularExpression('#^/admin/notifications/all\?before=\d+$#', $next);
        self::assertSame(
            ['Shared records'],
            $page
                ->filter('.collaborate-notifications__filter')
                ->slice(2)
                ->each(static fn($link): string => $link->text()),
        );

        $this->client->xmlHttpRequest('GET', $next);
        self::assertResponseIsSuccessful();
        $older = $this->client->getCrawler();
        self::assertCount(2, $older->filter('.collaborate-notification-row'));
        self::assertCount(0, $older->filter('.collaborate-notification-more'));
        self::assertCount(0, $older->filter('h1'));

        $shared = $this->open('/admin/notifications/all?type=record_shared&unread=1');
        self::assertCount(30, $shared->filter('.collaborate-notification-row'));
        // The announcement, the latest, is of another type.
        self::assertSame(
            'Bob shared “Song 31” with you',
            $shared
                ->filter('.collaborate-notification-row')
                ->first()
                ->filter('.collaborate-notification__text')
                ->text(),
        );
        self::assertStringContainsString(
            'type=record_shared',
            (string) $shared->filter('.collaborate-notification-more')->attr('data-next'),
        );
        self::assertStringContainsString(
            'unread=1',
            (string) $shared->filter('.collaborate-notification-more')->attr('data-next'),
        );
        self::assertCount(
            1,
            $this->open('/admin/notifications/all?topic=announcements')->filter('.collaborate-notification-row'),
        );
        $this->markRead();
        self::assertCount(0, $this->open('/admin/notifications/all?unread=1')->filter('.collaborate-notification-row'));
        // Nothing left to list, the page says so.
        self::assertNull(
            $this
                ->open('/admin/notifications/all?unread=1')
                ->filter('.collaborate-notifications__empty')
                ->attr('hidden'),
        );
    }

    public function testAPageHoldingThemAllLeadsToNoOlderOnes(): void
    {
        $this->signIn('alice');
        for ($index = 1; $index <= 30; ++$index) {
            $this->share(self::ALICE, 'Song ' . $index);
        }
        $page = $this->open('/admin/notifications/all');
        self::assertCount(30, $page->filter('.collaborate-notification-row'));
        self::assertCount(0, $page->filter('.collaborate-notification-more'));
        self::assertCount(
            0,
            $this->open('/admin/notifications/all?unread=1')->filter('.collaborate-notification-more'),
        );
    }

    public function testAUserTheApplicationGaveNoIdIsRefusedTheirNotifications(): void
    {
        $this->signIn('pending');
        $this->client->request('GET', '/admin/notifications');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/notifications/push/subscribe', server: ['HTTP_X_CSRF_TOKEN' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testThePageRefusesAFilterItDoesNotKnow(): void
    {
        $this->signIn('alice');
        foreach (['type=nope', 'topic=nope', 'before=x', 'before=-1'] as $query) {
            $this->client->request('GET', '/admin/notifications/all?' . $query);
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testAUserReadsUnreadsAndClearsTheirOwnNotificationsAlone(): void
    {
        $this->signIn('alice');
        $this->share(self::ALICE, 'One');
        $this->share(self::ALICE, 'Two');
        $this->share(self::BOB, 'His');
        [$one, $two] = $this->rows(self::ALICE);
        [$his] = $this->rows(self::BOB);

        self::assertSame(['unread' => 1, 'total' => 2], $this->change('read', (string) $one->id));
        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
        self::assertSame(
            ['channel' => 'notifications'],
            self::on($this->published(), 'urn:collaborate:notifications:15')[0] ?? null,
        );
        // Another user's notification is none of hers: nothing changes, and her pages are told so all the same.
        $transport = static::getContainer()->get(RecordingTransport::class);
        self::assertInstanceOf(RecordingTransport::class, $transport);
        $transport->clear();
        self::assertSame(['unread' => 1, 'total' => 2], $this->change('read', (string) $his->id));
        self::assertCount(1, self::on($this->published(), 'urn:collaborate:notifications:15'));
        self::assertTrue($this->rows(self::BOB)[0]->isUnread());
        self::assertSame(['unread' => 0, 'total' => 2], $this->change('read'));
        $readAt = $this->rows(self::ALICE)[0]->readAt;
        self::assertNotNull($readAt);
        // Read again, a notification keeps the date it was first read.
        $this->change('read', (string) $one->id);
        self::assertEquals($readAt, $this->rows(self::ALICE)[0]->readAt);
        self::assertSame(['unread' => 1, 'total' => 2], $this->change('unread', (string) $two->id));
        self::assertSame(['unread' => 2, 'total' => 2], $this->change('unread', ''));
        self::assertSame(['unread' => 1, 'total' => 1], $this->change('clear', (string) $one->id));
        self::assertSame(['unread' => 0, 'total' => 0], $this->change('clear'));
        self::assertCount(1, $this->rows());
        $this->client->request('POST', '/admin/notifications/read', ['id' => ['1']]);
        self::assertResponseStatusCodeSame(400);
        $this->client->request('POST', '/admin/notifications/read', ['id' => 'x']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAUserChoosesTheTopicsTheyAreToldOf(): void
    {
        $this->signIn('reader');
        $page = $this->open('/admin/notifications/topics');
        $switches = $page->filter('input[role="switch"]');
        self::assertSame(
            ['shares', 'announcements'],
            $switches->each(static fn($input): string => (string) $input->attr('name')),
        );
        self::assertNotNull($switches->first()->attr('checked'));
        self::assertNull($switches->first()->attr('disabled'));
        self::assertNotNull($switches->last()->attr('disabled'));
        self::assertSame(
            'A record is shared with you.',
            $page->filter('#collaborate-topic-shares-description')->text(),
        );
        $token = (string) self::bell($page)['topicsToken'];
        $unsubscribe = (string) $switches->first()->attr('data-unsubscribe');
        self::assertSame('/admin/notifications/topics/shares/unsubscribe', $unsubscribe);

        $this->post($unsubscribe, 'forged', [], 403);
        self::assertSame(['topic' => 'shares', 'subscribed' => false], $this->post($unsubscribe, $token, []));
        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
        self::assertSame(
            ['channel' => 'notifications'],
            self::on($this->published(), 'urn:collaborate:notifications:17')[0] ?? null,
        );
        self::assertNull($this->open('/admin/notifications/topics')->filter('input[name="shares"]')->attr('checked'));
        $this->client->xmlHttpRequest('GET', '/admin/notifications/topics');
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        // Unsubscribed, a user is told nothing of the topic; a mandatory one still reaches them.
        $this->share(self::READER);
        $this->notifier()->notify(self::READER, 'announcement', ['%text%' => 'Everyone']);
        self::assertSame(
            ['announcement'],
            array_map(static fn(Notification $row): string => $row->type, $this->rows()),
        );

        self::assertSame(
            ['topic' => 'shares', 'subscribed' => true],
            $this->post('/admin/notifications/topics/shares/subscribe', $token, []),
        );
        $this->share(self::READER);
        self::assertCount(2, $this->rows());
        $this->post('/admin/notifications/topics/announcements/unsubscribe', $token, [], 403);
        $this->post('/admin/notifications/topics/nope/unsubscribe', $token, [], 404);
        // A form posts its token as a field.
        $this->client->request('POST', '/admin/notifications/topics/shares/unsubscribe', ['_token' => $token]);
        self::assertResponseIsSuccessful();
    }

    public function testAVisitorIsSentToSignInAndARouteOfTheBundleKeepsItsMethods(): void
    {
        $this->client->request('GET', '/admin/notifications');
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/admin/notifications/read');
        self::assertResponseStatusCodeSame(405);
    }

    public function testAVisitorIsNobodysRecipientAndSeesNoBell(): void
    {
        $this->open('/login');
        $access = static::getContainer()->get(NotificationAccess::class);
        self::assertInstanceOf(NotificationAccess::class, $access);
        self::assertNull($access->recipient());
        $runtime = static::getContainer()->get(NotificationRuntime::class);
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(NotificationRuntime::class, $runtime);
        self::assertInstanceOf(\Twig\Environment::class, $twig);
        self::assertSame('', $runtime->bell($twig));
        // A type the configuration no longer declares says nothing.
        self::assertSame(
            '',
            $runtime->text(new Notification(self::ALICE, 'shares', 'gone', [], null, new \DateTimeImmutable())),
        );
        self::assertNull($runtime->icon(
            new Notification(self::ALICE, 'shares', 'gone', [], null, new \DateTimeImmutable()),
        ));
    }

    public function testABareGrantSendsNoPointerAndARoomIsACollaboratorsAlone(): void
    {
        $this->signIn('alice');
        $layer = self::layer($this->open('/admin/notifications/all'));
        self::assertTrue(PageGrant::isBare($layer['grant']));
        $this->post(
            '/admin/cursors/events',
            $layer['csrf'],
            ['grant' => $layer['grant'], 'kind' => 'hello', 'clientId' => '12345678-1234-1234', 'sequence' => 1],
            403,
        );

        // A room grant in the hands of a user who may not collaborate is refused.
        $this->signIn('reader');
        $csrf = self::layer($this->open('/admin/library/music'))['csrf'];
        $pages = static::getContainer()->get(PageGrant::class);
        $users = static::getContainer()->get(\Janalis\Collaborate\Identity\CollaboratorProviderInterface::class);
        self::assertInstanceOf(PageGrant::class, $pages);
        self::assertInstanceOf(\Janalis\Collaborate\Identity\CollaboratorProviderInterface::class, $users);
        $request = \Symfony\Component\HttpFoundation\Request::create('/admin/library/music');
        $request->attributes->set('_route', 'library');
        $request->attributes->set('folder', 'music');
        $reader = new \Janalis\Collaborate\Tests\Fixtures\TestUserProvider()->loadUserByIdentifier('reader');
        $grant = $pages->issue($request, self::READER, time(), $users->authorization($reader));
        self::assertNotNull($grant);
        self::assertFalse(PageGrant::isBare($grant));
        $this->post('/admin/cursors/session', $csrf, ['grant' => $grant, 'notifications' => true], 403);
    }

    public function testATopicNobodyConfiguredIsNoneAndAUserIsForgotten(): void
    {
        $preferences = static::getContainer()->get(Preferences::class);
        self::assertInstanceOf(Preferences::class, $preferences);
        self::assertFalse($preferences->isSubscribed(self::ALICE, 'nope'));
        self::assertTrue($preferences->isSubscribed(self::ALICE, 'announcements'));
        $this->expectExceptionObject(new \InvalidArgumentException('The notification topic "nope" is not configured.'));
        try {
            $preferences->choose(self::ALICE, 'nope', false);
        } finally {
            $this->share(self::ALICE);
            $this->share(self::BOB);
            $repository = static::getContainer()->get(NotificationRepository::class);
            self::assertInstanceOf(NotificationRepository::class, $repository);
            $repository->forget(self::ALICE);
            self::assertSame(
                [self::BOB],
                array_map(static fn(Notification $row): int => $row->recipient, $this->rows()),
            );
            $subscriptions = static::getContainer()->get(PushSubscriptions::class);
            self::assertInstanceOf(PushSubscriptions::class, $subscriptions);
            $subscriptions->save(self::ALICE, self::ENDPOINT, self::KEYS['p256dh'], self::KEYS['auth'], 'aes128gcm');
            $subscriptions->save(
                self::BOB,
                self::ENDPOINT . '-2',
                self::KEYS['p256dh'],
                self::KEYS['auth'],
                'aes128gcm',
            );
            self::assertSame(2, $subscriptions->removeAll());
            self::assertSame(0, $subscriptions->removeAll());
        }
    }

    public function testABrowserSubscribesToThePushesOfItsUser(): void
    {
        $this->signIn('alice');
        $bell = self::bell($this->open('/admin/library/music'));
        self::assertIsArray($bell['push']);
        $token = (string) $bell['push']['token'];
        self::assertSame(
            \Janalis\Collaborate\Tests\Application\Host\HostKernel::VAPID_PUBLIC_KEY,
            $bell['push']['key'],
        );

        $this->subscribe('forged', status: 403);
        $this->subscribe($token);
        $subscription = $this->em()->getRepository(PushSubscription::class)->findOneBy([]);
        self::assertInstanceOf(PushSubscription::class, $subscription);
        self::assertSame(self::ALICE, $subscription->recipient);
        self::assertSame('aes128gcm', $subscription->encoding);
        self::assertSame(hash('sha256', self::ENDPOINT), $subscription->endpointHash);

        // The same browser signed in as somebody else is theirs: one device, one subscription.
        $this->signIn('bob');
        $bob = $this->pushToken($this->open('/admin/library/music'));
        $this->post('/admin/notifications/push/subscribe', $bob, [
            'endpoint' => self::ENDPOINT,
            'keys' => self::KEYS,
            'encoding' => 'aesgcm',
        ]);
        $this->em()->clear();
        $subscriptions = $this->em()->getRepository(PushSubscription::class)->findAll();
        self::assertCount(1, $subscriptions);
        self::assertSame(self::BOB, $subscriptions[0]->recipient);
        self::assertSame('aesgcm', $subscriptions[0]->encoding);
        // Only its user unsubscribes it.
        $this->signIn('alice');
        $this->post('/admin/notifications/push/unsubscribe', $token, ['endpoint' => self::ENDPOINT]);
        self::assertCount(1, $this->em()->getRepository(PushSubscription::class)->findAll());
        $this->signIn('bob');
        self::assertSame(['subscribed' => false], $this->post('/admin/notifications/push/unsubscribe', $bob, [
            'endpoint' => self::ENDPOINT,
        ]));
        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(PushSubscription::class)->findAll());
    }

    public function testASubscriptionIsRefusedUnlessItIsOneAPushServiceMade(): void
    {
        $this->signIn('alice');
        $token = $this->pushToken($this->open('/admin/library/music'));
        $invalid = [
            // The server would post to what a user wrote: an internal address, another host.
            ['endpoint' => 'https://169.254.169.254/latest/meta-data/', 'keys' => self::KEYS],
            ['endpoint' => 'https://intranet.local/push', 'keys' => self::KEYS],
            ['endpoint' => 'https://fcm.googleapis.com:8443/fcm/send/x', 'keys' => self::KEYS],
            ['endpoint' => 'http://push.example.test/x', 'keys' => self::KEYS],
            ['endpoint' => 'https://' . str_repeat('a', 2050), 'keys' => self::KEYS],
            ['endpoint' => 'https://', 'keys' => self::KEYS],
            ['endpoint' => 7, 'keys' => self::KEYS],
            ['endpoint' => self::ENDPOINT],
            ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => 'short', 'auth' => self::KEYS['auth']]],
            ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => self::KEYS['p256dh'], 'auth' => 'x']],
            ['endpoint' => self::ENDPOINT, 'keys' => self::KEYS, 'encoding' => 'gzip'],
        ];
        foreach ($invalid as $body) {
            $this->post('/admin/notifications/push/subscribe', $token, $body, 400);
        }
        $this->post(
            '/admin/notifications/push/subscribe',
            $token,
            ['endpoint' => self::ENDPOINT, 'padding' => str_repeat('x', 8200)],
            400,
        );
        self::assertCount(0, $this->em()->getRepository(PushSubscription::class)->findAll());
    }

    public function testTheServiceWorkerMayControlEveryPageAndKnowsWhereToRead(): void
    {
        $this->signIn('reader');
        $this->client->request('GET', '/admin/notifications/push/worker.js');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Service-Worker-Allowed', '/');
        self::assertResponseHeaderSame('Content-Type', 'text/javascript; charset=utf-8');
        self::assertStringContainsString(
            'no-cache',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
        $source = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith(
            'self.collaborateNotifications = {"read":"/admin/notifications/read","title":"New notification","body":"You have a new notification.","icon":null};'
            . "\n",
            $source,
        );
        self::assertStringContainsString('addEventListener', $source);
    }

    public function testANotificationIsPushedToTheBrowsersOfItsReaderInTheirLanguage(): void
    {
        $this->signIn('reader');
        $token = $this->pushToken($this->open('/admin/library/music'));
        $this->subscribe($token);
        $this->subscribe($token, 'https://fcm.googleapis.com/fcm/send/reader-phone');
        $this->share(self::READER);
        self::assertSame([], $this->pushes()->sent);
        $this->terminate();
        $sent = $this->pushes()->sent;
        self::assertCount(2, $sent);
        self::assertSame(self::ENDPOINT, $sent[0]['endpoint']);
        $id = $this->rows()[0]->id;
        self::assertSame(
            [
                'id' => $id,
                'title' => 'Nouvelle notification',
                'body' => 'Bob shared “Song” with you',
                'url' => '/admin/library/music',
                'icon' => null,
                'tag' => 'collaborate-notification-' . (string) $id,
            ],
            json_decode((string) $sent[0]['payload'], true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertStringContainsString(
            'urn%3Acollaborate%3Anotifications%3A17',
            HubSubscriptions::$asked[0]['url'] ?? '',
        );
        self::assertStringStartsWith('Bearer ', HubSubscriptions::$asked[0]['authorization'] ?? '');
        $this->em()->clear();
        foreach ($this->em()->getRepository(PushSubscription::class)->findAll() as $subscription) {
            self::assertNotNull($subscription->lastUsedAt);
        }
    }

    public function testAReaderWhosePageHoldsTheirStreamIsPushedNothing(): void
    {
        $this->signIn('reader');
        $this->subscribe($this->pushToken($this->open('/admin/library/music')));
        HubSubscriptions::$online = ['urn:collaborate:notifications:17'];
        $this->share(self::READER);
        $this->terminate();
        self::assertSame([], $this->pushes()->sent);
        // A hub that does not answer reads as nobody listening.
        HubSubscriptions::$online = [];
        HubSubscriptions::$down = true;
        $this->share(self::READER);
        $this->terminate();
        self::assertCount(1, $this->pushes()->sent);
    }

    public function testAnExpiredBrowserIsForgottenAndAFailedPushKeepsIt(): void
    {
        $this->signIn('alice');
        $token = $this->pushToken($this->open('/admin/library/music'));
        $this->subscribe($token);
        $this->subscribe($token, 'https://fcm.googleapis.com/fcm/send/alice-phone');
        $this->pushes()->answers = [
            self::ENDPOINT => PushResult::Expired,
            'https://fcm.googleapis.com/fcm/send/alice-phone' => PushResult::Failed,
        ];
        $this->share(self::ALICE);
        $this->terminate();
        $this->em()->clear();
        $left = $this->em()->getRepository(PushSubscription::class)->findAll();
        self::assertCount(1, $left);
        self::assertSame('https://fcm.googleapis.com/fcm/send/alice-phone', $left[0]->endpoint);
        self::assertNull($left[0]->lastUsedAt);
        // Nobody subscribed, nothing is asked of the hub.
        $this->share(self::BOB);
        HubSubscriptions::reset();
        $this->terminate();
        self::assertSame([], HubSubscriptions::$asked);
    }

    /** @return array<string, mixed> */
    private function change(string $action, ?string $id = null): array
    {
        $this->client->request('POST', '/admin/notifications/' . $action, null === $id ? [] : ['id' => $id]);
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function markRead(): void
    {
        $this->client->request('POST', '/admin/notifications/read');
        self::assertResponseIsSuccessful();
    }
}
