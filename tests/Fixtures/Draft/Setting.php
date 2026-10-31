<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use Doctrine\ORM\Mapping as ORM;

/** An entity no article reaches: its views never refresh for an article's save. */
#[ORM\Entity]
#[ORM\Table(name: 'draft_fixture_setting')]
class Setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;
}
