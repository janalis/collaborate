<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Janalis\Collaborate\Tests\Fixtures\Draft\Article;
use Janalis\Collaborate\Tests\Fixtures\Draft\Tag;

/** An entry of an {@see Article}'s form whose to-many association is mapped before its parent. */
#[ORM\Entity]
#[ORM\Table(name: 'doctrine_fixture_folder')]
class Folder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'doctrine_fixture_folder_tag')]
    public Collection $tags;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Article::class)]
        public ?Article $article = null,
    ) {
        $this->tags = new ArrayCollection();
    }
}
