<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use Doctrine\ORM\Mapping as ORM;

/** The other side of a many-to-many association of {@see Article}, with a subclass of its own. */
#[ORM\Entity]
#[ORM\Table(name: 'draft_fixture_category')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string')]
#[ORM\DiscriminatorMap(['category' => Category::class, 'section' => Section::class])]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;
}
