<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Host;

use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\HeadlessDraft;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Security\CurrentUserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/** An isolated headless host; its normal library pages keep form drafts disabled. */
final readonly class TextController
{
    private HeadlessDraft $draft;

    public function __construct(
        DraftJournal $journal,
        CollaboratorProviderInterface $collaborators,
        Topics $topics,
        private CurrentUserInterface $security,
        private CsrfTokenManagerInterface $csrf,
        private Environment $twig,
    ) {
        $this->draft = new HeadlessDraft(
            new DraftAccess('host-text-example-test-secret', true, $topics, $collaborators, new TextAccess()),
            $journal,
            $collaborators,
        );
    }

    public function page(Request $request, string $record): Response
    {
        $user = $this->security->getUser() ?? throw new AccessDeniedHttpException();
        $session = $this->draft->open($user, 'example-text', $record, 'Hello world.');
        return new Response($this->twig->render('text.html.twig', [
            'session' => $session,
            'token' => $this->csrf->getToken('host-text')->getValue(),
            'endpoint' => $request->getPathInfo() . '/events',
        ]));
    }

    public function events(Request $request): JsonResponse
    {
        $user = $this->security->getUser() ?? throw new AccessDeniedHttpException();
        if (\strlen($request->getContent()) > 262_144) {
            throw new HttpException(413, 'Example request is too large.');
        }
        if (!$this->csrf->isTokenValid(new CsrfToken('host-text', $request->headers->get('X-CSRF-Token')))) {
            throw new AccessDeniedHttpException('Invalid example token.');
        }
        $data = $request->toArray();
        $grant = \is_string($data['grant'] ?? null) ? $data['grant'] : '';
        // Long validation or journal work does not hold the host's session lock.
        $request->getSession()->save();
        if ('append' === ($data['action'] ?? null) && \is_array($data['event'] ?? null)) {
            /** @var array<string, mixed> $event */
            $event = $data['event'];
            return new JsonResponse($this->draft->append($grant, $user, $event));
        }
        if ('replay' === ($data['action'] ?? null) && \is_int($data['since'] ?? null)) {
            return new JsonResponse($this->draft->replay($grant, $user, $data['since']));
        }
        throw new BadRequestHttpException('Unknown example action.');
    }
}
