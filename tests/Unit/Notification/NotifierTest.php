<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\Notice;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Hub\Transport\Mercure\MercureTransport;
use Janalis\Collaborate\Hub\Transport\PublishFailedException;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Notification\Entity\Notification;
use Janalis\Collaborate\Notification\Entity\NotificationPreference;
use Janalis\Collaborate\Notification\Messenger\SendNotification;
use Janalis\Collaborate\Notification\NotificationCatalog;
use Janalis\Collaborate\Notification\NotificationRepository;
use Janalis\Collaborate\Notification\Notifier;
use Janalis\Collaborate\Notification\Preferences;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(Notifier::class)]
#[UsesClass(HubPublisher::class)]
#[UsesClass(Notice::class)]
#[UsesClass(MercureTransport::class)]
#[UsesClass(PublishFailedException::class)]
#[UsesClass(Topics::class)]
#[UsesClass(Notification::class)]
#[UsesClass(SendNotification::class)]
#[UsesClass(NotificationCatalog::class)]
#[UsesClass(NotificationRepository::class)]
#[UsesClass(Preferences::class)]
#[UsesClass(NotificationPreference::class)]
final class NotifierTest extends TestCase
{
    public function testWritesWithoutWebPush(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with(self::isInstanceOf(Notification::class));
        $em->expects($this->once())->method('flush');
        $notification = $this->notifier($em)->write(3, 'shared', ['%name%' => 'Bob'], '/x', new \DateTimeImmutable());
        self::assertInstanceOf(Notification::class, $notification);
        self::assertSame(3, $notification->recipient);
        self::assertSame('library', $notification->topic);
    }

    public function testSeveralUsersAreWrittenInOneFlushBeforeAnyPageIsTold(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $em
            ->expects($this->exactly(3))
            ->method('persist')
            ->willReturnCallback(static function (object $row) use (&$persisted): void {
                \assert($row instanceof Notification);
                $persisted[] = $row;
            });
        $em->expects($this->once())->method('flush');
        $this->notifier($em)->notifyAll([3, 4, 3], 'shared', ['%name%' => 'Bob'], '/x');
        self::assertSame([3, 4, 3], array_map(static fn(Notification $row): int => $row->recipient, $persisted));
        self::assertSame('/x', $persisted[0]->url);
        self::assertSame($persisted[0]->createdAt, $persisted[2]->createdAt);
    }

    public function testAUserWithoutACollaboratorIdIsToldNothing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');
        $this->notifier($em)->notify($this->createStub(UserInterface::class), 'shared');
    }

    public function testOnlyTheSubscribersOfItsTopicAreWritten(): void
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository
            ->method('findBy')
            ->willReturn([new NotificationPreference(4, 'news', true, new \DateTimeImmutable())]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em
            ->expects($this->once())
            ->method('persist')
            ->with(self::callback(static fn(Notification $row): bool => 4 === $row->recipient));
        $em->expects($this->once())->method('flush');
        $this->notifier($em)->notifyAll([3, 4], 'posted');
    }

    public function testNobodyToTellFlushesNothing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');
        $this->notifier($em)->notifyAll([], 'shared');
    }

    public function testSeveralUsersAreEachSentTheirOwnMessageThroughABus(): void
    {
        $messages = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$messages): Envelope {
                $messages[] = $message;

                return new Envelope($message);
            });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');
        $this->notifier($em, $bus)->notifyAll([3, 4], 'shared', ['%name%' => 'Bob'], '/x');
        self::assertContainsOnlyInstancesOf(SendNotification::class, $messages);
        self::assertSame([3, 4], [$messages[0]->recipient, $messages[1]->recipient]);
        self::assertSame('/x', $messages[1]->url);
        self::assertNotSame($messages[0]->key, $messages[1]->key);
    }

    public function testAnUnknownTypeIsRefusedBeforeItReachesTheBus(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $this->expectException(\InvalidArgumentException::class);
        $this->notifier($this->createStub(EntityManagerInterface::class), $bus)->notify(3, 'unknown');
    }

    public function testTheKeyOfAMessageIsARandomVersion4Uuid(): void
    {
        $keys = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$keys): Envelope {
            self::assertInstanceOf(SendNotification::class, $message);
            $keys[] = $message->key;

            return new Envelope($message);
        });
        $notifier = $this->notifier($this->createStub(EntityManagerInterface::class), $bus);
        for ($index = 0; $index < 64; ++$index) {
            $notifier->notify(3, 'shared');
        }
        $bytes = [];
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $key,
            );
            $bytes[] = (string) hex2bin(str_replace('-', '', $key));
        }
        self::assertCount(64, array_unique($keys));
        // What the version and the variant leave of their bytes is random, and each byte its own: of 64 keys,
        // some differ from their neighbours and some are odd, others even.
        self::assertTrue(self::some($bytes, static fn(string $b): bool => 1 === (\ord($b[6]) & 1)));
        self::assertTrue(self::some($bytes, static fn(string $b): bool => 0 === (\ord($b[6]) & 1)));
        self::assertTrue(self::some(
            $bytes,
            static fn(string $b): bool => (\ord($b[6]) & 0x0F) !== (\ord($b[5]) & 0x0F),
        ));
        self::assertTrue(self::some(
            $bytes,
            static fn(string $b): bool => (\ord($b[6]) & 0x0F) !== (\ord($b[7]) & 0x0F),
        ));
        self::assertTrue(self::some($bytes, static fn(string $b): bool => 1 === (\ord($b[8]) & 1)));
        self::assertTrue(self::some($bytes, static fn(string $b): bool => 0 === (\ord($b[8]) & 1)));
        self::assertTrue(self::some(
            $bytes,
            static fn(string $b): bool => (\ord($b[8]) & 0x3F) !== (\ord($b[7]) & 0x3F),
        ));
        self::assertTrue(self::some(
            $bytes,
            static fn(string $b): bool => (\ord($b[8]) & 0x3F) !== (\ord($b[9]) & 0x3F),
        ));
    }

    /**
     * @param list<string>          $bytes
     * @param \Closure(string): bool $test
     */
    private static function some(array $bytes, \Closure $test): bool
    {
        return [] !== array_filter($bytes, $test);
    }

    private function notifier(EntityManagerInterface $em, ?MessageBusInterface $bus = null): Notifier
    {
        $catalog = new NotificationCatalog(
            [
                'library' => [
                    'label' => 'Library',
                    'description' => null,
                    'default' => true,
                    'mandatory' => true,
                    'domain' => 'messages',
                ],
                // Nobody is subscribed unless they chose to.
                'news' => [
                    'label' => 'News',
                    'description' => null,
                    'default' => false,
                    'mandatory' => false,
                    'domain' => 'messages',
                ],
            ],
            [
                'shared' => [
                    'topic' => 'library',
                    'sentence' => '%name% shared',
                    'label' => null,
                    'icon' => null,
                    'domain' => 'messages',
                ],
                'posted' => [
                    'topic' => 'news',
                    'sentence' => 'Posted',
                    'label' => null,
                    'icon' => null,
                    'domain' => 'messages',
                ],
            ],
        );
        $clock = new MockClock();

        return new Notifier(
            $em,
            $catalog,
            new Preferences($em, $catalog, $clock),
            new NotificationRepository($em),
            $this->createStub(CollaboratorProviderInterface::class),
            new HubPublisher(
                new MercureTransport($this->createStub(HubInterface::class)),
                $this->createStub(LoggerInterface::class),
            ),
            new Topics(),
            $clock,
            bus: $bus,
        );
    }
}
