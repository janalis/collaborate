<?php

declare(strict_types=1);

namespace Drupal\collaborate\Page;

use Composer\InstalledVersions;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Url;
use Janalis\Collaborate\CollaborateBundle;

/**
 * Where a page finds the bundle's modules and the bare names they import: the bundle's `assets/` through the
 * module's asset route (Composer keeps it outside the web root), the module's shims for `ckeditor5` and Bootstrap's
 * modal, and its copy of `@microsoft/fetch-event-source`.
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

        return [
            '@janalis/collaborate/' =>
                Url::fromRoute('collaborate.asset', ['version' => self::version()])->toString() . '/',
            'ckeditor5' => $module . 'ckeditor5.js',
            'bootstrap/js/src/modal.js' => $module . 'modal.js',
            '@microsoft/fetch-event-source' => $module . 'vendor/fetch-event-source/index.js',
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
