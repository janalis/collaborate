<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub;

use Janalis\Collaborate\Hub\HubPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(HubPublisher::class)]
final class HubPublisherTest extends TestCase
{
    public function testPublishesDeferredUpdatesOnTerminateOnceAndLogsAFailure(): void
    {
        $first = new Update('urn:a', '{}', true);
        $second = new Update('urn:b', '{}', true);
        $published = [];
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects($this->exactly(2))
            ->method('publish')
            ->willReturnCallback(static function (Update $update) use (&$published, $second): string {
                $published[] = $update;
                if ($update === $second) {
                    throw new \RuntimeException('offline');
                }

                return 'id';
            });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('second failed');
        $publisher = new HubPublisher($hub, $logger);
        self::assertSame([KernelEvents::TERMINATE => 'publish'], HubPublisher::getSubscribedEvents());

        $publisher->defer($first, 'first failed');
        $publisher->defer($second, 'second failed');
        self::assertSame([], $published);
        $publisher->publish();
        $publisher->publish();

        self::assertSame([$first, $second], $published);
    }

    public function testAFailurePausesPublishingAcrossRequestsUntilThePauseEnds(): void
    {
        $published = [];
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects($this->exactly(2))
            ->method('publish')
            ->willReturnCallback(static function (Update $update) use (&$published): string {
                $published[] = $update->getTopics()[0];
                if ('urn:a' === $update->getTopics()[0]) {
                    throw new \RuntimeException('offline');
                }

                return 'id';
            });
        $clock = new MockClock();
        $publisher = new HubPublisher($hub, $this->createStub(LoggerInterface::class), $clock, 3);
        self::assertTrue($publisher->available());

        $publisher->defer(new Update('urn:a', '{}', true), 'failed');
        $publisher->defer(new Update('urn:skipped', '{}', true), 'failed');
        $publisher->publish();
        self::assertFalse($publisher->available());
        $publisher->reset();
        self::assertFalse($publisher->available());
        $publisher->defer(new Update('urn:dropped', '{}', true), 'failed');
        $publisher->publish();

        $clock->sleep(2.5);
        self::assertFalse($publisher->available());
        $clock->sleep(0.5);
        self::assertTrue($publisher->available());
        $publisher->defer(new Update('urn:b', '{}', true), 'failed');
        $publisher->publish();

        self::assertSame(['urn:a', 'urn:b'], $published);
    }

    public function testResetDropsUpdatesOfAnUnfinishedRequest(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->never())->method('publish');
        $publisher = new HubPublisher($hub, $this->createStub(LoggerInterface::class));
        $publisher->defer(new Update('urn:a', '{}', true), 'failed');
        $publisher->reset();
        $publisher->publish();
    }
}
