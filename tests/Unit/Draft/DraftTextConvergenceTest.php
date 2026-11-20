<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Janalis\Collaborate\Draft\DraftText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A plain-text field saved outside the draft is written into it as the operations from its draft text to the saved
 * one ({@see DraftText::diff()}), which the server then applies: whatever the two texts, accents, emoji and surrogate
 * pairs included, the draft ends on the saved text. Seeded: a failing seed replays.
 */
#[CoversClass(DraftText::class)]
final class DraftTextConvergenceTest extends TestCase
{
    /** UTF-16 units of one, of two (a surrogate pair), and combining marks. */
    private const array ALPHABET = ['a', 'b', ' ', 'é', 'ß', '中', '😀', '👍🏽', "e\u{301}", "\n"];

    /** @return iterable<string, array{int}> */
    public static function seeds(): iterable
    {
        foreach (range(1, 40) as $seed) {
            yield 'seed ' . $seed => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testTheDraftEndsOnTheSavedTextWhateverBothTexts(int $seed): void
    {
        mt_srand($seed);
        $text = new DraftText();
        $draft = self::text();
        $graveyard = 0;
        $version = 1;
        for ($save = 0; $save < 20; ++$save) {
            $saved = mt_rand(0, 3) > 0 ? self::edited($draft) : self::text();
            $operations = $text->diff($draft, $saved, $version);
            $applied = $text->apply($draft, $graveyard, $operations);
            self::assertSame($saved, $applied['text'], \sprintf('seed %d, save %d', $seed, $save));
            self::assertGreaterThanOrEqual($graveyard, $applied['graveyard']);
            $draft = $applied['text'];
            $graveyard = $applied['graveyard'];
            $version += \count($operations);
        }
    }

    private static function text(): string
    {
        $text = '';
        foreach (range(1, mt_rand(0, 12)) as $_) {
            $text .= self::ALPHABET[mt_rand(0, \count(self::ALPHABET) - 1)];
        }

        return $text;
    }

    /** The text with a few characters, never half of one, removed or inserted somewhere. */
    private static function edited(string $text): string
    {
        $characters = mb_str_split($text);
        $at = mt_rand(0, \count($characters));
        $removed = mt_rand(0, min(3, \count($characters) - $at));
        array_splice($characters, $at, $removed, mb_str_split(self::text()));

        return implode('', $characters);
    }
}
