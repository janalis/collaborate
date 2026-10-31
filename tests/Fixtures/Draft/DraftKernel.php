<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Tests\Fixtures\TestUserProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MercureBundle\MercureBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

/**
 * An EasyAdmin application with shared drafts: a dashboard at `/admin` and the CRUD of {@see Article}, whose
 * tables live in the database `COLLABORATE_DATABASE_URL` names.
 */
final class DraftKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new MercureBundle();
        yield new TwigExtraBundle();
        yield new TwigComponentBundle();
        yield new EasyAdminBundle();
        yield new CollaborateBundle();
    }

    /** Not the repository: a debug kernel dumps `config/reference.php` into its project's `config/`. */
    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return \dirname(__DIR__, 3) . '/var/draft-kernel/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \dirname(__DIR__, 3) . '/var/draft-kernel/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test-secret',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => true,
            'translator' => ['fallbacks' => ['en']],
            'router' => ['utf8' => true],
            'validation' => ['enabled' => true],
            'assets' => ['enabled' => true],
        ]);
        $container->extension('security', [
            'providers' => ['test' => ['id' => TestUserProvider::class]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'test', 'logout' => ['path' => '/logout']]],
        ]);
        $container->extension('twig_component', ['anonymous_template_directory' => 'components']);
        $url = $_SERVER['COLLABORATE_DATABASE_URL'] ?? $_ENV['COLLABORATE_DATABASE_URL'] ?? null;
        $container->extension('doctrine', [
            'dbal' => [
                'url' => \is_string($url)
                    ? $url
                    : 'mysql://root@127.0.0.1:3306/collaborate_test?serverVersion=10.11.0-MariaDB',
            ],
            'orm' => [
                'mappings' => [
                    'DraftFixtures' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__,
                        'prefix' => __NAMESPACE__,
                    ],
                ],
            ],
        ]);
        $container->extension('mercure', [
            'hubs' => [
                'default' => [
                    'url' => 'https://hub.example.com/.well-known/mercure',
                    'jwt' => [
                        'secret' => 'a-test-secret-of-at-least-256-bits-for-hs256!',
                        'publish' => ['*'],
                        'claims' => [
                            'iss' => 'https://app.example.com',
                            'aud' => 'https://hub.example.com/.well-known/mercure',
                            'sub' => 'collaborate-draft-tests',
                            'client_id' => 'collaborate-draft-tests',
                        ],
                    ],
                ],
            ],
        ]);
        $container->extension('collaborate', [
            'drafts' => ['enabled' => true, 'rich_text' => ['ckeditor_version' => '47.0.0']],
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(TestUserProvider::class);
        $services->set(DraftDashboardController::class)->autowire()->autoconfigure();
        $services->set(ArticleCrudController::class)->autowire()->autoconfigure();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(\dirname(__DIR__, 3) . '/config/routes.php')->prefix('/admin');
        $routes->import('.', 'easyadmin.routes');
    }
}
