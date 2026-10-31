<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use Doctrine\ORM\Mapping as ORM;

/** A join entity between an {@see Article} and a {@see Tag}, edited as an entry of the article's form. */
#[ORM\Entity]
#[ORM\Table(name: 'draft_fixture_tagging')]
class Tagging
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'taggings')]
        public ?Article $article = null,
        #[ORM\ManyToOne(targetEntity: Tag::class)]
        public ?Tag $tag = null,
    ) {}
}
