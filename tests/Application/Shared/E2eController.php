<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared;

use Doctrine\ORM\EntityManagerInterface;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Article;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Tag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** `POST /e2e/articles` (the `e2e` environment only): an article of its own for each browser scenario. */
final readonly class E2eController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{title?: string, summary?: string, content?: string, tags?: list<string>} $data */
        $data = $request->toArray();
        $article = new Article($data['title'] ?? 'Article');
        $article->summary = $data['summary'] ?? 'Summary';
        $article->content = $data['content'] ?? '<p>Text</p>';
        foreach ($data['tags'] ?? [] as $position => $name) {
            $article->addTag(new Tag($name, $position));
        }
        $this->em->persist($article);
        $this->em->flush();

        return new JsonResponse(['id' => $article->id], 201);
    }
}
