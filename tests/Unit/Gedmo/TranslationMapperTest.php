<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Gedmo;

use Gedmo\Translatable\Entity\MappedSuperclass\AbstractTranslation;
use Janalis\Collaborate\Gedmo\TranslationMapper;
use Janalis\Collaborate\Tests\Fixtures\Draft\Article;
use Janalis\Collaborate\Tests\Fixtures\Draft\ArticleTranslation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationMapper::class)]
final class TranslationMapperTest extends TestCase
{
    public function testATranslationNamesItsObject(): void
    {
        $translation = new ArticleTranslation();
        $translation->setObjectClass(Article::class);
        // A hydrated translation holds its object's id as the column type, not the declared string.
        new \ReflectionProperty(AbstractTranslation::class, 'foreignKey')->setValue($translation, 5);

        self::assertSame([Article::class, '5'], new TranslationMapper()->translated($translation));
    }

    public function testAnythingElseTranslatesNothing(): void
    {
        $mapper = new TranslationMapper();
        $unknown = new ArticleTranslation();
        $unknown->setObjectClass('Missing\\Entity');

        self::assertNull($mapper->translated(new Article()));
        self::assertNull($mapper->translated(new ArticleTranslation()));
        self::assertNull($mapper->translated($unknown));
    }
}
