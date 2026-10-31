<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Gedmo;

use Janalis\Collaborate\Draft\FieldKeyResolver;
use Janalis\Collaborate\Gedmo\TranslatableFieldKeyResolver;
use Janalis\Collaborate\Tests\Fixtures\Draft\Article;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(TranslatableFieldKeyResolver::class)]
#[CoversClass(FieldKeyResolver::class)]
final class TranslatableFieldKeyResolverTest extends TestCase
{
    private function resolver(?string $locale): TranslatableFieldKeyResolver
    {
        $requests = new RequestStack();
        if (null !== $locale) {
            $request = Request::create('/admin/article/7/edit');
            $request->setLocale($locale);
            $requests->push($request);
        }

        return new TranslatableFieldKeyResolver($requests, 'en');
    }

    public function testATranslatedPropertyIsKeyedByTheLocaleItsValueBelongsTo(): void
    {
        $french = $this->resolver('fr');

        self::assertSame('fr:article[title]', $french->key(Article::class, 'title', 'article[title]', false));
        // Records are created in the default locale.
        self::assertSame('en:article[title]', $french->key(Article::class, 'title', 'article[title]', true));
        self::assertSame(':article[title]', $this->resolver(null)->key(
            Article::class,
            'title',
            'article[title]',
            false,
        ));
    }

    public function testOtherPropertiesAreSharedByEveryLocale(): void
    {
        $french = $this->resolver('fr');

        self::assertSame('article[parent]', $french->key(Article::class, 'parent', 'article[parent]', false));
        self::assertSame('article[extra]', $french->key(Article::class, 'extra', 'article[extra]', false));
        self::assertFalse($french->savedElsewhere(Article::class, 'parent', false));
    }

    public function testOnlyAnotherLocalesValueIsSavedElsewhere(): void
    {
        self::assertTrue($this->resolver('fr')->savedElsewhere(Article::class, 'title', false));
        self::assertFalse($this->resolver('fr')->savedElsewhere(Article::class, 'title', true));
        self::assertFalse($this->resolver('en')->savedElsewhere(Article::class, 'title', false));
    }

    public function testTheDefaultResolverSharesEveryField(): void
    {
        $resolver = new FieldKeyResolver();

        self::assertSame('article[title]', $resolver->key(Article::class, 'title', 'article[title]', false));
        self::assertFalse($resolver->savedElsewhere(Article::class, 'title', false));
    }
}
