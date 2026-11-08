<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Controller;

use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Hub\Admission;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\HubSessions;
use Janalis\Collaborate\Hub\Transport\HubTransportInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST <prefix>/cursors/session`: the page's one subscription, on whichever transport the hub runs. The topics
 * are {@see HubSessions}'s; a signature the server no longer knows is named in `resend`, a refused grant in
 * `denied`.
 */
final readonly class HubController
{
    public function __construct(
        private HubGuard $guard,
        private DraftAccess $drafts,
        private HubSessions $sessions,
        private HubTransportInterface $transport,
    ) {}

    public function __invoke(Request $request): Response
    {
        // A session request proves the page's drafts with their field grants, sent in full once.
        $admission = $this->guard->admit($request, $this->drafts->maxRequestBytes);
        if (!$admission instanceof Admission) {
            return $admission;
        }
        // The session stays open until Symfony writes the security token on kernel.response.
        $session = $this->sessions->open($admission);
        $subscription = $this->transport->subscribe($session, $request);

        return new JsonResponse([
            'transport' => $subscription->transport,
            'url' => $subscription->url,
            'topics' => $session->topics,
            'token' => $subscription->token,
            'userId' => $session->userId,
            'resend' => $session->resend,
            'denied' => $session->denied,
            'cursorEvents' => $subscription->cursorEvents,
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
