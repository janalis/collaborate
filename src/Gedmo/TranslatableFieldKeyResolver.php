<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Gedmo;

use Gedmo\Mapping\Annotation\Translatable;
use Janalis\Collaborate\Draft\FieldKeyResolverInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Gedmo translatable properties have locale-specific draft keys (`<locale>:<form name>`): the request's
 * locale on an edit form, the default locale, in which records are created, on a new one.
 */
final readonly class TranslatableFieldKeyResolver implements FieldKeyResolverInterface
{
    public function __construct(
        private RequestStack $requests,
        private string $defaultLocale = 'en',
    ) {}

    public function key(string $ownerClass, string $property, string $formName, bool $new): string
    {
        return $this->translatable($ownerClass, $property) ? $this->locale($new) . ':' . $formName : $formName;
    }

    /** Gedmo restores the entity property after writing a non-default translation. */
    public function savedElsewhere(string $ownerClass, string $property, bool $new): bool
    {
        return $this->translatable($ownerClass, $property) && $this->locale($new) !== $this->defaultLocale;
    }

    /** @param class-string $ownerClass */
    private function translatable(string $ownerClass, string $property): bool
    {
        return (
            property_exists($ownerClass, $property)
            && [] !== new \ReflectionProperty($ownerClass, $property)->getAttributes(Translatable::class)
        );
    }

    private function locale(bool $new): string
    {
        return $new ? $this->defaultLocale : (string) $this->requests->getMainRequest()?->getLocale();
    }
}
