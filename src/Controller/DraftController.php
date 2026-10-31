<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Controller;

use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\Update;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * `POST <prefix>/collaborate/{action}` (route `collaborate_draft`): the journal requests of a shared draft.
 * Every request carries the form's field grant, which names the draft and the fields it may read and write.
 */
final readonly class DraftController
{
    public function __construct(
        private DraftAccess $access,
        private DraftJournal $journal,
        private HubPublisher $publisher,
        private Security $security,
        private CollaboratorProviderInterface $collaborators,
        private CsrfTokenManagerInterface $csrf,
        private string $role = 'ROLE_ADMIN',
        private ?RateLimiterFactoryInterface $limiter = null,
    ) {}

    public function handle(string $action, Request $request): Response
    {
        $user = $this->security->getUser();
        $userId = null === $user ? null : $this->collaborators->id($user);
        if (null === $user || null === $userId || !$this->security->isGranted($this->role)) {
            throw new AccessDeniedException('Collaborate drafts require the collaboration role.');
        }
        if (\strlen($request->getContent()) > $this->access->maxRequestBytes) {
            throw new BadRequestHttpException('Collaborate request is too large.');
        }
        /** @var array<string, mixed> $data */
        $data = $request->toArray();
        $token = $request->headers->get('X-CSRF-Token');
        if (!\is_string($token) || !$this->csrf->isTokenValid(new CsrfToken('collaborate-draft', $token))) {
            throw new AccessDeniedException('Invalid Collaborate CSRF token.');
        }
        if (null !== $this->limiter && !$this->limiter->create((string) $userId)->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Collaborate rate limit exceeded.'], 429, [
                'Retry-After' => '1',
                'Cache-Control' => 'no-store',
            ]);
        }
        $scope = $this->access->verify(\is_string($data['grant'] ?? null) ? $data['grant'] : '', $user, time());
        // The session stays open until Symfony writes the security token on
        // kernel.response. Hub publishes wait for kernel.terminate.
        $id = $scope['id'];
        $fields = $scope['fields'];
        $label = $this->collaborators->label($user);
        $event = $data;
        unset($event['grant']);
        $result = match ($action) {
            'join' => $this->journal->join($id, $fields, $scope['requestedAt'] ?? 0.0),
            'replay' => \is_string($data['stamp'] ?? null)
                && $this->journal->unchanged($id, $this->integer($data, 'since'), $data['stamp'])
                    ? ['unchanged' => true, 'stamp' => $data['stamp']]
                    : $this->journal->replay(
                        $id,
                        $fields,
                        $this->integer($data, 'since'),
                        $scope['requestedAt'] ?? 0.0,
                        \is_string($data['digest'] ?? null) ? $data['digest'] : null,
                    ),
            'append' => $this->journal->append($id, $fields, $event, $userId, $label),
            'discard' => $this->journal->discard(
                $id,
                $this->integer($data, 'generation'),
                $this->integer($data, 'revision'),
                $fields,
                $userId,
                $label,
            ),
            'reconcile' => \is_bool($data['keep'] ?? null)
                ? $this->journal->reconcile(
                    $id,
                    $this->integer($data, 'generation'),
                    $this->integer($data, 'revision'),
                    $fields,
                    $data['keep'],
                    $scope['requestedAt'] ?? 0.0,
                    $userId,
                    $label,
                )
                : throw new BadRequestHttpException('Invalid reconciliation choice.'),
            default => throw new BadRequestHttpException('Unknown Collaborate action.'),
        };
        if (\in_array($action, ['append', 'discard', 'reconcile'], true)) {
            // No draft values in notifications: every read rechecks current authorization.
            $notice = ['channel' => 'draft', 'draft' => $id, 'kind' => $action];
            // An append names its place in the journal: a page that has read it already, its author's
            // included, replays nothing for it.
            if ('append' === $action && \is_int($event['generation'] ?? null) && \is_int($result['revision'] ?? null)) {
                $notice += ['generation' => $event['generation'], 'revision' => $result['revision']];
            }
            $this->publisher->defer(
                new Update($this->access->topic($id), json_encode($notice, \JSON_THROW_ON_ERROR), true),
                'Collaborate draft Mercure publication failed.',
            );
        }

        return new JsonResponse($result, headers: ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, mixed> $data */
    private function integer(array $data, string $name): int
    {
        if (!\is_int($data[$name] ?? null) || $data[$name] < 0) {
            throw new BadRequestHttpException('Invalid Collaborate ' . $name . '.');
        }

        return $data[$name];
    }
}
