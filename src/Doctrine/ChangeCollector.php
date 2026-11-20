<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\RecordId;
use Janalis\Collaborate\Updates\SavedChanges;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The `onFlush` half of the saved-view updates: which entity classes an admin write can alter, and the
 * drafts of the records it changed. It records them as {@see SavedChanges} for the response to announce.
 */
final class ChangeCollector
{
    /** @var array<string, list<string>>|null Classes holding an association to each class: the mapping, built once per worker. */
    private ?array $inbound = null;

    private bool $enabled;

    private string $pathPattern;

    /** The `collaborate.updates` defaults the collector reads, which the configuration tree declares from here. */
    public const array DEFAULTS = [
        'path_pattern' => '#^/admin(?:/|$)#',
        'routes' => [],
        'association_depth' => 2,
        'ignored_fields' => ['updatedAt'],
    ];

    /** @var list<string> */
    private array $routes;

    private int $associationDepth;

    /** @var list<string> */
    private array $ignoredFields;

    /**
     * @param array{enabled?: bool|null, path_pattern?: string, routes?: list<string>, association_depth?: int, ignored_fields?: list<string>, ...} $options
     *     the `collaborate.updates` configuration
     * @param bool $drafts shared drafts (`collaborate.drafts.enabled`): their pages hear of changes to their records
     */
    public function __construct(
        private readonly DraftAccess $access,
        private readonly RequestStack $requests,
        array $options = [],
        private readonly ?TranslationMapperInterface $translations = null,
        bool $drafts = false,
    ) {
        // Saved views need the changed classes, open drafts the changed records: collect for either.
        $this->enabled = true === ($options['enabled'] ?? false) || $drafts;
        $this->pathPattern = $options['path_pattern'] ?? self::DEFAULTS['path_pattern'];
        $this->routes = $options['routes'] ?? self::DEFAULTS['routes'];
        $this->associationDepth = $options['association_depth'] ?? self::DEFAULTS['association_depth'];
        $this->ignoredFields = $options['ignored_fields'] ?? self::DEFAULTS['ignored_fields'];
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $request = $this->requests->getMainRequest();
        if (!$this->enabled || null === $request || !$this->announces($request)) {
            return;
        }
        $em = $event->getObjectManager();
        $uow = $em->getUnitOfWork();
        $changed = [];
        // A set: one save can name the same record many times.
        $records = [];
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            $changed[] = $collection->getTypeClass()->getName();
            // Both sides of a many-to-many association show it. A diff holds entities only.
            /** @var object $element */
            foreach ([...$collection->getInsertDiff(), ...$collection->getDeleteDiff()] as $element) {
                $records += array_fill_keys($this->records($em, $element), true);
            }
        }
        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            $changed[] = $collection->getTypeClass()->getName();
            foreach (self::objects($collection->getSnapshot()) as $element) {
                $records += array_fill_keys($this->records($em, $element), true);
            }
        }
        foreach ([
            ...$uow->getScheduledCollectionUpdates(),
            ...$uow->getScheduledCollectionDeletions(),
        ] as $collection) {
            $owner = $collection->getOwner();
            if (null !== $owner) {
                $changed[] = $em->getClassMetadata($owner::class)->getName();
                $records += array_fill_keys($this->records($em, $owner), true);
            }
        }
        foreach ([
            ...$uow->getScheduledEntityInsertions(),
            ...$uow->getScheduledEntityUpdates(),
            ...$uow->getScheduledEntityDeletions(),
        ] as $entity) {
            $changes = $uow->getEntityChangeSet($entity);
            if ([] !== $changes && [] === array_diff(array_keys($changes), $this->ignoredFields)) {
                continue;
            }
            // A translation row changes how its object reads.
            $translated = $this->translations?->translated($entity);
            if (null !== $translated) {
                [$class, $id] = $translated;
                $changed[] = $class;
                $records += array_fill_keys($this->drafts($em->getClassMetadata($class), $id), true);
                continue;
            }
            $metadata = $em->getClassMetadata($entity::class);
            $changed[] = $metadata->getName();
            $records += array_fill_keys($this->records($em, $entity), true);
            // An entry of a form collection belongs to its parent's form: the parent's record, before and after.
            foreach ($metadata->getAssociationMappings() as $field => $association) {
                if (!$association->isToOne()) {
                    continue;
                }
                foreach (self::objects([
                    $metadata->getFieldValue($entity, $field),
                    $changes[$field][0] ?? null,
                ]) as $parent) {
                    $records += array_fill_keys($this->records($em, $parent), true);
                }
            }
        }
        if ([] === $changed) {
            return;
        }
        SavedChanges::data($request, ...$this->affected($em, $changed));
        // A numeric key is an int: the draft id is its string.
        // @infection-ignore-all: a draft id is a hash; no test can make one PHP reads as an integer key.
        SavedChanges::records($request, ...array_map(strval(...), array_keys($records)));
    }

    /** Admin pages, and the other routes configured to write through the admin (e.g. a sort endpoint). */
    private function announces(Request $request): bool
    {
        return (
            \in_array($request->attributes->get('_route'), $this->routes, true)
            || 1 === preg_match($this->pathPattern, $request->getPathInfo())
        );
    }

    /** The drafts of the edit pages showing a stored entity: none before it has an id.
     *
     * @return list<string>
     */
    private function records(EntityManagerInterface $em, object $entity): array
    {
        $uow = $em->getUnitOfWork();
        if (!$uow->isInIdentityMap($entity)) {
            return [];
        }
        $identifier = $uow->getEntityIdentifier($entity);
        // The unit of work keeps identifiers flattened: a key derived from an association is the associated id.
        $id = RecordId::string(reset($identifier));
        // An edit page addresses single-column ids only.
        if (null === $id || 1 !== \count($identifier)) {
            return [];
        }

        return $this->drafts($em->getClassMetadata($entity::class), $id);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<object>
     */
    private static function objects(array $values): array
    {
        $objects = [];
        foreach (array_keys($values) as $key) {
            if (!\is_object($values[$key])) {
                continue;
            }

            $objects[] = $values[$key];
        }

        return $objects;
    }

    /** @param ClassMetadata<object> $metadata
     * @return list<string>
     */
    private function drafts(ClassMetadata $metadata, string $id): array
    {
        // An admin may manage a parent class of the stored entity.
        return array_map(fn(string $class): string => $this->access->draftId($class, $id), [
            $metadata->getName(),
            ...$metadata->parentClasses,
        ]);
    }

    /**
     * The classes of the views a change can alter: the changed classes, their hierarchy, and the
     * classes up to `association_depth` associations away in either direction.
     *
     * @param list<string> $changed
     *
     * @return list<string>
     */
    private function affected(EntityManagerInterface $em, array $changed): array
    {
        // A set, keyed by class name.
        $classes = [];
        foreach ($changed as $class) {
            $metadata = $em->getClassMetadata($class);
            foreach ([$metadata->getName(), ...$metadata->parentClasses, ...$metadata->subClasses] as $related) {
                $classes[$related] = $related;
            }
        }
        if (null === $this->inbound) {
            $this->inbound = [];
            foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
                foreach ($metadata->getAssociationMappings() as $association) {
                    $this->inbound[$association->targetEntity][] = $metadata->getName();
                }
            }
        }
        $inbound = $this->inbound;
        // Two hops by default: a join entity sits between a record and the records it lists.
        for ($hop = 0; $hop < $this->associationDepth; ++$hop) {
            // foreach walks a copy: the classes this hop adds are walked by the next one.
            foreach ($classes as $class) {
                foreach ($em->getClassMetadata($class)->getAssociationMappings() as $association) {
                    $classes[$association->targetEntity] = $association->targetEntity;
                }
                foreach ($inbound[$class] ?? [] as $source) {
                    $classes[$source] = $source;
                }
            }
        }

        // @infection-ignore-all: each class is keyed by itself; the values are the same names.
        return array_keys($classes);
    }
}
