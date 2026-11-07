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
