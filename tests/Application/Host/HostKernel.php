<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Host;

use Janalis\Collaborate\Tests\Application\Shared\AppKernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

/**
 * A host whose pages are no forms: a library of folders ({@see LibraryController}), each a listing of records
 * keyed by `data-key` in rows or tiles, reached from the library or from a home, with a share popup per record
 * and a toolbar of its own. Drafts are off; every cursor option a host may set is on, the room of a page being
 * the folder {@see HostPlaces} names.
 */
final class HostKernel extends AppKernel
{
    /** The colours of the presence and the pointers: Bootstrap 6's, which `host.scss` names alike. */
    public const array COLORS = [
        'blue',
        'indigo',
        'violet',
        'purple',
        'pink',
        'red',
        'orange',
        'amber',
        'yellow',
        'lime',
        'green',
        'teal',
        'cyan',
        'brown',
        'gray',
        'pewter',
    ];

    public static function app(): string
    {
        return 'host';
    }

    protected function appBundles(): iterable
    {
        return [];
    }

    protected function configureApp(ContainerConfigurator $container, ServicesConfigurator $services): void
    {
        // Merged over the shared configuration: no drafts, no saved views, and the pages described.
        $container->extension('collaborate', [
            'colors' => self::COLORS,
            'drafts' => ['enabled' => false],
            'updates' => ['enabled' => false],
            'cursors' => [
                'places' => HostPlaces::class,
                'regions' => [
                    'modal' => 'dialog[open]',
                    'toolbar' => '#toolbar',
                    'listing' => '#listing',
                    'inspector' => '#inspector',
                    'body' => 'body',
                ],
                'key_attribute' => 'data-key',
                'key_scope' => '#listing',
                'anchor_attributes' => ['data-action', 'name', 'id'],
                'anchor_classes' => true,
                'caret_regions' => ['modal'],
                'popups' => [
                    'share' => ['where' => 'in the sharing of %%name%%', 'line' => 'Also sharing: %%names%%.'],
                ],
                'idle' => 5,
                'markers' => true,
                'edges' => '.pane',
                'top_layer' => true,
                'presence' => 'menu',
                'distinct_colors' => true,
                'silent_alone' => true,
                'share_stream' => true,
            ],
        ]);
        $services
            ->set(HostPlaces::class)
            ->args([param('kernel.logs_dir')])
            ->public();
        $services->set(LibraryController::class)->tag('controller.service_arguments');
    }

    protected function configureAppRoutes(RoutingConfigurator $routes): void
    {
        $routes
            ->add('home', '/admin')
            ->controller('Symfony\Bundle\FrameworkBundle\Controller\RedirectController')
            ->defaults(['route' => 'library', 'folder' => 'music']);
        $routes
            ->add('library', '/admin/library/{folder}')
            ->controller([LibraryController::class, 'page'])
            ->requirements(['folder' => '[a-z]+'])
            ->methods(['GET']);
        // The same folder from a home: another address, the same place.
        $routes
            ->add('library_home', '/admin/homes/{home}/{folder}')
            ->controller([LibraryController::class, 'page'])
            ->requirements(['home' => '[a-z]+', 'folder' => '[a-z]+'])
            ->methods(['GET']);
        $routes
            ->add('library_listing', '/admin/library/{folder}/listing')
            ->controller([LibraryController::class, 'listing'])
            ->requirements(['folder' => '[a-z]+'])
            ->methods(['GET']);
        if ('test' !== $this->environment) {
            $routes
                ->add('e2e_revoke', '/e2e/folders/{folder}/revoke')
                ->controller([LibraryController::class, 'revoke'])
                ->requirements(['folder' => '[a-z]+'])
                ->methods(['POST']);
        }
    }
}
