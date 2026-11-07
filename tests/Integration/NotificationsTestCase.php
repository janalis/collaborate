<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Integration;

use Janalis\Collaborate\Notification\Entity\Notification;
use Janalis\Collaborate\Notification\Entity\NotificationPreference;
use Janalis\Collaborate\Notification\Entity\PushSubscription;
use Janalis\Collaborate\Notification\Notifier;
use Janalis\Collaborate\Tests\Application\Host\HostKernel;
use Janalis\Collaborate\Tests\Application\Host\Push\HubSubscriptions;
use Janalis\Collaborate\Tests\Application\Host\Push\RecordingPushSender;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** The library with notifications on: its users, their rows and the browsers they asked to be pushed to. */
abstract class NotificationsTestCase extends ApplicationTestCase
{
    protected const int ALICE = 15;
    protected const int BOB = 16;
    protected const int READER = 17;

    /** A push endpoint, as a push service hands one to a browser. */
    protected const string ENDPOINT = 'https://fcm.googleapis.com/fcm/send/alice-laptop';

    protected const array KEYS = [
        'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
        'auth' => 'tBHItJI5svbpez7KI4CCXg',
    ];

    protected static function kernel(): string
    {
        return HostKernel::class;
    }

    protected function editUrl(int $id): string
    {
        throw new \LogicException('The library edits no record.');
    }

    protected function newUrl(): string
    {
        throw new \LogicException('The library edits no record.');
    }

    protected function saveButton(bool $new): string
    {
        throw new \LogicException('The library edits no record.');
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        HubSubscriptions::reset();
        $em = $this->em();
        foreach ([Notification::class, NotificationPreference::class, PushSubscription::class] as $class) {
            $em->createQueryBuilder()->delete($class, 'r')->getQuery()->execute();
        }
    }

    protected function notifier(): Notifier
    {
        $notifier = static::getContainer()->get(Notifier::class);
        self::assertInstanceOf(Notifier::class, $notifier);

        return $notifier;
    }

    protected function pushes(): RecordingPushSender
    {
        $sender = static::getContainer()->get(RecordingPushSender::class);
        self::assertInstanceOf(RecordingPushSender::class, $sender);

        return $sender;
    }

    /** @return list<Notification> */
    protected function rows(?int $recipient = null): array
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Notification::class)->findBy(null === $recipient ? [] : ['recipient' => $recipient], [
            'id' => 'ASC',
        ]);
    }

    /** Shares a record with a user, as the library would tell them. */
    protected function share(int $to, string $record = 'Song'): void
    {
        $this->notifier()->notify(
            $to,
            'record_shared',
            ['%name%' => 'Bob', '%record%' => $record],
            '/admin/library/music',
        );
    }

    /** Ends the request the client made: what it deferred is published and pushed. */
    protected function terminate(): void
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new TerminateEvent($kernel, new Request(), new Response()), KernelEvents::TERMINATE);
    }

    /** @return array<string, mixed> the settings the bell of a page carries */
    protected static function bell(Crawler $page): array
    {
        $bell = $page->filter('[data-collaborate-notifications]');
        self::assertCount(1, $bell);

        /** @var array<string, mixed> */
        return json_decode((string) $bell->attr('data-collaborate-notifications'), true, flags: \JSON_THROW_ON_ERROR);
    }

    /** The token a page gives its scripts to subscribe a browser with. */
    protected function pushToken(Crawler $page): string
    {
        $bell = self::bell($page);
        self::assertIsArray($bell['push'] ?? null);
        self::assertIsString($bell['push']['token'] ?? null);

        return $bell['push']['token'];
    }

    protected function subscribe(
        #[\SensitiveParameter]
        string $token,
        string $endpoint = self::ENDPOINT,
        int $status = 200,
    ): void {
        $this->post(
            '/admin/notifications/push/subscribe',
            $token,
            ['endpoint' => $endpoint, 'keys' => self::KEYS],
            $status,
        );
    }
}
