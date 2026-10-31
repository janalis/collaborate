<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Entity;

use Doctrine\ORM\Mapping as ORM;

/** One accepted batch of a {@see Draft} journal: appending inserts a row instead of rewriting the draft. */
#[ORM\Entity]
#[ORM\Table(name: 'collaborate_draft_event')]
#[ORM\UniqueConstraint(name: 'collaborate_draft_event_batch', columns: ['draft_id', 'batch_id'])]
class DraftEvent
{
    #[ORM\Id]
    #[ORM\Column(name: 'draft_id', length: 64)]
    public string $draftId;

    #[ORM\Id]
    #[ORM\Column]
    public int $revision;

    /** Batch identifiers and form names are case-sensitive. */
    #[ORM\Column(name: 'batch_id', length: 64, options: ['collation' => 'utf8mb4_bin'])]
    public string $batchId;

    #[ORM\Column(type: 'text', options: ['collation' => 'utf8mb4_bin'])]
    public string $field;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public array $payload = [];
}
