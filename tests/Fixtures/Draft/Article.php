<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/** An edited record: translated text, an ordered collection of its own kind, tags through a join entity and categories. */
#[ORM\Entity]
#[ORM\Table(name: 'draft_fixture_article')]
#[Gedmo\TranslationEntity(class: ArticleTranslation::class)]
class Article extends Publication
{
    #[Gedmo\Translatable]
    #[ORM\Column(nullable: true)]
    public ?string $title = null;

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $body = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    public ?Article $parent = null;

    /** @var Collection<int, Article> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    public Collection $children;

    /** @var Collection<int, Tagging> */
    #[ORM\OneToMany(targetEntity: Tagging::class, mappedBy: 'article')]
    public Collection $taggings;

    /** @var Collection<int, Category> */
    #[ORM\ManyToMany(targetEntity: Category::class)]
    #[ORM\JoinTable(name: 'draft_fixture_article_category')]
    public Collection $categories;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->taggings = new ArrayCollection();
        $this->categories = new ArrayCollection();
    }
}
