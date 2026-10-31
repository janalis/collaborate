<?php

declare(strict_types=1);

namespace Janalis\Collaborate\EasyAdmin;

use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\Topics;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Update;

/**
 * Announces a successful admin write, as the Doctrine change collector recorded it, once its response
 * is ready: the saved views it can alter, by EasyAdmin entity name (`EntityDto::getName()`), the draft its own
 * form saved, and the drafts of the other records it changed. Notices name no content.
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
        if ($this->enabled && $request->attributes->has('_collaborate_data_changed')) {
            // EntityDto::getName(): the short class name.
            $entities = array_values(array_unique(array_map(static fn(string $class): string => basename(str_replace(
                '\\',
                '/',
                $class,
            )), $this->strings($request->attributes->all('_collaborate_data_changed')))));
            sort($entities);
            $updates[] = new Update(
                $this->topics->updates(),
                json_encode(['channel' => 'updates', 'kind' => 'saved', 'entities' => $entities], \JSON_THROW_ON_ERROR),
                true,
            );
        }
        if ($this->drafts) {
            $updates = [...$updates, ...$this->drafts($request)];
        }
        foreach ($updates as $update) {
            $this->publisher->defer($update, 'Collaborate updates Mercure publication failed.');
        }
    }

    /** @return list<Update> */
    private function drafts(Request $request): array
    {
        $updates = [];
        $draft = $request->attributes->get('_collaborate_committed');
        if (\is_string($draft)) {
            $updates[] = new Update(
                $this->access->topic($draft),
                json_encode(['channel' => 'draft', 'draft' => $draft, 'kind' => 'saved'], \JSON_THROW_ON_ERROR),
                true,
            );
        }
        // Edit pages of the other changed records compare their form with the saved record again;
        // the draft saved through its own form has just recorded it.
        $records = array_values(array_diff(
            array_unique($this->strings($request->attributes->all('_collaborate_records_changed'))),
            \is_string($draft) ? [$draft] : [],
        ));
        foreach (array_chunk($records, max(1, $this->chunk)) as $chunk) {
            // One notice names several drafts: it carries none, and every draft session of the page compares again.
            $updates[] = new Update(
                array_map($this->access->topic(...), $chunk),
                '{"channel":"draft","kind":"record"}',
                true,
            );
        }

        return $updates;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    private function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (\is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }
}
