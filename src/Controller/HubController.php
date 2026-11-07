<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Controller;

use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Hub\Admission;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Notification\NotificationAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;

/**
 * `POST <prefix>/cursors/session`: the page's one subscriber JWT.
 *
 * One subscription carries every topic of the page: its cursors, the saved views when updates are on,
 * each draft its field grant proves, and the notifications of its user when the page asks for them. A
 * bare grant carries no cursor room: the notifications alone. A browser keeps six HTTP/1.1 connections per origin, and each
 * stream holds one. A signature the server no longer knows is named in `resend`, a refused grant in `denied`.
 */
final readonly class HubController
{
    public function __construct(
        private HubGuard $guard,
        private DraftAccess $drafts,
        private Topics $topics,
        private HubInterface $hub,
        private bool $updates = false,
        private int $maxDrafts = 8,
        private int $subscriptionTtl = 300,
        private ?NotificationAccess $notifications = null,
    ) {}

    public function __invoke(Request $request): Response
    {
        // A session request proves the page's drafts with their field grants, sent in full once.
        $admission = $this->guard->admit($request, $this->drafts->maxRequestBytes);
        if (!$admission instanceof Admission) {
            return $admission;
        }
        // The session stays open until Symfony writes the security token on kernel.response.
        $topics = null === $admission->topic ? [] : [$admission->topic];
        $recipient = true === ($admission->data['notifications'] ?? null) ? $this->notifications?->recipient() : null;
        if (null !== $recipient) {
            $topics[] = $this->topics->notifications($recipient);
        }
        if ([] === $topics) {
            throw new AccessDeniedHttpException('A bare page grant subscribes to the notifications alone.');
        }
        if (true === ($admission->data['updates'] ?? null) && $this->updates && null !== $admission->topic) {
            $topics[] = $this->topics->updates();
        }
        $resend = [];
        $denied = [];
        foreach ($this->grants($admission->data['drafts'] ?? []) as $index => $grant) {
            try {
                $topics[] = $this->drafts->topic($this->drafts->verify($grant, $admission->user, time())['id']);
            } catch (PreconditionFailedHttpException) {
                $resend[] = $index;
            } catch (AccessDeniedHttpException) {
                $denied[] = $index;
            }
        }
        $factory = $this->hub->getFactory();
        \assert(null !== $factory, 'A hub configured with a JWT secret or provider has a token factory.');
        $topics = array_values(array_unique($topics));

        return new JsonResponse([
            'url' => $this->hub->getPublicUrl(),
            'topics' => $topics,
            'token' => $factory->create([new Grant([Grant::ACTION_SUBSCRIBE], $topics)], [
                'exp' => new \DateTimeImmutable()->add(new \DateInterval('PT' . $this->subscriptionTtl . 'S')),
            ]),
            'userId' => $admission->userId,
            'resend' => $resend,
            'denied' => $denied,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * A page holds a form or two: a longer list is not a page's.
     *
     * @return list<string>
     */
    private function grants(mixed $drafts): array
    {
        if (!\is_array($drafts) || !array_is_list($drafts) || \count($drafts) > $this->maxDrafts) {
            throw new BadRequestHttpException('Invalid cursor session drafts.');
        }

        return array_map(static fn(mixed $grant): string => \is_string($grant) ? $grant : '', $drafts);
    }
}
