<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use Doctrine\ORM\Mapping as ORM;

/** The root of a hierarchy: an admin may list every publication, or articles only. */
#[ORM\Entity]
#[ORM\Table(name: 'draft_fixture_publication')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string')]
#[ORM\DiscriminatorMap(['publication' => Publication::class, 'article' => Article::class])]
class Publication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;
}
