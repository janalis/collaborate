<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared\Entity;

use Doctrine\ORM\Mapping as ORM;

/** An ordered entry of an article's form collection. */
#[ORM\Entity]
#[ORM\Table(name: 'app_tag')]
class Tag implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'tags')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Article $article = null;

    public function __construct(
        #[ORM\Column(length: 100)]
        public string $name = '',
        #[ORM\Column]
        public int $position = 0,
    ) {}

    public function __toString(): string
    {
        return $this->name;
    }
}
