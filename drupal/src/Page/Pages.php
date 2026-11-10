<?php

declare(strict_types=1);

namespace Drupal\collaborate\Page;

use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Janalis\Collaborate\Security\CurrentUserInterface;
use Janalis\Collaborate\Twig\CollaborateExtension;
use Twig\Environment;

/**
 * What a collaborator's page carries: the module's script and the import map its modules resolve through, the
 * bundle's layer at the bottom, and what the page shows on its body (`data-collaborate-page`), which the saved
 * entities its listings refresh for are compared with.
 */
final readonly class Pages implements TrustedCallbackInterface
{
    /** Route => the page kind it is, for the routes whose name does not say it. */
    private const array KINDS = [
        'system.admin_content' => 'node:index',
        'entity.taxonomy_vocabulary.overview_form' => 'taxonomy_term:index',
        'entity.user.collection' => 'user:index',
        'entity.media.collection' => 'media:index',
        'entity.block_content.collection' => 'block_content:index',
    ];

    public function __construct(
        private CurrentUserInterface $users,
        private CollaborateExtension $layer,
        private Environment $twig,
        private RouteMatchInterface $route,
        private AssetUrls $assets,
        private string $role = 'use collaborate',
        private bool $notifications = false,
    ) {}

    /** @param array<array-key, mixed> $attachments */
    public function attach(array &$attachments): void
    {
        $attachments['#cache']['contexts'][] = 'user.permissions';
        if (!$this->collaborates()) {
            return;
        }
        $attachments['#attached']['library'][] = 'collaborate/drupal';
        $attachments['#attached']['html_head'][] = [
            [
                '#type' => 'html_tag',
                '#tag' => 'script',
                '#attributes' => ['type' => 'importmap'],
                '#value' => json_encode(
                    ['imports' => $this->assets->imports()],
                    \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
                ),
                // Before any module script, as browsers require.
                '#weight' => -1000,
            ],
            'collaborate_importmap',
        ];
    }

    /** @param array<array-key, mixed> $page */
    public function layer(array &$page): void
    {
        $page['#cache']['contexts'][] = 'user.permissions';
        if (!$this->collaborates()) {
            return;
        }
        // The grant is the page's and its user's, signed at render time: never cached.
        $page['collaborate_layer'] = [
            '#lazy_builder' => [self::class . ':render', []],
            '#create_placeholder' => true,
        ];
    }

    /** @return array<array-key, mixed> the layer, rendered by the bundle's Twig extension */
    public function render(): array
    {
        return ['#markup' => Markup::create($this->layer->layer($this->twig)), '#cache' => ['max-age' => 0]];
    }

    public static function trustedCallbacks(): array
    {
        return ['render'];
    }

    /** @param array<array-key, mixed> $variables */
    public function mark(array &$variables): void
    {
        $page = $this->page();
        if (null !== $page) {
            $variables['attributes']['data-collaborate-page'] = $page;
        }
    }

    /** What the current route shows: an entity type's listing, one entity, or its form. */
    private function page(): ?string
    {
        $name = (string) $this->route->getRouteName();
        if (isset(self::KINDS[$name])) {
            return self::KINDS[$name];
        }
        if (1 === preg_match('/^entity\.([a-z0-9_]+)\.(collection|canonical|edit_form|add_form)$/', $name, $match)) {
            return (
                $match[1]
                . ':'
                . [
                    'collection' => 'index',
                    'canonical' => 'detail',
                    'edit_form' => 'edit',
                    'add_form' => 'new',
                ][$match[2]]
            );
        }
        if ('node.add' === $name) {
            return 'node:new';
        }
        // A view's page lists the entities of its base table: refreshed for any save.
        return str_starts_with($name, 'view.') ? 'index' : null;
    }

    private function collaborates(): bool
    {
        return (
            $this->users->isGranted($this->role)
            || $this->notifications && $this->users->isGranted('receive collaborate notifications')
        );
    }
}
