<?php

declare(strict_types=1);

namespace Drupal\collaborate\Bridge;

use Drupal\Core\Entity\EntityInterface;
use Janalis\Collaborate\Draft\RecordIdentifierInterface;

/** The id of a Drupal entity: what a new record's draft names once its save created it. */
final readonly class EntityIdentifier implements RecordIdentifierInterface
{
    public function value(object $entity): mixed
    {
        return $entity instanceof EntityInterface ? $entity->id() : null;
    }
}
