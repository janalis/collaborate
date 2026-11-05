<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Plain;

use Janalis\Collaborate\Form\FormContextInterface;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Article;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The host's form context: {@see ArticleController} names the article its page edits on the request. */
final readonly class PlainFormContext implements FormContextInterface
{
    /** Request attribute holding `[Article $article, bool $new]`. */
    public const string RECORD = '_app_record';

    public function __construct(
        private RequestStack $requests,
        private UrlGeneratorInterface $urls,
    ) {}

    public function current(): ?array
    {
        $request = $this->requests->getMainRequest();
        if (null === $request) {
            return null;
        }
        $attributes = $request->attributes->all();
        if (
            !\is_array($attributes[self::RECORD] ?? null) || !($attributes[self::RECORD][0] ?? null) instanceof Article
        ) {
            return null;
        }
        $record = $attributes[self::RECORD];
        $article = $record[0];
        $new = true === ($record[1] ?? null);
        $query = $request->query->all();

        return [
            'entity' => $article,
            'class' => Article::class,
            'new' => $new,
            'id' => $new ? null : (string) $article->id,
            'draft' => \is_string($query['draft'] ?? null) ? $query['draft'] : null,
        ];
    }

    public function editUrl(): string
    {
        return $this->urls->generate('article_edit', ['id' => '__record__']);
    }
}
