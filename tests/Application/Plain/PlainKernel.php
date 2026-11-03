<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Plain;

use Janalis\Collaborate\Form\FormContextInterface;
use Janalis\Collaborate\Tests\Application\Shared\AppKernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * An application without EasyAdmin (installed, not registered): hand-written list, edit and new pages
 * ({@see ArticleController}) whose form context is {@see PlainFormContext}.
 */
final class PlainKernel extends AppKernel
{
    /**
     * The browser environments that render the pages with another stock form theme than Bootstrap 5's: the
     * bundle reads the markup of every one.
     */
    public const array FORM_THEMES = [
        'e2e_div' => 'form_div_layout.html.twig',
        'e2e_table' => 'form_table_layout.html.twig',
        'e2e_tailwind' => 'tailwind_2_layout.html.twig',
    ];

    public static function app(): string
    {
        return 'plain';
    }

    protected function appBundles(): iterable
    {
        return [];
    }

    protected function configureApp(ContainerConfigurator $container, ServicesConfigurator $services): void
    {
        $container->extension('twig', [
            'form_themes' => [self::FORM_THEMES[$this->environment] ?? 'bootstrap_5_layout.html.twig'],
        ]);
        $services->set(PlainFormContext::class);
        $services->alias(FormContextInterface::class, PlainFormContext::class);
        $services->set(ArticleController::class)->tag('controller.service_arguments');
    }

    protected function configureAppRoutes(RoutingConfigurator $routes): void
    {
        $routes
            ->add('home', '/admin')
            ->controller('Symfony\Bundle\FrameworkBundle\Controller\RedirectController')
            ->defaults(['route' => 'article_index']);
        $routes
            ->add('article_index', '/admin/articles')
            ->controller([ArticleController::class, 'index'])
            ->methods(['GET']);
        $routes
            ->add('article_new', '/admin/articles/new')
            ->controller([ArticleController::class, 'new'])
            ->methods(['GET', 'POST']);
        // No requirement on the id: the form context's edit URL carries the `__record__` placeholder.
        $routes
            ->add('article_edit', '/admin/articles/{id}/edit')
            ->controller([ArticleController::class, 'edit'])
            ->methods(['GET', 'POST']);
    }
}
