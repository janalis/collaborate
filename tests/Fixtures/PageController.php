<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** `GET /admin/page`: a page of the test application rendering its flashes, the presence and the layer. */
final readonly class PageController
{
    public function __construct(
        private Environment $twig,
    ) {}

    public function __invoke(): Response
    {
        return new Response($this->twig->render('page.html.twig'));
    }
}
