<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** The record both test applications edit: plain text, a textarea, a rich-text field, a choice and ordered tags. */
#[ORM\Entity]
#[ORM\Table(name: 'app_article')]
class Article implements \Stringable
{
    public const array STATUSES = ['Draft' => 'draft', 'Review' => 'review', 'Published' => 'published'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $title = '';

    #[ORM\Column(type: 'text')]
    public string $summary = '';

    /** HTML written in CKEditor. */
    #[ORM\Column(type: 'text')]
    public string $content = '';

    #[ORM\Column(length: 20)]
    public string $status = 'draft';

    /** @var Collection<int, Tag> */
    #[ORM\OneToMany(targetEntity: Tag::class, mappedBy: 'article', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    public Collection $tags;

    public function __construct(string $title = '')
    {
        $this->title = $title;
        $this->tags = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title;
    }

    /** The adder a form collection calls (`by_reference: false`). */
    public function addTag(Tag $tag): void
    {
        $tag->article = $this;
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
    }

    /** The remover a form collection calls; the orphaned tag is deleted. */
    public function removeTag(Tag $tag): void
    {
        $this->tags->removeElement($tag);
    }
}
