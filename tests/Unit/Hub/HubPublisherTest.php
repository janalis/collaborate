<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub;

use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\Notice;
use Janalis\Collaborate\Hub\Transport\HubTransportInterface;
use Janalis\Collaborate\Hub\Transport\PublishFailedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

#[CoversClass(HubPublisher::class)]
#[UsesClass(Notice::class)]
#[UsesClass(PublishFailedException::class)]
final class HubPublisherTest extends TestCase
{
    public function testPublishesDeferredNoticesTogetherOnTerminateOnceAndLogsTheOneThatFailed(): void
    {
        $first = new Notice('urn:a', '{}');
        $second = new Notice('urn:b', '{}');
        $offline = new \RuntimeException('offline');
        $published = [];
        $transport = $this->createMock(HubTransportInterface::class);
        $transport
            ->expects($this->once())
            ->method('publish')
            ->willReturnCallback(static function (array $notices) use (&$published, $offline): void {
                $published[] = $notices;

                throw new PublishFailedException(1, $offline);
            });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('second failed', ['exception' => $offline]);
        $publisher = new HubPublisher($transport, $logger);
        self::assertSame(
            [
                KernelEvents::TERMINATE => 'publish',
                ConsoleEvents::TERMINATE => 'publish',
                WorkerMessageHandledEvent::class => 'publish',
                WorkerMessageFailedEvent::class => 'publish',
            ],
            HubPublisher::getSubscribedEvents(),
        );

        $publisher->defer($first, 'first failed');
        $publisher->defer($second, 'second failed');
        self::assertSame([], $published);
        $publisher->publish();
        $publisher->publish();

        self::assertSame([[$first, $second]], $published);
    }

    public function testAFailurePausesPublishingAcrossRequestsUntilThePauseEnds(): void
    {
        $published = [];
        $transport = $this->createMock(HubTransportInterface::class);
        $transport
            ->expects($this->exactly(2))
            ->method('publish')
            ->willReturnCallback(static function (array $notices) use (&$published): void {
                $topics = array_map(static fn(Notice $notice): string => $notice->topics[0], $notices);
                $published = [...$published, ...$topics];
                if ('urn:a' === $topics[0]) {
                    throw new PublishFailedException(0, new \RuntimeException('offline'));
                }
            });
        $clock = new MockClock();
        $publisher = new HubPublisher($transport, $this->createStub(LoggerInterface::class), $clock, 3);
        self::assertTrue($publisher->available());

        $publisher->defer(new Notice('urn:a', '{}'), 'failed');
        $publisher->defer(new Notice('urn:skipped', '{}'), 'failed');
        $publisher->publish();
        self::assertFalse($publisher->available());
        $publisher->reset();
        self::assertFalse($publisher->available());
        $publisher->defer(new Notice('urn:dropped', '{}'), 'failed');
        $publisher->publish();

        $clock->sleep(2.5);
        self::assertFalse($publisher->available());
        $clock->sleep(0.5);
        self::assertTrue($publisher->available());
        $publisher->defer(new Notice('urn:b', '{}'), 'failed');
        $publisher->publish();
        self::assertTrue($publisher->available());

        self::assertSame(['urn:a', 'urn:skipped', 'urn:b'], $published);
    }

    public function testAFailurePausesFiveSecondsByDefaultToTheMicrosecond(): void
    {
        $transport = $this->createStub(HubTransportInterface::class);
        $transport->method('publish')->willThrowException(new PublishFailedException(0, new \RuntimeException('x')));
        $clock = new MockClock('2026-01-01 00:00:00.750000');
        $publisher = new HubPublisher($transport, $this->createStub(LoggerInterface::class), $clock);
        $publisher->defer(new Notice('urn:a', '{}'), 'failed');
        $publisher->publish();

        $clock->sleep(4.999_999);
        self::assertFalse($publisher->available());
        $clock->sleep(0.000_001);
        self::assertTrue($publisher->available());
    }

    public function testNothingDeferredPublishesNothing(): void
    {
        $transport = $this->createMock(HubTransportInterface::class);
        $transport->expects($this->never())->method('publish');
        $publisher = new HubPublisher($transport, $this->createStub(LoggerInterface::class));
        $publisher->publish();
    }

    public function testResetDropsNoticesOfAnUnfinishedRequest(): void
    {
        $transport = $this->createMock(HubTransportInterface::class);
        $transport->expects($this->never())->method('publish');
        $publisher = new HubPublisher($transport, $this->createStub(LoggerInterface::class));
        $publisher->defer(new Notice('urn:a', '{}'), 'failed');
        $publisher->reset();
        $publisher->publish();
    }
}
