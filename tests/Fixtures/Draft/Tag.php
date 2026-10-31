<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use Doctrine\ORM\Mapping as ORM;

/** Listed by articles through {@see Tagging}: two associations away from them. */
#[ORM\Entity]
#[ORM\Table(name: 'draft_fixture_tag')]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = '';
}
