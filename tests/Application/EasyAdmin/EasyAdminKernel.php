<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;
use Janalis\Collaborate\Tests\Application\Shared\AppKernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

/**
 * An EasyAdmin application: a dashboard at `/admin` with the Article and Tag CRUDs; its layout override
 * renders the bundle's layer and presence. The bundle's EasyAdmin adapter is the form context.
 */
final class EasyAdminKernel extends AppKernel
{
    public static function app(): string
    {
        return 'easyadmin';
    }

    protected function appBundles(): iterable
    {
        yield new TwigExtraBundle();
        yield new TwigComponentBundle();
        yield new EasyAdminBundle();
    }

    protected function configureApp(ContainerConfigurator $container, ServicesConfigurator $services): void
    {
        $container->extension('twig_component', ['anonymous_template_directory' => 'components']);
        $services->set(DashboardController::class);
        $services->set(ArticleCrudController::class);
        $services->set(TagCrudController::class);
        $services->set(CategoryCrudController::class);
    }

    protected function configureAppRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'easyadmin.routes');
    }
}
