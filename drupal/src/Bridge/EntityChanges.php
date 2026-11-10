<?php

declare(strict_types=1);

namespace Drupal\collaborate\Bridge;

use Drupal\Core\Entity\EntityInterface;
use Janalis\Collaborate\Draft\DraftAccess;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What an entity save changed, in the request attributes the bundle's {@see \Janalis\Collaborate\Updates\UpdatesSubscriber}
 * announces once the response is ready: the entity type, which the listings marked with it refresh for
 * (`data-collaborate-page="node:index"`), and the draft of the entity's form, which its editors hear was saved
 * elsewhere. An entity a paragraph or a media belongs to is told of too, through the entity it references.
 */
final readonly class EntityChanges
{
    public function __construct(
        private RequestStack $requests,
        private DraftAccess $access,
        private bool $enabled = true,
    ) {}

    public function changed(EntityInterface $entity): void
    {
        $request = $this->requests->getMainRequest();
        if (!$this->enabled || null === $request) {
            return;
        }
        $type = $entity->getEntityTypeId();
        $request->attributes->set('_collaborate_data_changed', [
            ...$request->attributes->all('_collaborate_data_changed'),
            $type,
        ]);
        $id = $entity->id();
        if (null === $id || '' === (string) $id) {
            return;
        }
        $request->attributes->set('_collaborate_records_changed', [
            ...$request->attributes->all('_collaborate_records_changed'),
            $this->access->draftId($type . ':' . $entity->bundle(), (string) $id),
        ]);
    }
}
