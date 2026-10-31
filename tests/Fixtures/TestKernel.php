<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Janalis\Collaborate\CollaborateBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MercureBundle\MercureBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * A minimal application around the bundle: session and CSRF, a stateful firewall over {@see TestUserProvider}
 * (sign in with `KernelBrowser::loginUser()`), Twig, Doctrine on `COLLABORATE_DATABASE_URL` (connected lazily)
 * and a Mercure hub that is never reached. The bundle's routes live under `/admin`, next to `GET /admin/page`
 * ({@see PageController}).
 *
 * `$collaborate` is merged into the bundle's configuration; each configuration builds its own container.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string HUB_URL = 'https://hub.example.com/.well-known/mercure';
    public const array JWT_CLAIMS = [
        'iss' => 'https://app.example.com',
        'aud' => 'https://hub.example.com/.well-known/mercure',
        'sub' => 'collaborate-tests',
        'client_id' => 'collaborate-tests',
    ];

    /** @param array<string, mixed> $collaborate */
    public function __construct(
        string $environment = 'test',
        bool $debug = true,
        private readonly array $collaborate = [],
    ) {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new MercureBundle();
        yield new CollaborateBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->varDir() . '/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->varDir() . '/log';
    }

    private function varDir(): string
    {
        return sys_get_temp_dir() . '/janalis-collaborate-tests/' . hash('xxh128', serialize($this->collaborate));
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => hash('sha256', 'collaborate-tests'),
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => true,
            'translator' => ['fallbacks' => ['en']],
            'router' => ['utf8' => true],
        ]);
        $container->extension('security', [
            'providers' => ['test' => ['id' => TestUserProvider::class]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'test']],
        ]);
        $container->extension('twig', ['default_path' => __DIR__ . '/templates']);
        $url = $_SERVER['COLLABORATE_DATABASE_URL'] ?? $_ENV['COLLABORATE_DATABASE_URL'] ?? null;
        $container->extension('doctrine', [
            'dbal' => [
                'url' => \is_string($url)
                    ? $url
                    : 'mysql://root@127.0.0.1:3306/collaborate_test?serverVersion=10.11.0-MariaDB',
            ],
            'orm' => [],
        ]);
        $container->extension('mercure', [
            'hubs' => [
                'default' => [
                    'url' => self::HUB_URL,
                    'jwt' => [
                        'secret' => hash('sha256', 'collaborate-tests-hub'),
                        'publish' => ['*'],
                        'claims' => self::JWT_CLAIMS,
                    ],
                ],
            ],
        ]);
        $container->extension('collaborate', $this->collaborate);

        $services = $container->services();
        // The test output stays readable: nothing a test asserts on is logged.
        $services->set('logger', NullLogger::class);
        $services->set(TestUserProvider::class);
        $services
            ->set(PageController::class)
            ->args([service('twig')])
            ->tag('controller.service_arguments');
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import($this->getProjectDir() . '/config/routes.php')->prefix('/admin');
        $routes->add('page', '/admin/page')->controller(PageController::class)->methods(['GET']);
    }
}
