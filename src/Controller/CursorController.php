<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Controller;

use Janalis\Collaborate\Cursor\CursorEvent;
use Janalis\Collaborate\Hub\Admission;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\Update;

/**
 * `POST <prefix>/cursors/events`: publishes a pointer, caret or presence event to the page's room, with the
 * identity the server knows in place of anything the client claims. Answers 404 when cursors are disabled.
 */
final readonly class CursorController
{
    /** @param non-empty-list<string> $colors */
    public function __construct(
        private HubGuard $guard,
        private CollaboratorProviderInterface $collaborators,
        private HubPublisher $publisher,
        private bool $enabled = true,
        private array $colors = ['blue'],
        private int $maxEventBytes = 4096,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException('Cursors are disabled.');
        }
        $admission = $this->guard->admit($request, $this->maxEventBytes);
        if (!$admission instanceof Admission) {
            return $admission;
        }
        $event = CursorEvent::fromArray($admission->data);
        $event['userId'] = $admission->userId;
        $event['label'] = $this->collaborators->label($admission->user);
        $event['color'] = $this->colors[$admission->userId % \count($this->colors)];
        $event['avatar'] = $this->collaborators->avatarUrl($admission->user);
        $event['timestamp'] = (int) (microtime(true) * 1000);
        $event['channel'] = 'cursors';
        // The client backs off on an error; a 204 would bring its next move 100 ms later.
        if (!$this->publisher->available()) {
            return new JsonResponse(['error' => 'Cursor hub unavailable.'], 503, [
                'Retry-After' => '1',
                'Cache-Control' => 'no-store',
            ]);
        }
        // The hub publish waits for kernel.terminate, once the session is released.
        $this->publisher->defer(
            new Update($admission->topic, json_encode($event, \JSON_THROW_ON_ERROR), true),
            'Cursor Mercure publication failed.',
        );

        return new Response(status: 204, headers: ['Cache-Control' => 'no-store']);
    }
}
