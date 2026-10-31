<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

/**
 * Names the draft state of a mapped form field. A field whose value belongs to one locale (a translated
 * property) takes a key of its own per locale, so the pages of two locales share the record's draft
 * without sharing that field.
 */
interface FieldKeyResolverInterface
{
    /**
     * @param class-string $ownerClass the class holding the property
     * @param string       $formName   the field's full form name, e.g. `article[title]`
     * @param bool         $new        whether the form creates its record
     */
    public function key(string $ownerClass, string $property, string $formName, bool $new): string;

    /**
     * Whether a saved record no longer holds the value submitted for that field, which went to another
     * locale's storage: the draft then checkpoints the submitted value.
     *
     * @param class-string $ownerClass
     */
    public function savedElsewhere(string $ownerClass, string $property, bool $new): bool;
}
