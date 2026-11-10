<?php

declare(strict_types=1);

namespace Drupal\Tests\collaborate\Unit;

use Drupal\collaborate\DependencyInjection\Options;
use Drupal\Tests\UnitTestCase;
use Janalis\Collaborate\Bridge\Bridge;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Options::class)]
final class OptionsTest extends UnitTestCase
{
    public function testTheModuleDecidesWhoCollaboratesAndWithWhichServices(): void
    {
        $options = Options::bundle([], []);

        self::assertSame('none', $options['bridge']);
        self::assertSame('use collaborate', $options['role']);
        self::assertSame(Options::IDENTITY, $options['identity']);
        self::assertSame(Options::ACCESS, $options['access']);
        self::assertSame(Bridge::Drupal->regions(), $options['cursors']['regions']);
        self::assertSame(Options::PLACES, $options['cursors']['places']);
        self::assertSame(['text_format'], $options['drafts']['rich_text']['block_prefixes']);
        self::assertSame('receive collaborate notifications', $options['notifications']['role']);
        self::assertSame(Options::BUS, $options['notifications']['bus']);
        self::assertFalse($options['notifications']['enabled']);
    }

    public function testTheAdministrationFormsChoicesAndSettingsPhpComeThrough(): void
    {
        $options = Options::bundle([
            'colors' => ['red', 'green'],
            'cursors' => [
                'enabled' => false,
                'presence' => 'menu',
                'max_avatars' => 3,
                'regions' => ['main' => 'main'],
            ],
            'drafts' => ['enabled' => true, 'rich_text' => false],
            'notifications' => ['enabled' => true, 'web_push' => true],
            'advanced' => ['drafts' => ['max_fields' => 50], 'role' => 'ignored'],
        ], ['hub' => ['transport' => 'websocket', 'websocket' => ['secret' => 'x']]]);

        self::assertSame(['red', 'green'], $options['colors']);
        self::assertFalse($options['cursors']['enabled']);
        self::assertSame('menu', $options['cursors']['presence']);
        self::assertSame(3, $options['cursors']['max_avatars']);
        self::assertSame(['main' => 'main'], $options['cursors']['regions']);
        self::assertSame([], $options['drafts']['rich_text']['block_prefixes']);
        self::assertSame(50, $options['drafts']['max_fields']);
        self::assertTrue($options['notifications']['web_push']['enabled']);
        self::assertSame('websocket', $options['hub']['transport']);
        // The module's own decisions win over the advanced tree.
        self::assertSame('use collaborate', $options['role']);
    }

    public function testASentencesPlaceholdersAreNoContainerParameters(): void
    {
        $options = Options::bundle([
            'advanced' => ['notifications' => ['types' => ['saved' => ['sentence' => '%name% saved']]]],
        ], []);

        self::assertSame('%%name%% saved', $options['notifications']['types']['saved']['sentence']);
    }
}
