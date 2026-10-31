<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractTranslation;

/** A Gedmo translation row of an {@see Article}. */
#[ORM\Entity]
#[ORM\Table(name: 'draft_fixture_article_translation')]
class ArticleTranslation extends AbstractTranslation {}
