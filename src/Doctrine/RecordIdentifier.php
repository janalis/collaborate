<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Doctrine;

use Doctrine\Persistence\ManagerRegistry;

/** Reads the identifier of a mapped entity: an edit page addresses a record by its one identifier column. */
final readonly class RecordIdentifier
{
    public function __construct(
        private ?ManagerRegistry $doctrine = null,
    ) {}

    /** The identifier of a mapped entity with a single-column key, or null (unmapped, composite or not assigned yet). */
    public function value(object $entity): mixed
    {
        $manager = $this->doctrine?->getManagerForClass($entity::class);
        if (null === $manager) {
            return null;
        }
        $ids = $manager->getClassMetadata($entity::class)->getIdentifierValues($entity);

        return 1 === \count($ids) ? reset($ids) : null;
    }
}
