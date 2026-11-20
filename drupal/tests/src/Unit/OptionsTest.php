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
        // The module's own decisions win over the advanced tree and settings.php.
        self::assertSame('use collaborate', $options['role']);
    }

    public function testTheModulesServicesWinOverTheAdvancedTreeButNotSettingsPhp(): void
    {
        $options = Options::bundle([
            'advanced' => ['notifications' => ['bus' => 'my.bus', 'locales' => 'my.locales']],
        ], ['cursors' => ['places' => 'my.places'], 'notifications' => ['role' => 'other permission']]);

        self::assertSame(Options::BUS, self::at($options, 'notifications', 'bus'));
        self::assertSame(Options::LOCALES, self::at($options, 'notifications', 'locales'));
        self::assertSame('my.places', self::at($options, 'cursors', 'places'));
        // A permission is the module's, as its pages check it.
        self::assertSame('receive collaborate notifications', self::at($options, 'notifications', 'role'));
        // settings.php names a service of its own: nothing to warn of, unlike a permission.
        self::assertSame(
            ['notifications.role', 'notifications.bus'],
            Options::ignored([
                'advanced' => ['notifications' => ['bus' => 'my.bus']],
            ], ['notifications' => ['locales' => 'my.locales', 'role' => 'other permission']]),
        );
    }

    public function testTheAdvancedTreeOverridesTheModulesDefaultsButNotTheFormsChoices(): void
    {
        $defaults = Options::bundle([], []);
        self::assertSame(Options::GRANT_CACHE, self::at($defaults, 'drafts', 'grant_cache'));
        self::assertSame('@collaborate/collaborate-notifications-layout.html.twig', self::at(
            $defaults,
            'notifications',
            'layout',
        ));
        // The bundle's change collector reads it, and the module removes the collector.
        self::assertNull(self::at($defaults, 'updates', 'path_pattern'));

        $options = Options::bundle([
            'drafts' => ['enabled' => false],
            'advanced' => [
                'drafts' => ['enabled' => true, 'grant_cache' => 'cache.other'],
                'notifications' => ['layout' => 'site.html.twig'],
            ],
        ], []);
        self::assertSame('cache.other', self::at($options, 'drafts', 'grant_cache'));
        self::assertSame('site.html.twig', self::at($options, 'notifications', 'layout'));
        self::assertFalse(self::at($options, 'drafts', 'enabled'));
    }

    public function testAListOfAHigherLayerReplacesTheOneBelowWhole(): void
    {
        $options = Options::bundle([
            'colors' => ['red', 'green'],
            'advanced' => ['colors' => ['blue', 'indigo', 'purple', 'pink']],
        ], ['hub' => ['transport' => 'websocket']]);

        self::assertSame(['red', 'green'], self::at($options, 'colors'));
        self::assertSame('websocket', self::at($options, 'hub', 'transport'));
    }

    public function testAnEmptyArrayEmptiesAListAndLeavesAMapAsItWas(): void
    {
        $options = Options::bundle(['advanced' => ['colors' => ['blue', 'indigo'], 'drafts' => []]], [
            'colors' => [],
            'notifications' => [],
        ]);

        self::assertSame([], self::at($options, 'colors'));
        self::assertSame(Options::GRANT_CACHE, self::at($options, 'drafts', 'grant_cache'));
        self::assertSame(Options::BUS, self::at($options, 'notifications', 'bus'));
    }

    public function testTheMapsTheBundleReplacesWholeAreReplacedWhole(): void
    {
        $comment = ['where' => 'commenting', 'detail' => 'on %name%'];
        $options = Options::bundle([
            'cursors' => ['regions' => ['main' => 'main']],
            'advanced' => [
                'cursors' => ['regions' => ['legacy' => '.old'], 'popups' => ['comment' => $comment]],
                'notifications' => ['topics' => ['shares' => ['label' => 'Shares']]],
            ],
        ], ['cursors' => ['popups' => []], 'notifications' => ['topics' => ['news' => ['label' => 'News']]]]);

        // The module's regions, the form's here, whatever the layers below say.
        self::assertSame(['main' => 'main'], self::at($options, 'cursors', 'regions'));
        self::assertSame([], self::at($options, 'cursors', 'popups'));
        self::assertSame(['news' => ['label' => 'News']], self::at($options, 'notifications', 'topics'));
    }

    public function testTheOptionsTheModuleReadsNothingOfAreNamed(): void
    {
        self::assertSame([], Options::ignored(['drafts' => ['position_field' => 'rank']], ['hub' => []]));
        self::assertSame(
            [
                'updates.path_pattern',
                'drafts.rich_text.ckeditor_version',
                'role',
                'cursors.regions',
                'notifications.bus',
            ],
            Options::ignored([
                'advanced' => [
                    'updates' => ['path_pattern' => '#^/#'],
                    'drafts' => ['rich_text' => 'none'],
                    'role' => 'ROLE_EDITOR',
                    'notifications' => ['bus' => 'my.bus'],
                ],
            ], [
                'drafts' => ['rich_text' => ['ckeditor_version' => '47.0.0']],
                'cursors' => ['regions' => ['legacy' => '.old']],
            ]),
        );
    }

    public function testASentencesPlaceholdersAreNoContainerParameters(): void
    {
        $options = Options::bundle([
            'advanced' => ['notifications' => ['types' => ['saved' => ['sentence' => '%name% saved']]]],
        ], []);

        self::assertSame('%%name%% saved', self::at($options, 'notifications', 'types', 'saved', 'sentence'));
    }

    /** @param array<array-key, mixed> $options */
    private static function at(array $options, string ...$keys): mixed
    {
        $value = $options;
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            // @mago-expect analysis:mixed-assignment an option's value: the next key narrows it
            $value = $value[$key];
        }

        return $value;
    }
}
