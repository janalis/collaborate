<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification\Push;

use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Hub\Transport\PresenceProbeInterface;
use Janalis\Collaborate\Notification\Push\OnlineRecipients;
use Janalis\Collaborate\Notification\Push\PushDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

#[CoversClass(OnlineRecipients::class)]
#[CoversClass(PushDispatcher::class)]
#[UsesClass(Topics::class)]
final class OnlineRecipientsTest extends TestCase
{
    public function testAsksTheHubWhetherAPageListensToTheNotificationsOfTheRecipient(): void
    {
        $asked = [];
        $presence = $this->createStub(PresenceProbeInterface::class);
        $presence
            ->method('listening')
            ->willReturnCallback(static function (string $topic) use (&$asked): bool {
                $asked[] = $topic;

                return 'urn:t:notifications:5' === $topic;
            });
        $online = new OnlineRecipients($presence, new Topics('urn:t:'), true);

        self::assertTrue($online->isOnline(5));
        self::assertFalse($online->isOnline(6));
        self::assertSame(['urn:t:notifications:5', 'urn:t:notifications:6'], $asked);
    }

    public function testNobodyIsOnlineUnlessAsked(): void
    {
        // Off unless asked: the service worker decides, and the hub is not asked.
        $presence = $this->createMock(PresenceProbeInterface::class);
        $presence->expects($this->never())->method('listening');

        self::assertFalse(new OnlineRecipients($presence, new Topics())->isOnline(1));
        self::assertFalse(new OnlineRecipients($presence, new Topics(), false)->isOnline(1));
    }

    public function testPushesAfterTheNoticesOfTheHubWhereverAnOperationEnds(): void
    {
        self::assertSame(
            [
                KernelEvents::TERMINATE => ['flush', -16],
                ConsoleEvents::TERMINATE => ['flush', -16],
                WorkerMessageHandledEvent::class => ['flush', -16],
                WorkerMessageFailedEvent::class => ['flush', -16],
            ],
            PushDispatcher::getSubscribedEvents(),
        );
    }
}
