<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Form\CollaborateFormExtension;
use Janalis\Collaborate\Twig\CollaborateExtension;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Every string the shared drafts show is translated, in each catalogue of the `collaborate` domain. */
#[CoversNothing]
final class DraftTranslationsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'English' => ['en'];
        yield 'French' => ['fr'];
    }

    #[DataProvider('locales')]
    public function testTheCatalogueTranslatesEveryDraftString(string $locale): void
    {
        $catalogue = self::array(Yaml::parseFile(
            \dirname(__DIR__, 3) . '/translations/collaborate.' . $locale . '.yaml',
        ));
        $keys = [
            ...array_values(CollaborateFormExtension::MESSAGES),
            DraftSave::CHANGED,
            DraftSave::BUSY,
            DraftSave::SYNC,
            DraftJournal::ALREADY_CREATED,
            // The presence component's label, and what the cursor scripts say.
            'Also on this page',
            ...array_values(CollaborateExtension::MESSAGES),
        ];

        foreach ($keys as $key) {
            self::assertIsString($catalogue[$key] ?? null, $key);
            self::assertNotSame('', $catalogue[$key]);
        }
    }

    /** @return array<array-key, mixed> */
    private static function array(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
