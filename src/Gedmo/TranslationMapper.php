<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Gedmo;

use Gedmo\Translatable\Entity\MappedSuperclass\AbstractTranslation;
use Janalis\Collaborate\Doctrine\TranslationMapperInterface;

/** A Gedmo translation row names its object by class and foreign key. */
final class TranslationMapper implements TranslationMapperInterface
{
    public function translated(object $entity): ?array
    {
        if (!$entity instanceof AbstractTranslation) {
            return null;
        }
        $class = $entity->getObjectClass();
        if (!\is_string($class) || !class_exists($class)) {
            return null;
        }

        // Gedmo declares a string; the column holds the object's id, an int once hydrated.
        return [$class, (string) $entity->getForeignKey()];
    }
}
