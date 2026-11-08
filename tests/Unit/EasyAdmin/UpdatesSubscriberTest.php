<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\EasyAdmin;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Doctrine\ChangeCollector;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\EasyAdmin\UpdatesSubscriber;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\Notice;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Hub\Transport\Mercure\MercureTransport;
use Janalis\Collaborate\Hub\Transport\PublishFailedException;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\Draft\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(ChangeCollector::class)]
#[CoversClass(UpdatesSubscriber::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(HubPublisher::class)]
#[UsesClass(Notice::class)]
#[UsesClass(MercureTransport::class)]
#[UsesClass(PublishFailedException::class)]
#[UsesClass(Topics::class)]
final class UpdatesSubscriberTest extends TestCase
{
    private function access(bool $enabled = true): DraftAccess
    {
        return new DraftAccess(
            'secret',
            $enabled,
            new Topics(),
            new SecurityCollaboratorProvider(),
            new RoleDraftAccessChecker(),
        );
    }

    /** @param array{path_pattern?: string, routes?: list<string>, ignored_fields?: list<string>} $options */
    private function collector(RequestStack $requests, array $options = []): ChangeCollector
    {
        return new ChangeCollector($this->access(), $requests, $options + ['enabled' => true]);
    }

    private function subscriber(HubPublisher $publisher, bool $enabled = true): UpdatesSubscriber
    {
        return new UpdatesSubscriber($this->access(), new Topics(), $publisher, $enabled);
    }

    /** @param array<string, mixed> $changes */
    private function flush(object $entity, array $changes = []): OnFlushEventArgs
    {
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);
        $uow->method('getEntityChangeSet')->willReturn($changes);

