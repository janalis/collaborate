<?php

declare(strict_types=1);

namespace Drupal\collaborate\DependencyInjection;

use Drupal\Core\Config\BootstrapConfigStorageFactory;
use Drupal\Core\Site\Settings;
use Janalis\Collaborate\Bridge\Bridge;

/**
 * The bundle's `collaborate` options under Drupal, read when the container is built: the module's configuration
 * (`collaborate.settings`, what the administration form edits, and its `advanced` tree), then settings.php's
 * `$settings['collaborate']` (the hub, its secrets), then what the module itself decides (the permissions as the
 * roles, its own services). The bundle's configuration tree validates the result.
 */
final class Options
{
    /** The module's services the bundle's options name. */
    public const string IDENTITY = 'collaborate.identity';
    public const string ACCESS = 'collaborate.access';
    public const string BUS = 'collaborate.message_bus';
    public const string LOCALES = 'collaborate.locales';
    public const string PLACES = 'collaborate.places';
    public const string GRANT_CACHE = 'collaborate.grant_cache';

    /**
     * The options the bundle's extension loads.
     *
     * @param array<string, mixed>|null $config   `collaborate.settings`, read from the active storage when null
     * @param array<string, mixed>|null $settings `$settings['collaborate']`, read from settings.php when null
     *
     * @return array<string, mixed>
     */
    public static function bundle(?array $config = null, ?array $settings = null): array
    {
        $config ??= self::stored();
        $settings ??= self::settings();
        $advanced = \is_array($config['advanced'] ?? null) ? $config['advanced'] : [];
        $cursors = \is_array($config['cursors'] ?? null) ? $config['cursors'] : [];
        $drafts = \is_array($config['drafts'] ?? null) ? $config['drafts'] : [];
        $updates = \is_array($config['updates'] ?? null) ? $config['updates'] : [];
        $notifications = \is_array($config['notifications'] ?? null) ? $config['notifications'] : [];
        $regions = \is_array($cursors['regions'] ?? null) && [] !== $cursors['regions']
            ? $cursors['regions']
            : Bridge::Drupal->regions();

        $options = array_replace_recursive($advanced, array_filter(
            [
                'colors' => \is_array($config['colors'] ?? null) && [] !== $config['colors']
                    ? array_values($config['colors'])
                    : null,
                'cursors' => [
                    'enabled' => (bool) ($cursors['enabled'] ?? true),
                    'presence' => $cursors['presence'] ?? 'avatars',
                    'max_avatars' => (int) ($cursors['max_avatars'] ?? 5),
                    'idle' => (int) ($cursors['idle'] ?? 0),
                    'markers' => (bool) ($cursors['markers'] ?? false),
                    'places' => self::PLACES,
                ],
                'drafts' => [
                    'enabled' => (bool) ($drafts['enabled'] ?? true),
                    'grant_cache' => self::GRANT_CACHE,
                    'rich_text' => ['block_prefixes' => $drafts['rich_text'] ?? true ? ['text_format'] : []],
                ],
                'updates' => [
                    'enabled' => (bool) ($updates['enabled'] ?? true),
                    // Drupal's administration, and the entity forms its themes show to editors.
                    'path_pattern' => '#^/(?:admin|node|user|taxonomy|media|block)(?:/|$)#',
                ],
                'notifications' => [
                    'enabled' => (bool) ($notifications['enabled'] ?? false),
                    'role' => 'receive collaborate notifications',
                    'bus' => self::BUS,
                    'locales' => self::LOCALES,
                    // Their pages' body, which the module places in the administration theme's page.
                    'layout' => '@collaborate/collaborate-notifications-layout.html.twig',
                    'web_push' => ['enabled' => (bool) ($notifications['web_push'] ?? false)],
                ],
            ],
            static fn(mixed $value): bool => null !== $value,
        ));
        // settings.php: the hub and its secrets, and anything a site keeps out of its exported configuration.
        $options = array_replace_recursive($options, $settings);

        // The module's own decisions, whatever the configuration says.
        return self::escape(array_replace_recursive($options, [
            'bridge' => Bridge::None->value,
            'identity' => self::IDENTITY,
            'access' => self::ACCESS,
            'role' => 'use collaborate',
            'cursors' => ['regions' => $regions],
        ]));
    }

    /**
     * The options with every `%` doubled: the container reads `%name%` as a parameter, and a sentence's
     * placeholders (`%name%`, `%title%`) are the bundle's, as `%%name%%` writes them in a Symfony configuration.
     *
     * @param array<array-key, mixed> $options
     *
     * @return array<array-key, mixed>
     */
    private static function escape(array $options): array
    {
        foreach ($options as $key => $value) {
            $options[$key] = match (true) {
                \is_string($value) => str_replace('%', '%%', $value),
                \is_array($value) => self::escape($value),
                default => $value,
            };
        }

        return $options;
    }

    /** The secret grants are signed with: derived from the site's hash salt, never stored as is. */
    public static function secret(): string
    {
        return hash_hmac('sha256', 'janalis/collaborate', Settings::getHashSalt());
    }

    /** The site's default language, the one a notification is written in for a user who chose none. */
    public static function language(): string
    {
        $site = BootstrapConfigStorageFactory::get()->read('system.site');
        $language = \is_array($site) ? $site['default_langcode'] ?? null : null;

        return \is_string($language) && '' !== $language ? $language : 'en';
    }

    /** @return array<string, mixed> */
    private static function stored(): array
    {
        $config = BootstrapConfigStorageFactory::get()->read('collaborate.settings');

        return \is_array($config) ? $config : [];
    }

    /** @return array<string, mixed> */
    private static function settings(): array
    {
        $settings = Settings::get('collaborate', []);

        return \is_array($settings) ? $settings : [];
    }
}
