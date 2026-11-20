<?php

declare(strict_types=1);

namespace Drupal\collaborate\DependencyInjection;

use Drupal\Core\Config\BootstrapConfigStorageFactory;
use Drupal\Core\Site\Settings;
use Janalis\Collaborate\Bridge\Bridge;

/**
 * The bundle's `collaborate` options under Drupal, read when the container is built, each layer over the one before:
 * the module's defaults, the `advanced` tree of its configuration (`collaborate.settings`), what the administration
 * form edits with the module's services, settings.php's `$settings['collaborate']` (the hub, its secrets, a service
 * of the site's own), then what the module itself decides (its bridge and identity, the permissions as the roles).
 * The bundle's configuration tree validates the result.
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
     * Options a site sets for nothing: those of the bundle's form extension and Doctrine change collector, which the
     * module replaces with its own entity forms and hooks. Accepted by the configuration tree, read by nothing; the
     * options the module decides ({@see DECIDED}, {@see SERVICES}, `cursors.regions`) are named with them.
     */
    public const array IGNORED = [
        'updates.path_pattern',
        'updates.routes',
        'updates.association_depth',
        'updates.ignored_fields',
        'drafts.position_field',
        'drafts.rich_text.schema_option',
        'drafts.rich_text.ckeditor_version',
    ];

    /**
     * What the module decides itself, over every layer: its bridge, the users as Drupal's, its permissions as the
     * roles, which its pages check too. The regions of Drupal's pages, or the form's, are decided with them.
     */
    private const array DECIDED = [
        'bridge' => Bridge::None->value,
        'identity' => self::IDENTITY,
        'access' => self::ACCESS,
        'role' => 'use collaborate',
        'notifications' => ['role' => 'receive collaborate notifications'],
    ];

    /**
     * The module's services: over the `advanced` tree, under settings.php, where a site may name its own (a bus of
     * its own, say).
     */
    private const array SERVICES = [
        'cursors' => ['places' => self::PLACES],
        'notifications' => [
            'bus' => self::BUS,
            'locales' => self::LOCALES,
        ],
    ];

    /**
     * The maps the bundle's configuration tree replaces whole from one file to the next (`performNoDeepMerging()`):
     * a higher layer's replaces the one below, an empty one included.
     */
    private const array WHOLE = ['cursors.regions', 'cursors.popups', 'notifications.topics', 'notifications.types'];

    /** What the module chooses unless the `advanced` tree says otherwise. */
    private const array DEFAULTS = [
        'drafts' => ['grant_cache' => self::GRANT_CACHE],
        // Their pages' body, which the module places in the administration theme's page.
        'notifications' => ['layout' => '@collaborate/collaborate-notifications-layout.html.twig'],
    ];

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

        $options = self::merge(
            self::DEFAULTS,
            $advanced,
            array_filter(
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
                    ],
                    'drafts' => [
                        'enabled' => (bool) ($drafts['enabled'] ?? true),
                        'rich_text' => ['block_prefixes' => $drafts['rich_text'] ?? true ? ['text_format'] : []],
                    ],
                    'updates' => ['enabled' => (bool) ($updates['enabled'] ?? true)],
                    'notifications' => [
                        'enabled' => (bool) ($notifications['enabled'] ?? false),
                        'web_push' => ['enabled' => (bool) ($notifications['web_push'] ?? false)],
                    ],
                ],
                static fn(mixed $value): bool => null !== $value,
            ),
            self::SERVICES,
        );
        // settings.php: the hub and its secrets, and anything a site keeps out of its exported configuration.
        $options = self::merge($options, $settings);

        // The module's own decisions, whatever the configuration says.
        return self::escape(self::merge($options, self::DECIDED, ['cursors' => ['regions' => $regions]]));
    }

    /**
     * Each layer over the ones before: a map is merged key by key, anything else, a list or a {@see WHOLE} map
     * included, replaced whole. array_replace_recursive() would merge lists index by index, and a lower layer's
     * longer list would show through a shorter one.
     *
     * @param array<array-key, mixed> ...$layers
     *
     * @return array<array-key, mixed>
     */
    private static function merge(array ...$layers): array
    {
        return self::mergeAt('', ...$layers);
    }

    /**
     * @param string                  $path      the dotted path of the layers' maps, empty at the root
     * @param array<array-key, mixed> ...$layers
     *
     * @return array<array-key, mixed>
     */
    private static function mergeAt(string $path, array ...$layers): array
    {
        $merged = [];
        foreach ($layers as $layer) {
            // @mago-expect analysis:mixed-assignment an option's value: the next lines narrow it
            foreach ($layer as $key => $value) {
                $at = '' === $path ? (string) $key : $path . '.' . $key;
                // @mago-expect analysis:mixed-assignment the layer below's value: the next line narrows it
                $below = $merged[$key] ?? null;
                // An empty array changes nothing of a map below it, and empties a list.
                $merged[$key] = \is_array($value)
                && \is_array($below)
                && !self::isList($value)
                && !self::isList($below)
                && !\in_array($at, self::WHOLE, true)
                    ? self::mergeAt($at, $below, $value)
                    : $value;
            }
        }

        return $merged;
    }

    /** @param array<array-key, mixed> $array */
    private static function isList(array $array): bool
    {
        return [] !== $array && array_is_list($array);
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

    /**
     * The {@see IGNORED} and decided options the site sets, in its `advanced` tree or settings.php: settings.php may
     * name the module's {@see SERVICES}.
     *
     * @param array<string, mixed>|null $config   `collaborate.settings`, read from the active storage when null
     * @param array<string, mixed>|null $settings `$settings['collaborate']`, read from settings.php when null
     *
     * @return list<string>
     */
    public static function ignored(?array $config = null, ?array $settings = null): array
    {
        $config ??= self::stored();
        $advanced = \is_array($config['advanced'] ?? null) ? $config['advanced'] : [];
        $settings ??= self::settings();
        $decided = [...self::IGNORED, ...self::paths(self::DECIDED), 'cursors.regions'];

        return [
            ...array_filter(
                $decided,
                static fn(string $path): bool => self::sets($advanced, $path) || self::sets($settings, $path),
            ),
            ...array_filter(self::paths(self::SERVICES), static fn(string $path): bool => self::sets($advanced, $path)),
        ];
    }

    /**
     * Whether a layer sets the option at a dotted path.
     *
     * @param array<array-key, mixed> $layer
     */
    private static function sets(array $layer, string $path): bool
    {
        $value = $layer;
        foreach (explode('.', $path) as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return false;
            }
            // @mago-expect analysis:mixed-assignment an option's value: the next key narrows it
            $value = $value[$key];
        }

        return true;
    }

    /**
     * The dotted paths of a map's leaves.
     *
     * @param array<array-key, mixed> $options
     *
     * @return list<string>
     */
    private static function paths(array $options, string $prefix = ''): array
    {
        $paths = [];
        // @mago-expect analysis:mixed-assignment an option's value: a map is walked, anything else is a leaf
        foreach ($options as $key => $value) {
            $paths[] = \is_array($value) ? self::paths($value, $prefix . $key . '.') : [$prefix . $key];
        }

        return array_values(array_merge(...$paths));
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