        return new OnFlushEventArgs($this->entityManager($uow));
    }

    /** Metadata without associations: each change concerns its own class only. */
    private function entityManager(UnitOfWork $uow): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getClassMetadata')->willReturnCallback(static function (string $class): ClassMetadata {
            \assert(class_exists($class));

            return new ClassMetadata($class);
        });
        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('getAllMetadata')->willReturn([]);
        $em->method('getMetadataFactory')->willReturn($factory);

        return $em;
    }

    private function response(
        Request $request,
        int $status = 200,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): ResponseEvent {
        return new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            $type,
            new Response(status: $status),
        );
    }

    public function testPublishesOnlyContentlessInvalidationsAfterSuccessfulPersistence(): void
    {
        $request = Request::create('/admin/tag/7/edit');
        $requests = new RequestStack();
        $requests->push($request);
        $payloads = [
            'urn:collaborate:updates' => '{"channel":"updates","kind":"saved","entities":["Tag"]}',
            $this->access()->topic('draft') => '{"channel":"draft","draft":"draft","kind":"saved"}',
        ];
        // The redirect is not held back by the hub: publication waits for kernel.terminate.
        $terminated = false;
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects($this->exactly(2))
            ->method('publish')
            ->with(self::callback(static function (Update $event) use (&$terminated, $payloads): bool {
                return (
                    $terminated
                    && $event->isPrivate()
                    && ($payloads[$event->getTopics()[0]] ?? null) === $event->getData()
                );
            }));
        $publisher = new HubPublisher(new MercureTransport($hub), $this->createStub(LoggerInterface::class));
        $collector = $this->collector($requests);
        $collector->onFlush($this->flush(new Tag(), ['name' => ['old', 'new']]));
        // A second flush in the same request names its entities once.
        $collector->onFlush($this->flush(new Tag(), ['name' => ['new', 'newer']]));
        self::assertSame([Tag::class, Tag::class], $request->attributes->all('_collaborate_data_changed'));
        $request->attributes->set('_collaborate_committed', 'draft');
        $this->subscriber($publisher)->publish($this->response($request, 302));
        $terminated = true;
        $publisher->publish();
        self::assertSame([KernelEvents::RESPONSE => ['publish', -2050]], UpdatesSubscriber::getSubscribedEvents());
    }

    public function testReadsTheAssociationMappingOnceForEveryFlush(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/admin/tag/7/edit'));
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityUpdates')->willReturn([new Tag()]);
        $uow->method('getEntityChangeSet')->willReturn(['name' => ['old', 'new']]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getClassMetadata')->willReturnCallback(static function (string $class): ClassMetadata {
            \assert(class_exists($class));

            return new ClassMetadata($class);
        });
        $factory = $this->createMock(ClassMetadataFactory::class);
        $factory->expects($this->once())->method('getAllMetadata')->willReturn([]);
        $em->method('getMetadataFactory')->willReturn($factory);
        $collector = $this->collector($requests);

        $collector->onFlush(new OnFlushEventArgs($em));
        $collector->onFlush(new OnFlushEventArgs($em));

        self::assertSame(
            [Tag::class, Tag::class],
            $requests->getMainRequest()?->attributes->all('_collaborate_data_changed'),
        );
    }

    public function testIgnoresSessionNoisePublicRequestsAndFailedResponses(): void
    {
        $requests = new RequestStack();
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->never())->method('publish');
        $publisher = new HubPublisher(new MercureTransport($hub), $this->createStub(LoggerInterface::class));
        $collector = $this->collector($requests, ['ignored_fields' => ['lastLogin', 'updatedAt']]);
        $collector->onFlush($this->flush(new Tag()));
        $public = Request::create('/tags');
        $requests->push($public);
        $collector->onFlush($this->flush(new Tag()));
        self::assertFalse($public->attributes->has('_collaborate_data_changed'));
        $requests->pop();
        $request = Request::create('/admin/tag');
        $requests->push($request);
        $collector->onFlush($this->flush(new Tag(), ['lastLogin' => [null, 'now'], 'updatedAt' => [null, 'now']]));
        self::assertFalse($request->attributes->has('_collaborate_data_changed'));
        $request->attributes->set('_collaborate_data_changed', [Tag::class]);
        $subscriber = $this->subscriber($publisher);
        $subscriber->publish($this->response($request, 500));
        $subscriber->publish($this->response($request, 200, HttpKernelInterface::SUB_REQUEST));
        $disabled = new ChangeCollector($this->access(), $requests, ['enabled' => false]);
        $other = Request::create('/admin/tag/7/edit');
        $requests->push($other);
        $disabled->onFlush($this->flush(new Tag()));
        self::assertFalse($other->attributes->has('_collaborate_data_changed'));
        // Shared drafts alone collect: their pages hear of changes to their records.
        $draftRequests = new RequestStack();
        $draftRequests->push($other);
        new ChangeCollector($this->access(), $draftRequests, ['enabled' => false], null, true)->onFlush($this->flush(
            new Tag(),
        ));
        self::assertContains(Tag::class, $other->attributes->all('_collaborate_data_changed'));
        $this->subscriber($publisher, false)->publish($this->response($request));
        $publisher->publish();
    }

    public function testAdminPathsFollowTheConfiguredPattern(): void
    {
        $requests = new RequestStack();
        $collector = $this->collector($requests, ['path_pattern' => '#^/(?:en|fr|de)/admin(?:/|$)#D']);
        $configured = Request::create('/de/admin/tag/7/edit');
        $requests->push($configured);
        $collector->onFlush($this->flush(new Tag()));
        $requests->pop();
        $other = Request::create('/it/admin/tag/7/edit');
        $requests->push($other);
        $collector->onFlush($this->flush(new Tag()));

        self::assertSame([Tag::class], $configured->attributes->all('_collaborate_data_changed'));
        self::assertFalse($other->attributes->has('_collaborate_data_changed'));
    }

    public function testAssociationOnlyEditsAlsoInvalidateSavedViews(): void
    {
        $request = Request::create('/admin/tag/7/edit');
        $requests = new RequestStack();
        $requests->push($request);
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);
        $uow->method('getScheduledCollectionUpdates')->willReturn([new PersistentCollection(
            $this->createStub(EntityManagerInterface::class),
            new ClassMetadata(Tag::class),
            new ArrayCollection(),
        )]);
        $uow->method('getScheduledCollectionDeletions')->willReturn([]);
        $em = $this->entityManager($uow);
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects($this->once())
            ->method('publish')
            ->with(self::callback(
                static fn(Update $event): bool => '{"channel":"updates","kind":"saved","entities":["Tag"]}' === $event->getData(),
            ));
        $publisher = new HubPublisher(new MercureTransport($hub), $this->createStub(LoggerInterface::class));
        $this->collector($requests)->onFlush(new OnFlushEventArgs($em));
        $this->subscriber($publisher)->publish($this->response($request));
        $publisher->publish();
        self::assertSame([Tag::class], $request->attributes->get('_collaborate_data_changed'));
    }

    public function testConfiguredRoutesInvalidateAndHubFailuresDoNotFailTheSavedResponse(): void
    {
        $request = Request::create('/sort/tag');
        $request->attributes->set('_route', 'sort_entries');
        $requests = new RequestStack();
        $requests->push($request);
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects($this->once())
            ->method('publish')
            ->willThrowException(new \RuntimeException('offline'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $publisher = new HubPublisher(new MercureTransport($hub), $logger);
        $this->collector($requests, ['routes' => ['sort_entries']])->onFlush($this->flush(new Tag()));
        self::assertSame([Tag::class], $request->attributes->get('_collaborate_data_changed'));
        $this->subscriber($publisher)->publish($this->response($request));
        $publisher->publish();
    }

    public function testRecordNoticesSkipTheDraftSavedThroughItsOwnFormAndComeInChunks(): void
    {
        $access = $this->access();
        $published = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = [$update->getTopics(), $update->getData()];

            return 'id';
        });
        $publisher = new HubPublisher(new MercureTransport($hub), $this->createStub(LoggerInterface::class));
        $request = Request::create('/admin/tag/1/edit');
        $records = array_map(static fn(int $id): string => $access->draftId(Tag::class, (string) $id), range(1, 6));
        // A value that names no draft is skipped, wherever it stands.
        $request->attributes->set('_collaborate_records_changed', [42, ...$records, $records[0], 42]);
        $request->attributes->set('_collaborate_committed', $records[0]);

        new UpdatesSubscriber($access, new Topics(), $publisher, true, 3)->publish($this->response($request, 302));
        $publisher->publish();

        self::assertSame(
            [
                [[$access->topic($records[0])], '{"channel":"draft","draft":"' . $records[0] . '","kind":"saved"}'],
                [array_map($access->topic(...), \array_slice($records, 1, 3)), '{"channel":"draft","kind":"record"}'],
                [array_map($access->topic(...), \array_slice($records, 4, 2)), '{"channel":"draft","kind":"record"}'],
            ],
            $published,
        );
    }

    public function testAChunkSizeBelowOneNamesOneDraftPerNotice(): void
    {
        $access = $this->access();
        foreach ([0, 1] as $size) {
            $published = [];
            $hub = $this->createStub(HubInterface::class);
            $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
                $published[] = $update->getTopics();

                return 'id';
            });
            $publisher = new HubPublisher(new MercureTransport($hub), $this->createStub(LoggerInterface::class));
            $request = Request::create('/admin/tag/1/edit');
            $request->attributes->set('_collaborate_records_changed', ['first', 'second']);

            new UpdatesSubscriber($access, new Topics(), $publisher, true, $size)->publish($this->response($request));
            $publisher->publish();

            self::assertSame([[$access->topic('first')], [$access->topic('second')]], $published);
        }
    }

    public function testSavedViewsAndDraftsAreAnnouncedUnderTheirOwnFlags(): void
    {
        $access = $this->access();
        $draft = $access->draftId(Tag::class, '1');
        foreach ([[true, false, ['updates']], [false, true, ['draft']], [false, false, []]] as [
            $updates,
            $drafts,
            $channels,
        ]) {
            $published = [];
            $hub = $this->createStub(HubInterface::class);
            $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
                /** @var array{channel: string} $data */
                $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
                $published[] = $data['channel'];

                return 'id';
            });
            $publisher = new HubPublisher(new MercureTransport($hub), $this->createStub(LoggerInterface::class));
            $request = Request::create('/admin/tag/1/edit');
            $request->attributes->set('_collaborate_data_changed', [Tag::class]);
            $request->attributes->set('_collaborate_committed', $draft);

            new UpdatesSubscriber($access, new Topics(), $publisher, $updates, 100, $drafts)->publish($this->response(
                $request,
                302,
            ));
            $publisher->publish();

            self::assertSame($channels, $published);
        }
    }

    public function testByDefaultAnnouncesSortedDistinctEntitiesAndPrivateRecordNoticesOfAHundredDrafts(): void
    {
        $access = $this->access();
        $request = Request::create('/admin/article/1/edit', 'POST');
        $request->attributes->set('_collaborate_data_changed', [
            'App\Entity\Tag',
            'App\Entity\Article',
            'App\Entity\Tag',
        ]);
        $records = array_map(static fn(int $id): string => 'record-' . $id, range(1, 101));
        $request->attributes->set('_collaborate_records_changed', [...$records, 'record-1', 'own']);
        $request->attributes->set('_collaborate_committed', 'own');
        /** @var \ArrayObject<int, Update> $log */
        $log = new \ArrayObject();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use ($log): string {
            $log->append($update);

            return 'id';
        });
        $publisher = new HubPublisher(new MercureTransport($hub), $this->createStub(LoggerInterface::class));
        $subscriber = new UpdatesSubscriber($access, new Topics(), $publisher);

        // A response that fails announces nothing.
        $subscriber->publish($this->response($request, 400));
        $publisher->publish();
        self::assertCount(0, $log);

        $subscriber->publish($this->response($request, 399));
        $publisher->publish();
        $published = array_values($log->getArrayCopy());
        self::assertCount(4, $published);
        [$saved, $own, $first, $last] = $published;
        self::assertSame('{"channel":"updates","kind":"saved","entities":["Article","Tag"]}', $saved->getData());
        self::assertSame([$access->topic('own')], $own->getTopics());
        // The other records' drafts, each named once, by notices of a hundred topics at most.
        self::assertSame(array_map($access->topic(...), array_slice($records, 0, 100)), $first->getTopics());
        self::assertSame([$access->topic('record-101')], $last->getTopics());
        foreach ($published as $update) {
            self::assertTrue($update->isPrivate());
        }
    }
}
