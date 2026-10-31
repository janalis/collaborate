<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub;

use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The checks every hub request passes: the role, a bounded JSON body, a remembered session, the CSRF
 * token, the page grant of the signed-in user and the rate limit.
 *
 * @internal
 */
final readonly class HubGuard
{
    public const string CSRF_TOKEN_ID = 'collaborate-hub';

    public function __construct(
        private Security $security,
        private CollaboratorProviderInterface $collaborators,
        private PageGrant $pages,
        private CsrfTokenManagerInterface $csrf,
        private string $role = 'ROLE_ADMIN',
        private ?RateLimiterFactoryInterface $limiter = null,
    ) {}

    /** @return Admission|JsonResponse the admitted request, or the 429 answer of a user over the rate limit */
    public function admit(Request $request, int $maxBytes): Admission|JsonResponse
    {
        $user = $this->security->getUser();
        $userId = $user instanceof UserInterface ? $this->collaborators->id($user) : null;
        if (!$user instanceof UserInterface || null === $userId || !$this->security->isGranted($this->role)) {
            throw new AccessDeniedException('Collaboration requires a signed-in collaborator.');
        }
        if (\strlen($request->getContent()) > $maxBytes) {
            throw new BadRequestHttpException('Cursor event is too large.');
        }
        $data = $request->toArray();
        if (!$this->security->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            throw new AccessDeniedException('Collaboration requires an authenticated session.');
        }
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->headers->get('X-CSRF-Token')))) {
            throw new AccessDeniedException('Invalid cursor CSRF token.');
        }
        $topic = $this->pages->topic(
            \is_string($data['grant'] ?? null) ? $data['grant'] : '',
            $userId,
            time(),
            $this->collaborators->authorization($user),
        );
        if (null !== $this->limiter && !$this->limiter->create((string) $userId)->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Cursor rate limit exceeded.'], 429, [
                'Retry-After' => '1',
                'Cache-Control' => 'no-store',
            ]);
        }

        return new Admission($user, $userId, $topic, $data);
    }
}
