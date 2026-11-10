<?php

declare(strict_types=1);

namespace Drupal\collaborate;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The bundle's sentences (domain `collaborate`, keyed by their English text), translated by Drupal in the
 * `collaborate` context. Drupal reads no `%name%` without an argument of that name: the scripts' placeholders pass
 * through, and the parameters a caller gives are filled here.
 */
final readonly class DrupalTranslator implements TranslatorInterface
{
    public function __construct(
        private TranslationInterface $translation,
        private LanguageManagerInterface $languages,
    ) {}

    /** @param array<array-key, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $options = ['context' => $domain ?? 'collaborate'] + (null === $locale ? [] : ['langcode' => $locale]);
        $translated = (string) $this->translation->translate($id, [], $options);
        $replacements = [];
        foreach ($parameters as $name => $value) {
            $replacements[(string) $name] = \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
        }

        return strtr($translated, $replacements);
    }

    public function getLocale(): string
    {
        return $this->languages->getCurrentLanguage()->getId();
    }
}
