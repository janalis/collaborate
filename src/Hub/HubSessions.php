<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub;

use Janalis\Collaborate\Cursor\CursorIdentity;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Notification\NotificationAccess;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The topics one page subscription carries, whatever the transport: its cursors, the saved views when updates
 * are on, each draft its field grant proves, and the notifications of its user when the page asks for them. A
 * bare grant carries no cursor room: the notifications alone. A browser keeps six HTTP/1.1 connections per
 * origin, and each stream holds one.
 */
final readonly class HubSessions
{
    /** @param non-empty-list<string> $colors */
    public function __construct(
        private DraftAccess $drafts,
        private Topics $topics,
        private bool $updates = false,
        private int $maxDrafts = 8,
        private ?NotificationAccess $notifications = null,
        private ?CollaboratorProviderInterface $collaborators = null,
        private bool $cursors = false,
        private array $colors = ['blue'],
    ) {}

    public function open(Admission $admission): HubSession
    {
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

        $cursor =
            null === $admission->topic || !$this->cursors || null === $this->collaborators
                ? null
                : new CursorIdentity(
                    $admission->userId,
                    $this->collaborators->label($admission->user),
                    $this->colors[$admission->userId % \count($this->colors)],
                    $this->collaborators->avatarUrl($admission->user),
                );

        return new HubSession(
            $admission->userId,
            array_values(array_unique($topics)),
            $resend,
            $denied,
            null === $cursor ? null : $admission->topic,
            $cursor,
        );
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
