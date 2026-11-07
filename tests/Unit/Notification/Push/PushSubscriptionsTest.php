<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification\Push;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Janalis\Collaborate\Notification\Push\PushSubscriptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(PushSubscriptions::class)]
final class PushSubscriptionsTest extends TestCase
{
    public function testTheBrowsersOfAReaderComeInTheOrderTheySubscribed(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects($this->once())
            ->method('findBy')
            ->with(['recipient' => 3], ['id' => 'ASC'])
            ->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        self::assertSame([], new PushSubscriptions($em, $this->createStub(ClockInterface::class))->of(3));
    }
}
