<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared\Security;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** `GET /login`: the sign-in form {@see LoginAuthenticator} reads. */
final readonly class LoginController
{
    public function __construct(
        private Environment $twig,
    ) {}

    public function __invoke(): Response
    {
        return new Response($this->twig->render('@Shared/login.html.twig'));
    }
}
