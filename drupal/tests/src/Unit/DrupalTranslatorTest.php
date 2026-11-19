<?php

declare(strict_types=1);

namespace Drupal\Tests\collaborate\Unit;

use Drupal\collaborate\DrupalTranslator;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DrupalTranslator::class)]
final class DrupalTranslatorTest extends UnitTestCase
{
    private const string SYNCING = 'Synchronizing changes…';

    public function testASentenceDrupalCannotTranslateIsReadInTheBundlesCatalogue(): void
    {
        $translator = $this->translator('fr');
        self::assertSame('Synchronisation des modifications…', $translator->trans(self::SYNCING));
        // A region without a catalogue of its own reads its language's; a language without one keeps the sentence.
        self::assertSame('Synchronisation des modifications…', $translator->trans(self::SYNCING, locale: 'fr-ca'));
        self::assertSame(self::SYNCING, $translator->trans(self::SYNCING, locale: 'de'));
        // No file is named after anything but a language code.
        self::assertSame(self::SYNCING, $translator->trans(self::SYNCING, locale: '../fr'));
        // Another domain is Drupal's alone.
        self::assertSame(self::SYNCING, $translator->trans(self::SYNCING, domain: 'other'));
    }

    public function testATranslationEnteredInDrupalWins(): void
    {
        $translator = $this->translator('fr', [self::SYNCING => 'Synchro…']);
        self::assertSame('Synchro…', $translator->trans(self::SYNCING));
    }

    public function testTheCallersParametersAreFilledAndTheScriptsPlaceholdersKept(): void
    {
        $translator = $this->translator('fr');
        self::assertSame('Toutes les modifications ont été enregistrées par Ada.', $translator->trans('All changes saved by %name%.', [
            '%name%' => 'Ada',
            '%other%' => [],
        ]));
        // Without its parameter, a placeholder is the scripts' to fill.
        self::assertSame('sur %name%', $translator->trans('on %name%'));
        self::assertSame('fr', $translator->getLocale());
    }

    /** @param array<string, string> $drupal what Drupal translates, the rest answered as given */
    private function translator(string $language, array $drupal = []): DrupalTranslator
    {
        $strings = $this->getStringTranslationStub();
        \assert($strings instanceof TranslationInterface);
        $translation = $this->createStub(TranslationInterface::class);
        $translation
            ->method('translate')
            ->willReturnCallback(
                static fn(string $text): TranslatableMarkup => new TranslatableMarkup(
                    $drupal[$text] ?? $text,
                    [],
                    [],
                    $strings,
                ),
            );
        $current = $this->createStub(LanguageInterface::class);
        $current->method('getId')->willReturn($language);
        $languages = $this->createStub(LanguageManagerInterface::class);
        $languages->method('getCurrentLanguage')->willReturn($current);

        return new DrupalTranslator($translation, $languages);
    }
}
