<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Plain;

use Doctrine\ORM\EntityManagerInterface;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Article;
use Janalis\Collaborate\Tests\Application\Shared\Form\ArticleType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * A host's own admin pages, as the bundle expects them: the form's root is the edited article, a save
 * tells {@see DraftSave::completed()} once the article is persisted, and `X-Collaborate-Refresh: 1`
 * answers the form's fresh grant and CSRF tokens.
 */
final readonly class ArticleController
{
    public function __construct(
        private EntityManagerInterface $em,
        private FormFactoryInterface $forms,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private DraftSave $save,
    ) {}

    public function index(): Response
    {
        return new Response($this->twig->render('index.html.twig', [
            'articles' => $this->em->getRepository(Article::class)->findBy([], ['id' => 'ASC']),
        ]));
    }

    public function edit(Request $request, string $id): Response
    {
        $article = ctype_digit($id) ? $this->em->find(Article::class, (int) $id) : null;
        if (!$article instanceof Article) {
            throw new NotFoundHttpException('No such article.');
        }

        return $this->form($request, $article, false);
    }

    public function new(Request $request): Response
    {
        return $this->form($request, new Article(), true);
    }

    private function form(Request $request, Article $article, bool $new): Response
    {
        $request->attributes->set(PlainFormContext::RECORD, [$article, $new]);
        $form = $this->forms->create(ArticleType::class, $article);
        if ($request->isMethodSafe() && '1' === $request->headers->get('X-Collaborate-Refresh')) {
            return $this->refresh($form->createView());
        }
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($article);
            $this->em->flush();
            $this->save->completed($article);

            return new RedirectResponse($this->urls->generate('article_edit', [
                'id' => $article->id,
            ]), Response::HTTP_SEE_OTHER);
        }

        return new Response(
            $this->twig->render('edit.html.twig', [
                'form' => $form->createView(),
                'article' => $article,
                'new' => $new,
            ]),
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }

    /** The answer the draft session reads before a Save: the form's `data-collaborate` configuration and its CSRF tokens. */
    private function refresh(FormView $view): JsonResponse
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $view->vars['attr'];

        return new JsonResponse([
            'configuration' => \is_string($attributes['data-collaborate'] ?? null)
                ? json_decode($attributes['data-collaborate'], true, flags: \JSON_THROW_ON_ERROR)
                : null,
            'tokens' => $this->tokens($view),
        ], headers: ['Cache-Control' => 'no-store', 'Vary' => 'X-Collaborate-Refresh']);
    }

    /** @return array<string, string> */
    private function tokens(FormView $view): array
    {
        $tokens = [];
        foreach ($view->children as $name => $child) {
            if (
                '_token' === $name
                && \is_string($child->vars['full_name'] ?? null)
                && \is_string($child->vars['value'] ?? null)
            ) {
                $tokens[$child->vars['full_name']] = $child->vars['value'];
                continue;
            }
            $tokens += $this->tokens($child);
        }

        return $tokens;
    }
}
