<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One shared draft. Infrastructure state, independent of the edited entities and their listeners; the
 * journal reads and writes it with SQL, and the mapping keeps the schema tools aware of it. Column names
 * are explicit: the journal's SQL does not follow the application's naming strategy.
 */
#[ORM\Entity]
#[ORM\Table(name: 'collaborate_draft')]
// The purge finds the drafts idle since a date: without an index, it reads the whole table.
#[ORM\Index(name: 'collaborate_draft_updated_at', columns: ['updated_at'])]
class Draft
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $id;

    #[ORM\Column]
    public int $generation = 1;

    #[ORM\Column]
    public int $revision = 0;

    #[ORM\Column(name: 'saved_revision')]
    public int $savedRevision = 0;

    #[ORM\Column]
    public int $bytes = 0;

    #[ORM\Column(name: 'record_id', length: 255, nullable: true)]
    public ?string $recordId = null;

    /** The editor whose Save the draft last checkpointed, and the name the others see. */
    #[ORM\Column(name: 'saved_by', nullable: true)]
    public ?int $savedBy = null;

    #[ORM\Column(name: 'saved_by_label', length: 255, nullable: true)]
    public ?string $savedByLabel = null;

    /** The last write: an idle draft without unsaved changes is purged (`collaborate:purge`). */
    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', columnDefinition: 'DATETIME(6) NOT NULL')]
    public \DateTimeImmutable $updatedAt;
}
