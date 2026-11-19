<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Janalis\Collaborate\Notification\Entity\NotificationPreference;
use Janalis\Collaborate\Notification\NotificationCatalog;
use Janalis\Collaborate\Notification\Preferences;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(Preferences::class)]
#[UsesClass(NotificationCatalog::class)]
#[UsesClass(NotificationPreference::class)]
final class PreferencesTest extends TestCase
{
    public function testAMandatoryTopicIsSubscribedToWhateverItsDefault(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('find');
        self::assertTrue(new Preferences($em, self::catalog(), new MockClock())->isSubscribed(3, 'security'));
    }

    public function testEveryTopicIsListedWithTheChoicesOfTheUserAlone(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects($this->once())
            ->method('findBy')
            ->with(['recipient' => 3])
            ->willReturn([new NotificationPreference(3, 'library', false, new \DateTimeImmutable())]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        self::assertSame(
            ['security' => true, 'library' => false, 'news' => false],
            new Preferences($em, self::catalog(), new MockClock())->all(3),
        );
    }

    public function testTheSubscribersOfAMandatoryTopicAreEveryoneWithoutAQuery(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');
        self::assertSame(
            [3 => true, 4 => true],
            new Preferences($em, self::catalog(), new MockClock())->subscribers([3, 4, 3], 'security'),
        );
    }

    public function testTheSubscribersOfATopicAreReadInOneQuery(): void
    {
        $at = new \DateTimeImmutable();
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects($this->exactly(2))
            ->method('findBy')
            ->willReturnCallback(static fn(array $criteria): array => match ($criteria['topic']) {
                'library' => [new NotificationPreference(4, 'library', false, $at)],
                default => [new NotificationPreference(5, 'news', true, $at)],
            })
            ->with(self::callback(static fn(array $criteria): bool => [3, 4, 5] === $criteria['recipient']));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $preferences = new Preferences($em, self::catalog(), new MockClock());
        // Subscribed by default unless they chose otherwise, or not unless they chose to.
        self::assertSame([3 => true, 5 => true], $preferences->subscribers([3, 4, 3, 5], 'library'));
        self::assertSame([5 => true], $preferences->subscribers([3, 4, 5], 'news'));
    }

    public function testTheChoicesOfManyUsersAreReadAThousandAtATime(): void
    {
        $sizes = [];
        $repository = $this->createStub(EntityRepository::class);
        $repository
            ->method('findBy')
            ->willReturnCallback(
                /** @param array{recipient: list<int>, topic: string} $criteria */
                static function (array $criteria) use (&$sizes): array {
                    $sizes[] = \count($criteria['recipient']);

                    return [];
                },
            );
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $preferences = new Preferences($em, self::catalog(), new MockClock());
        self::assertCount(1000, $preferences->subscribers(range(1, 1000), 'library'));
        self::assertSame([1000], $sizes);
        self::assertCount(1001, $preferences->subscribers(range(1, 1001), 'library'));
        self::assertSame([1000, 1000, 1], $sizes);
    }

    public function testATopicNobodyConfiguredHasNoSubscriber(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');
        self::assertSame([], new Preferences($em, self::catalog(), new MockClock())->subscribers([3], 'nope'));
    }

    public function testNoSubscriberIsLookedForAmongNobody(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');
        self::assertSame([], new Preferences($em, self::catalog(), new MockClock())->subscribers([], 'news'));
    }

    private static function catalog(): NotificationCatalog
    {
        $topic = static fn(bool $default, bool $mandatory): array => [
            'label' => 'Topic',
            'description' => null,
            'default' => $default,
            'mandatory' => $mandatory,
            'domain' => 'messages',
        ];

        return new NotificationCatalog([
            'security' => $topic(false, true),
            'library' => $topic(true, false),
            'news' => $topic(false, false),
        ]);
    }
}
