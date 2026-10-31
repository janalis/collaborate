<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Functional\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ToManyAssociationMapping;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractTranslation;
use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Doctrine\ChangeCollector;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Gedmo\TranslationMapper;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\Draft\Article;
use Janalis\Collaborate\Tests\Fixtures\Draft\ArticleTranslation;
use Janalis\Collaborate\Tests\Fixtures\Draft\Category;
use Janalis\Collaborate\Tests\Fixtures\Draft\Publication;
use Janalis\Collaborate\Tests\Fixtures\Draft\Section;
use Janalis\Collaborate\Tests\Fixtures\Draft\Setting;
use Janalis\Collaborate\Tests\Fixtures\Draft\Tag;
use Janalis\Collaborate\Tests\Fixtures\Draft\Tagging;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** The entities and records a write names, computed from a real Doctrine mapping. */
#[CoversClass(ChangeCollector::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(TranslationMapper::class)]
#[UsesClass(Topics::class)]
final class ChangeCollectorMetadataTest extends TestCase
{
    private static ?EntityManagerInterface $em = null;

    private static function em(): EntityManagerInterface
    {
        if (null === self::$em) {
            $configuration = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 2) . '/Fixtures/Draft'], true);
            $configuration->enableNativeLazyObjects(true);
            self::$em = new EntityManager(DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ]), $configuration);
        }

        return self::$em;
    }

    private function access(): DraftAccess
    {
        return new DraftAccess(
            'secret',
            true,
            new Topics(),
            new SecurityCollaboratorProvider(),
            new RoleDraftAccessChecker(),
        );
    }

    /**
     * @param list<object>                 $updates
     * @param list<PersistentCollection<array-key, object>> $collections
     * @param \WeakMap<object, mixed>      $ids         the stored entities and their ids
     * @param array<string, mixed>         $changes
     * @param list<PersistentCollection<array-key, object>> $deletions
     *
     * @return Request the request the collector wrote to
     */
    private function flush(
        array $updates,
        array $collections = [],
        \WeakMap $ids = new \WeakMap(),
        array $changes = [],
        array $deletions = [],
        int $depth = 2,
    ): Request {
        $real = self::em();
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn($updates);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);
        $uow->method('getScheduledCollectionUpdates')->willReturn($collections);
        $uow->method('getScheduledCollectionDeletions')->willReturn($deletions);
        $uow->method('getEntityChangeSet')->willReturn($changes + ['name' => ['old', 'new']]);
        $uow->method('isInIdentityMap')->willReturnCallback(static fn(object $entity): bool => isset($ids[$entity]));
        $uow->method('getEntityIdentifier')->willReturnCallback(static fn(object $entity): array => (
            \is_array($ids[$entity]) ? $ids[$entity] : ['id' => $ids[$entity]]
        ));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getClassMetadata')->willReturnCallback($real->getClassMetadata(...));
        $em->method('getMetadataFactory')->willReturn($real->getMetadataFactory());
        $request = Request::create('/admin/article/1/edit');
        $requests = new RequestStack();
        $requests->push($request);
        new ChangeCollector(
            $this->access(),
            $requests,
            ['enabled' => true, 'association_depth' => $depth],
            new TranslationMapper(),
        )->onFlush(new OnFlushEventArgs($em));

        return $request;
    }

    /**
     * @param list<object> $updates
     *
     * @return list<string>
     */
    private function affected(array $updates, int $depth = 2): array
    {
        return $this->strings($this->flush($updates, depth: $depth)->attributes->all('_collaborate_data_changed'));
    }

    /**
     * @param list<object>                                  $updates
     * @param list<PersistentCollection<array-key, object>> $collections
     * @param \WeakMap<object, mixed>                       $ids
     * @param array<string, mixed>                          $changes
     * @param list<PersistentCollection<array-key, object>> $deletions
     *
     * @return list<string>
     */
    private function records(
        array $updates,
        array $collections,
        \WeakMap $ids,
        array $changes = [],
        array $deletions = [],
    ): array {
        return array_values(array_unique($this->strings($this->flush(
            $updates,
            $collections,
            $ids,
            $changes,
            $deletions,
        )->attributes->all('_collaborate_records_changed'))));
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    private function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            self::assertIsString($value);
            $strings[] = $value;
        }

        return $strings;
    }

    /** @return list<string> */
    private function drafts(string $id, string ...$classes): array
    {
        $access = $this->access();

        return array_values(array_map(static fn(string $class): string => $access->draftId($class, $id), $classes));
    }

    /**
     * @param class-string $owner
     *
     * @return PersistentCollection<array-key, object>
     */
    private function collection(
        string $owner,
        string $field,
        string $element,
        object $of,
        array $elements,
    ): PersistentCollection {
        $em = self::em();
        /** @var PersistentCollection<array-key, object> $collection */
        $collection = new PersistentCollection($em, $em->getClassMetadata($element), new ArrayCollection($elements));
        $mapping = $em->getClassMetadata($owner)->getAssociationMapping($field);
        self::assertInstanceOf(ToManyAssociationMapping::class, $mapping);
        $collection->setOwner($of, $mapping);
        $collection->takeSnapshot();

        return $collection;
    }

    public function testATagChangeReachesTheArticlesListingItButNotUnrelatedLists(): void
    {
        $affected = $this->affected([new Tag()]);

        self::assertContains(Tag::class, $affected);
        // Articles list their tags through the Tagging join entity.
        self::assertContains(Article::class, $affected);
        self::assertNotContains(Setting::class, $affected);
        // One association away, the join entity is all a tag reaches.
        $near = $this->affected([new Tag()], 1);
        self::assertContains(Tagging::class, $near);
        self::assertNotContains(Article::class, $near);
    }

    public function testATranslationChangesItsObjectAndAnArticleItsPublicationHierarchy(): void
    {
        $translation = new ArticleTranslation();
        $translation->setObjectClass(Article::class);
        self::assertContains(Article::class, $this->affected([$translation]));
        self::assertContains(Publication::class, $this->affected([new Article()]));
        // A parent class reaches its subclasses' lists as well.
        self::assertContains(Section::class, $this->affected([new Category()]));
    }

    public function testACollectionChangeConcernsItsOwnerAndItsElements(): void
    {
        $affected = $this->flush([], [$this->collection(
            Article::class,
            'taggings',
            Tagging::class,
            new Article(),
            [],
        )])->attributes->all('_collaborate_data_changed');

        self::assertContains(Article::class, $affected);
        self::assertContains(Tagging::class, $affected);
    }

    public function testAnEntryNotifiesTheRecordsOfItsParentsBeforeAndAfterButNotItself(): void
    {
        $before = new Article();
        $after = new Article();
        $tag = new Tag();
        $entry = new Tagging($after, $tag);
        $ids = new \WeakMap();
        $ids[$before] = 7;
        $ids[$after] = 8;
        $ids[$tag] = 3;

        self::assertEqualsCanonicalizing(
            [
                ...$this->drafts('8', Article::class, Publication::class),
                ...$this->drafts('3', Tag::class),
                ...$this->drafts('7', Article::class, Publication::class),
            ],
            $this->records([$entry], [], $ids, ['article' => [$before, $after]]),
        );
    }

    public function testATranslationNotifiesItsObjectsRecordAndAnUnsavedEntityNone(): void
    {
        $translation = new ArticleTranslation();
        $translation->setObjectClass(Article::class);
        // A hydrated translation holds its object's id as the column type, not the declared string.
        new \ReflectionProperty(AbstractTranslation::class, 'foreignKey')->setValue($translation, 5);

        self::assertSame(
            $this->drafts('5', Article::class, Publication::class),
            $this->records([$translation], [], new \WeakMap()),
        );
        self::assertSame([], $this->records([new Tag()], [], new \WeakMap()));
    }

    public function testAManyToManyChangeNotifiesBothSides(): void
    {
        $article = new Article();
        $kept = new Section();
        $added = new Section();
        $collection = $this->collection(Article::class, 'categories', Category::class, $article, [$kept]);
        $collection->add($added);
        $ids = new \WeakMap();
        $ids[$article] = 11;
        $ids[$kept] = 12;
        $ids[$added] = 13;

        self::assertEqualsCanonicalizing(
            [
                ...$this->drafts('13', Section::class, Category::class),
                ...$this->drafts('11', Article::class, Publication::class),
            ],
            $this->records([], [$collection], $ids),
        );
    }

    public function testADeletedCollectionNotifiesItsFormerElementsAndSkipsCompositeKeys(): void
    {
        $article = new Article();
        $section = new Section();
        $composite = new Section();
        $collection = $this->collection(
            Article::class,
            'categories',
            Category::class,
            $article,
            [$section, $composite, 'stray'],
        );
        $ids = new \WeakMap();
        $ids[$article] = 11;
        $ids[$section] = 14;
        $ids[$composite] = ['a' => 1, 'b' => 2];

        self::assertEqualsCanonicalizing(
            [
                ...$this->drafts('14', Section::class, Category::class),
                ...$this->drafts('11', Article::class, Publication::class),
            ],
            $this->records([], [], $ids, deletions: [$collection]),
        );
    }
}
