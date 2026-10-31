<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Entity;

use Doctrine\ORM\Mapping as ORM;

/** One field state of a {@see Draft}: an append rewrites its own field, not the whole draft. */
#[ORM\Entity]
#[ORM\Table(name: 'collaborate_draft_field')]
class DraftField
{
    #[ORM\Id]
    #[ORM\Column(name: 'draft_id', length: 64)]
    public string $draftId;

    /** Form names are case-sensitive. */
    #[ORM\Id]
    #[ORM\Column(length: 512, options: ['collation' => 'utf8mb4_bin'])]
    public string $name;

    #[ORM\Column(length: 16)]
    public string $type;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public array $state = [];
}
