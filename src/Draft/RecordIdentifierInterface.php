<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

/**
 * The identifier of a saved record: what a new record's draft names once its save created it, and what links to
 * its edit page. Doctrine's ({@see \Janalis\Collaborate\Doctrine\RecordIdentifier}) in an application, the
 * entity's id under Drupal.
 */
interface RecordIdentifierInterface
{
    /** The record's single identifier, or null when it has none (a composite or not yet assigned one). */
    public function value(object $entity): mixed;
}
