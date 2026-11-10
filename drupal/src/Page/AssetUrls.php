<?php

declare(strict_types=1);

namespace Drupal\collaborate\Page;

use Composer\InstalledVersions;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Url;
use Janalis\Collaborate\CollaborateBundle;

/**
 * Where a page finds the bundle's modules and the bare names they import: the bundle's `assets/` through the
 * module's asset route (Composer keeps it outside the web root), the bundle's copy of
 * `@microsoft/fetch-event-source` through the same route, and the module's shims for `ckeditor5` and Bootstrap's
 * modal.
 */
final readonly class AssetUrls
{
    public function __construct(
        private ModuleExtensionList $modules,
    ) {}

    /** @return array<string, string> the page's import map */
    public function imports(): array
    {
        $module = base_path() . $this->modules->getPath('collaborate') . '/js/';
        $bundle = Url::fromRoute('collaborate.asset', ['version' => self::version()])->toString() . '/';

        return [
            '@janalis/collaborate/' => $bundle,
            'ckeditor5' => $module . 'ckeditor5.js',
            'bootstrap/js/src/modal.js' => $module . 'modal.js',
            '@microsoft/fetch-event-source' => $bundle . 'vendor/fetch-event-source/index.js',
        ];
    }

    /** The bundle's release, or the commit it is installed at: its assets are cached until it changes. */
    public static function version(): string
    {
        $reference = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('janalis/collaborate')
            ? InstalledVersions::getReference('janalis/collaborate') ?? InstalledVersions::getVersion(
                'janalis/collaborate',
            )
            : null;

        return substr(hash('xxh128', $reference ?? (string) filemtime(self::directory())), 0, 12);
    }

    /** The bundle's `assets/` directory. */
    public static function directory(): string
    {
        return \dirname((string) new \ReflectionClass(CollaborateBundle::class)->getFileName(), 2) . '/assets';
    }
}
