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
        $record = $request?->attributes->get(self::RECORD);
        if (null === $request || !\is_array($record) || !($record[0] ?? null) instanceof Article) {
            return null;
        }
        [$article, $new] = $record;
        $draft = $request->query->get('draft');

        return [
            'entity' => $article,
            'class' => Article::class,
            'new' => true === $new,
            'id' => true === $new ? null : (string) $article->id,
            'draft' => \is_string($draft) ? $draft : null,
        ];
    }

    public function editUrl(): string
    {
        return $this->urls->generate('article_edit', ['id' => '__record__']);
    }
}
