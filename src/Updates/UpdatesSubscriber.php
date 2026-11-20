<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Updates;

use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\Notice;
use Janalis\Collaborate\Hub\Topics;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Announces a successful admin write, as the Doctrine change collector recorded it, once its response
 * is ready: the saved views it can alter, by the names the bridge gave them, the draft its own form saved, and the
 * drafts of the other records it changed ({@see SavedChanges}). Notices name no content.
 */
final readonly class UpdatesSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DraftAccess $access,
        private Topics $topics,
        private HubPublisher $publisher,
        /** Saved-list and saved-detail refresh (`collaborate.updates.enabled`). */
        private bool $enabled = true,
        /** Draft topics one notice names at most. */
        private int $chunk = 100,
        /** Shared drafts (`collaborate.drafts.enabled`): their pages hear of saves of their records. */
        private bool $drafts = true,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['publish', -2050]];
    }

    public function publish(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getResponse()->getStatusCode() >= 400) {
            return;
        }
        $request = $event->getRequest();
        $updates = [];
        if ($this->enabled && SavedChanges::hasData($request)) {
            // A host that records its changes in the request attribute itself, as before SavedChanges, names
            // classes: their short name is EasyAdmin's entity name, which the pages carry.
            $entities = array_unique(array_map(static fn(string $name): string => basename(str_replace(
                '\\',
                '/',
                $name,
            )), SavedChanges::dataNames($request)));
            sort($entities);
            $updates[] = Notice::updates($this->topics->updates(), $entities);
        }
        if ($this->drafts) {
            $updates = [...$updates, ...$this->drafts($request)];
        }
        foreach ($updates as $update) {
            $this->publisher->defer($update, 'Collaborate updates hub publication failed.');
        }
    }

    /** @return list<Notice> */
    private function drafts(Request $request): array
    {
        $updates = [];
        $draft = SavedChanges::committedDraft($request);
        if (null !== $draft) {
            $updates[] = Notice::draft($this->access->topic($draft), $draft, 'saved');
        }
        // Edit pages of the other changed records compare their form with the saved record again;
        // the draft saved through its own form has just recorded it.
        $records = array_diff(SavedChanges::recordDrafts($request), null !== $draft ? [$draft] : []);
        // array_chunk() lists each chunk again.
        foreach (array_chunk($records, max(1, $this->chunk)) as $chunk) {
            $updates[] = Notice::records(array_map($this->access->topic(...), $chunk));
        }

        return $updates;
    }
}
