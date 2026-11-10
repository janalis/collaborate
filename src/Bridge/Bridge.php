<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Bridge;

/**
 * The admin a page belongs to, and what the bundle assumes of its markup: the services that tie its forms to
 * a record, the regions a pointer is placed from, and the script profile reading its rows, collections and lists.
 *
 * `none` assumes nothing but the bundle's own `data-collaborate-*` markup: the host names its form's record with a
 * {@see \Janalis\Collaborate\Form\FormContextInterface}. `drupal` is selected by the janalis/collaborate_drupal
 * module, which builds the services itself; the bundle never loads it.
 */
enum Bridge: string
{
    case None = 'none';
    case EasyAdmin = 'easyadmin';
    case Drupal = 'drupal';

    /** The value of `collaborate.bridge` that picks one of them from the registered bundles. */
    public const string AUTO = 'auto';

    /** Named, not imported: easycorp/easyadmin-bundle is optional. */
    private const string EASYADMIN_BUNDLE = 'EasyCorp\\Bundle\\EasyAdminBundle\\EasyAdminBundle';

    /**
     * The bridge `collaborate.bridge` names: `auto` is `easyadmin` when EasyAdminBundle is registered, `none`
     * otherwise.
     *
     * @param array<array-key, mixed>|null $bundles the registered bundle classes (`kernel.bundles`), null when unknown
     */
    public static function resolve(string $configured, ?array $bundles): self
    {
        $registered = null !== $bundles && \in_array(self::EASYADMIN_BUNDLE, $bundles, true);
        if (self::AUTO === $configured) {
            return $registered ? self::EasyAdmin : self::None;
        }
        $bridge = self::from($configured);
        if (self::EasyAdmin === $bridge && null !== $bundles && !$registered) {
            throw new \LogicException(
                'The easyadmin bridge of collaborate.bridge needs easycorp/easyadmin-bundle: require and register it, or set collaborate.bridge to none.',
            );
        }

        return $bridge;
    }

    /**
     * The regions of the admin's pages, name => root selector, the first one an element is in naming it: the
     * default of `collaborate.cursors.regions`. The scripts' profiles (`assets/bridges/*.js`) hold the same.
     *
     * @return non-empty-array<string, string>
     */
    public function regions(): array
    {
        return match ($this) {
            self::None => [
                'modal' => 'dialog[open], .modal.show',
                'header' => 'body > header, [role=banner]',
                'content' => 'main, [role=main]',
                'body' => 'body',
            ],
            self::EasyAdmin => [
                'modal' => '.modal.show',
                'header' => '.content-top',
                'mobile-header' => '.responsive-header',
                // `.ea-sidebar` from EasyAdmin 5.6, `.sidebar` before.
                'sidebar' => '.ea-sidebar, .sidebar',
                'content' => '.content-wrapper',
                'body' => 'body',
            ],
            // Claro and Gin, Drupal's administration themes.
            self::Drupal => [
                'modal' => '.ui-dialog',
                'header' => '#toolbar-bar, .gin-secondary-toolbar, .region-header',
                'sidebar' => '.layout-region--secondary, .gin-sidebar',
                'content' => '.region-content, .layout-region--main, main',
                'body' => 'body',
            ],
        };
    }
}
