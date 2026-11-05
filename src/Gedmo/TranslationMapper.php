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
        // Gedmo declares strings: a translation not yet filled holds no class, and the foreign key column
        // holds the object's id, an int once hydrated.
        $class = self::className($entity->getObjectClass());
        if (null === $class) {
            return null;
        }

        return [$class, \strval($entity->getForeignKey())];
    }

    /** @return class-string|null */
    private static function className(mixed $class): ?string
    {
        return \is_string($class) && class_exists($class) ? $class : null;
    }
}
