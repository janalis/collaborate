<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Doctrine;

/** Maps a translation row to the object it translates: a change to it changes how that object reads. */
interface TranslationMapperInterface
{
    /** @return array{class-string, string}|null the translated object's class and identifier, or null when `$entity` translates nothing */
    public function translated(object $entity): ?array;
}
