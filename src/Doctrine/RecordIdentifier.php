<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Janalis\Collaborate\Draft\RecordIdentifierInterface;

/** Reads the identifier of a mapped entity: an edit page addresses a record by its one identifier column. */
final readonly class RecordIdentifier implements RecordIdentifierInterface
{
    public function __construct(
        private ?ManagerRegistry $doctrine = null,
    ) {}

    /**
     * The identifier of a mapped entity with a single-column key, or null (unmapped, composite or not assigned yet).
     * A key derived from an association is the associated entity's own identifier, as Doctrine's unit of work
     * flattens it: never the entity, whose string is a label.
     */
    public function value(object $entity): mixed
    {
        $doctrine = $this->doctrine;
        if (null === $doctrine) {
            return null;
        }
        $manager = $doctrine->getManagerForClass($entity::class);
        if (null === $manager) {
            return null;
        }
        $ids = $manager->getClassMetadata($entity::class)->getIdentifierValues($entity);
        // @mago-expect analysis:mixed-assignment an identifier is mixed: the next line narrows it
        $id = 1 === \count($ids) ? reset($ids) : null;

        // The registry, unlike a metadata factory, reads a lazy-loading proxy as the entity it stands for.
        return \is_object($id) && null !== $doctrine->getManagerForClass($id::class) ? $this->value($id) : $id;
    }
}
