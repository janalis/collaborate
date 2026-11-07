<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification\Push;

use Doctrine\ORM\EntityManagerInterface;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Notification\Entity\Notification;
use Janalis\Collaborate\Notification\Entity\PushSubscription;
use Janalis\Collaborate\Notification\NotificationCatalog;
use Janalis\Collaborate\Notification\NotificationRepository;
use Janalis\Collaborate\Notification\Push\OnlineRecipients;
use Janalis\Collaborate\Notification\Push\PushDispatcher;
use Janalis\Collaborate\Notification\Push\PushPayload;
use Janalis\Collaborate\Notification\Push\PushResult;
use Janalis\Collaborate\Notification\Push\PushSenderInterface;
use Janalis\Collaborate\Notification\Push\PushSubscriptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(PushDispatcher::class)]
#[UsesClass(Notification::class)]
#[UsesClass(PushSubscription::class)]
#[UsesClass(NotificationCatalog::class)]
#[UsesClass(NotificationRepository::class)]
#[UsesClass(OnlineRecipients::class)]
#[UsesClass(PushPayload::class)]
#[UsesClass(Topics::class)]
final class PushDispatcherTest extends TestCase
{
    public function testAnEnvironmentThatTurnedWebPushOffPushesNothing(): void
    {
        $subscriptions = $this->createMock(PushSubscriptions::class);
        $subscriptions->expects($this->never())->method('of');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $sender = $this->createMock(PushSenderInterface::class);
        $sender->expects($this->never())->method('send');
        foreach ([null, $bus] as $through) {
            $pushes = new PushDispatcher(
                $subscriptions,
                new NotificationRepository($this->createStub(EntityManagerInterface::class)),
                $sender,
                new PushPayload(new NotificationCatalog()),
                new OnlineRecipients(
                    $this->createStub(HubInterface::class),
                    new Topics(),
                    $this->createStub(LoggerInterface::class),
                ),
                $through,
                false,
            );
            $pushes->push(new Notification(1, 't', 'x', [], null, new \DateTimeImmutable()));
            $pushes->flush();
        }
    }

    public function testPushesAfterTheResponseTheReadersWhoHaveABrowser(): void
    {
        $browser = new PushSubscription(2, 'https://push.test/2', 'key', 'auth', 'aes128gcm', new \DateTimeImmutable());
        $subscriptions = $this->createMock(PushSubscriptions::class);
        $subscriptions
            ->expects($this->exactly(2))
            ->method('of')
            ->willReturnCallback(static fn(int $recipient): array => 2 === $recipient ? [$browser] : []);
        $subscriptions->expects($this->once())->method('used')->with($browser);
        $sender = $this->createMock(PushSenderInterface::class);
        $sender->expects($this->once())->method('send')->with($browser)->willReturn(PushResult::Sent);
        // Turned on unless told otherwise; the reader without a browser does not stop the ones after them.
        $pushes = new PushDispatcher(
            $subscriptions,
            new NotificationRepository($this->createStub(EntityManagerInterface::class)),
            $sender,
            new PushPayload(new NotificationCatalog()),
            new OnlineRecipients(
                $this->createStub(HubInterface::class),
                new Topics(),
                $this->createStub(LoggerInterface::class),
            ),
        );
        $pushes->push(new Notification(1, 't', 'x', [], null, new \DateTimeImmutable()));
        $pushes->push(new Notification(2, 't', 'x', [], null, new \DateTimeImmutable()));
        $pushes->flush();
    }
}
