<?php

declare(strict_types=1);

namespace Drupal\collaborate;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Janalis\Collaborate\CollaborateBundle;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The bundle's sentences (domain `collaborate`, keyed by their English text), translated by Drupal in the
 * `collaborate` context. Drupal reads no `%name%` without an argument of that name: the scripts' placeholders pass
 * through, and the parameters a caller gives are filled here.
 *
 * A sentence Drupal has no translation of (it answers the sentence itself) is read in the bundle's own catalogue of
 * the language (`translations/collaborate.<language>.yaml`), the language alone if the region has none: a site
 * translates them without importing anything, and a translation entered in Drupal still wins.
 */
final class DrupalTranslator implements TranslatorInterface
{
    /** @var array<string, array<string, string>> the bundle's catalogues read, by language */
    private array $catalogues = [];

    public function __construct(
        private readonly TranslationInterface $translation,
        private readonly LanguageManagerInterface $languages,
        private readonly ?string $directory = null,
    ) {}

    /** @param array<array-key, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $context = $domain ?? 'collaborate';
        $options = ['context' => $context] + (null === $locale ? [] : ['langcode' => $locale]);
        $translated = (string) $this->translation->translate($id, [], $options);
        if ($translated === $id && 'collaborate' === $context) {
            $translated = $this->catalogue($locale ?? $this->getLocale())[$id] ?? $id;
        }
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

    /**
     * The bundle's sentences in a language, its catalogues named as Symfony names locales: `pt-br` (Drupal's) or
     * `pt_BR` reads `collaborate.pt_BR.yaml`, else `collaborate.pt.yaml`; none for a language without either.
     *
     * @return array<string, string>
     */
    private function catalogue(string $langcode): array
    {
        if (!isset($this->catalogues[$langcode])) {
            $catalogue = [];
            // A language code names a file: nothing but its letters, digits and dashes.
            if (1 === preg_match('/^[a-z]{2,3}(?:[-_][a-z0-9]+)*$/iD', $langcode)) {
                $directory =
                    $this->directory
                    ?? \dirname((string) new \ReflectionClass(CollaborateBundle::class)->getFileName(), 2)
                        . '/translations';
                $parts = explode('-', str_replace('_', '-', $langcode));
                $language = strtolower($parts[0]);
                $region = $parts[1] ?? null;
                $names = null === $region ? [$language] : [$language . '_' . strtoupper($region), $language];
                foreach ($names as $name) {
                    $file = $directory . '/collaborate.' . $name . '.yaml';
                    if (is_file($file)) {
                        /** @var array<string, string> $catalogue the bundle's catalogues map sentences to sentences */
                        $catalogue = Yaml::parseFile($file);
                        break;
                    }
                }
            }
            $this->catalogues[$langcode] = $catalogue;
        }

        return $this->catalogues[$langcode];
    }
}
