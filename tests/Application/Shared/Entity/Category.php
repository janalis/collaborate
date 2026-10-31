<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared\Entity;

use Doctrine\ORM\Mapping as ORM;

/** What an article is filed under: a choice among saved records, an autocomplete in EasyAdmin. */
#[ORM\Entity]
#[ORM\Table(name: 'app_category')]
class Category implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 100)]
        public string $name = '',
    ) {}

    public function __toString(): string
    {
        return $this->name;
    }
}
