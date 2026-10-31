<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared;

use Doctrine\ORM\EntityManagerInterface;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Article;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Category;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Tag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The browser scenarios' fixtures, outside the `test` environment: `POST /e2e/articles` creates an article of
 * the scenario's own; `POST /admin/e2e/articles/{id}` changes one as a script or an import would, outside
 * every shared draft but on an admin path, so that the bundle announces it.
 */
final readonly class E2eController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /**
         * @var array{
         *     title?: string, summary?: string, content?: string, notes?: string, status?: string,
         *     audience?: string, featured?: bool, rating?: int|null, publishedOn?: string|null,
         *     category?: string|null, tags?: list<string|array{name: string, note?: string}>
         * } $data
         */
        $data = $request->toArray();
        $article = new Article($data['title'] ?? 'Article');
        $article->summary = $data['summary'] ?? 'Summary';
        $article->content = $data['content'] ?? '<p>Text</p>';
        $article->notes = $data['notes'] ?? '';
        $article->status = $data['status'] ?? 'draft';
        $article->audience = $data['audience'] ?? 'everyone';
        $article->featured = $data['featured'] ?? false;
        $article->rating = $data['rating'] ?? null;
        $publishedOn = $data['publishedOn'] ?? null;
        $article->publishedOn = null === $publishedOn ? null : new \DateTimeImmutable($publishedOn);
        $category = $data['category'] ?? null;
        $article->category = null === $category
            ? null
            : $this->em->getRepository(Category::class)->findOneBy(['name' => $category]);
        foreach ($data['tags'] ?? [] as $position => $tag) {
            $article->addTag(
                \is_string($tag) ? new Tag($tag, $position) : new Tag($tag['name'], $position, $tag['note'] ?? ''),
            );
        }
        $this->em->persist($article);
        $this->em->flush();

        return new JsonResponse(['id' => $article->id], 201);
    }

    /** Renames the article, removes the tags `remove` names and reorders the others as `order` lists them. */
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var array{title?: string, remove?: list<string>, order?: list<string>} $data */
        $data = $request->toArray();
        $article = $this->em->find(Article::class, $id);
        if (!$article instanceof Article) {
            return new JsonResponse(['error' => 'No such article.'], 404);
        }
        if (isset($data['title'])) {
            $article->title = $data['title'];
        }
        foreach ($article->tags->toArray() as $tag) {
            if (\in_array($tag->name, $data['remove'] ?? [], true)) {
                $article->removeTag($tag);
            }
            $position = array_search($tag->name, $data['order'] ?? [], true);
            if (\is_int($position)) {
                $tag->position = $position;
            }
        }
        $this->em->flush();

        return new JsonResponse(['id' => $article->id]);
    }
}
