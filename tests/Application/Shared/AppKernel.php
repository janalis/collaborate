<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Tests\Application\Shared\Command\SetupCommand;
use Janalis\Collaborate\Tests\Application\Shared\Hub\RecordingHub;
use Janalis\Collaborate\Tests\Application\Shared\Security\LoginAuthenticator;
use Janalis\Collaborate\Tests\Application\Shared\Security\LoginController;
use Janalis\Collaborate\Tests\Fixtures\TestUserProvider;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Extension\ImportMapExtension;
use Symfony\Bridge\Twig\Extension\ImportMapRuntime;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MercureBundle\MercureBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\Type\FormTypeValidatorExtension;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * What the two test applications share: sign-in by user name over {@see TestUserProvider} (alice and bob
 * are administrators, reader is not), Doctrine on the database {@see Database::url()} names, a Mercure hub
 * and the bundle with shared drafts, cursors and saved-view updates on.
 *
 * Environments: `test` (PHPUnit: mock sessions, a {@see RecordingHub}), `e2e` (FrankenPHP: file sessions,
 * the hub the web server runs at `MERCURE_URL`, two avatars before the `+N` counter) and `e2e_limits` (`e2e`
 * with the smallest draft limits, {@see LIMITS}).
 */
abstract class AppKernel extends Kernel
{
    use MicroKernelTrait;

    public const string MERCURE_SECRET = '!ChangeThisMercureHubJWTSecretKey!';

    /** The browser environment whose drafts reach their limits within one scenario, and compact as soon as they may. */
    public const string LIMITS = 'e2e_limits';

    /** The smallest limits the configuration accepts, for {@see LIMITS}. */
    private const array SMALL_LIMITS = [
        'max_update_bytes' => 4096,
        'max_draft_bytes' => 65_536,
        'max_fields' => 30,
        'compact' => ['bytes' => 1024, 'idle' => 0],
    ];

    /** The application's name: its database, session cookie and directories. */
    abstract public static function app(): string;

    /** @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface> the bundles between the framework's and this bundle */
    abstract protected function appBundles(): iterable;

    abstract protected function configureApp(ContainerConfigurator $container, ServicesConfigurator $services): void;

    abstract protected function configureAppRoutes(RoutingConfigurator $routes): void;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new MercureBundle();
        yield from $this->appBundles();
        yield new CollaborateBundle();
    }

    public function getProjectDir(): string
    {
        // The directory of the application's kernel: its templates, assets and importmap.php.
        return \dirname((string) new \ReflectionClass(static::class)->getFileName());
    }

    public function getCacheDir(): string
    {
        return $this->varDir() . '/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->varDir() . '/log';
    }

    /** The repository root, where node_modules lives. */
    public static function repository(): string
    {
        return \dirname(__DIR__, 3);
    }

    /** Parallel test processes (Infection's, ParaTest's) each own their container and database: `TEST_TOKEN`. */
    private function varDir(): string
    {
        $token = Environment::get('TEST_TOKEN');

        return \dirname(__DIR__) . '/var/' . static::app() . (null === $token ? '' : '/t' . $token);
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $test = 'test' === $this->environment;
        $root = self::repository();
        $generated = \dirname(__DIR__) . '/var/assets';
        $container->extension('framework', [
            'secret' => hash('sha256', 'collaborate-' . static::app()),
            'test' => $test,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => $test
                ? ['storage_factory_id' => 'session.storage.factory.mock_file']
                : [
                    'handler_id' => 'session.handler.native_file',
                    'save_path' => $this->varDir() . '/sessions',
                    'name' => static::app() . '_session',
                ],
            'csrf_protection' => true,
            'translator' => ['fallbacks' => ['en']],
            'router' => ['utf8' => true],
            'validation' => ['enabled' => true],
            'form' => ['enabled' => true],
            'assets' => ['enabled' => true],
            'http_client' => ['enabled' => true],
            'asset_mapper' => [
                'paths' => [
                    $this->getProjectDir() . '/assets' => '',
                    __DIR__ . '/assets' => 'shared',
                    $root . '/node_modules/ckeditor5/dist/browser' => 'vendor/ckeditor5',
                    $root . '/node_modules/bootstrap/js/src' => 'vendor/bootstrap',
                    $generated => 'build',
                ],
                'excluded_patterns' => ['*/*.map', '*/*.umd.js', '*/*.scss'],
                // Served by the application in every environment: nothing is compiled into public/.
                'server' => true,
            ],
        ]);
        $container->extension('security', [
            'providers' => ['users' => ['id' => TestUserProvider::class]],
            'firewalls' => [
                'main' => [
                    'lazy' => true,
                    'provider' => 'users',
                    'custom_authenticators' => [LoginAuthenticator::class],
                    'entry_point' => LoginAuthenticator::class,
                    'logout' => ['path' => '/logout', 'target' => '/login'],
                ],
            ],
            'access_control' => [['path' => '^/admin', 'roles' => 'IS_AUTHENTICATED_FULLY']],
        ]);
        $container->extension('twig', [
            'default_path' => $this->getProjectDir() . '/templates',
            'paths' => [__DIR__ . '/templates' => 'Shared'],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => Database::url(static::app(), $this->environment)],
            'orm' => [
                'mappings' => [
                    'App' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__ . '/Entity',
                        'prefix' => __NAMESPACE__ . '\Entity',
                    ],
                ],
            ],
        ]);
        // Read when the container runs: the console that prepares an application builds the same cached container.
        $container->parameters()->set('env(MERCURE_URL)', 'http://127.0.0.1/.well-known/mercure');
        $container->parameters()->set('env(MERCURE_JWT_SECRET)', self::MERCURE_SECRET);
        $hub = '%env(MERCURE_URL)%';
        $container->extension('mercure', [
            'hubs' => [
                'default' => [
                    'url' => $hub,
                    'public_url' => $hub,
                    'jwt' => [
                        'secret' => '%env(MERCURE_JWT_SECRET)%',
                        'publish' => ['*'],
                        'claims' => [
                            'iss' => 'collaborate-' . static::app(),
                            'sub' => 'collaborate-' . static::app(),
                            'client_id' => 'collaborate-' . static::app(),
                            'aud' => $hub,
                        ],
                    ],
                ],
            ],
        ]);
        $container->extension('collaborate', [
            'drafts' =>
                [
                    'enabled' => true,
                    'rich_text' => ['block_prefixes' => ['rich_text'], 'ckeditor_version' => self::ckeditorVersion()],
                ] + (self::LIMITS === $this->environment ? self::SMALL_LIMITS : []),
            'updates' => ['enabled' => true],
        ] + ($test ? [] : ['cursors' => ['max_avatars' => 2]]));

        $services = $container->services();
        $services->defaults()->autowire()->autoconfigure();
        if ($test) {
            // Nothing a test asserts on is logged; the hub records instead of publishing.
            $services->set('logger', NullLogger::class);
            $services
                ->set(RecordingHub::class)
                ->decorate('mercure.hub.default')
                ->args([service('.inner')])
                ->public();
        } else {
            if (!is_dir($this->getLogDir())) {
                mkdir($this->getLogDir(), 0o777, true);
            }
            $services->set('logger', Logger::class)->args([
                'warning',
                $this->getLogDir() . '/' . $this->environment . '.log',
            ]);
            $services->set(E2eController::class)->tag('controller.service_arguments');
        }
        // TwigBundle registers `importmap()` only when symfony/asset-mapper is a non-dev requirement, as in an
        // application; here it is the bundle's dev dependency.
        $services
            ->set('twig.runtime.importmap', ImportMapRuntime::class)
            ->args([service('asset_mapper.importmap.renderer')])
            ->tag('twig.runtime');
        $services->set('twig.extension.importmap', ImportMapExtension::class)->tag('twig.extension');
        // FrameworkBundle validates forms only when symfony/validator is a non-dev requirement, as in an
        // application; here it is the bundle's dev dependency. Named arguments skip the second parameter, a flag
        // on Symfony 7.4 and a violation mapper on 8.x: both build their own mapper from the renderer and translator.
        $services->set('form.type_extension.form.validator', FormTypeValidatorExtension::class)->args([
            '$validator' => service('validator'),
            '$formRenderer' => service('twig.form.renderer')->ignoreOnInvalid(),
            '$translator' => service('translator')->ignoreOnInvalid(),
        ])->tag('form.type_extension', ['extended-type' => FormType::class]);
        $services->set(TestUserProvider::class);
        $services->set(LoginAuthenticator::class);
        $services->set(LoginController::class)->tag('controller.service_arguments');
        $services->set(SetupCommand::class);
        $this->configureApp($container, $services);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(self::repository() . '/config/routes.php')->prefix('/admin');
        $routes->add('login', '/login')->controller(LoginController::class)->methods(['GET', 'POST']);
        $routes->add('logout', '/logout')->methods(['GET']);
        if ('test' !== $this->environment) {
            $routes->add('e2e_articles', '/e2e/articles')->controller(E2eController::class)->methods(['POST']);
            $routes
                ->add('e2e_article_update', '/admin/e2e/articles/{id}')
                ->controller([E2eController::class, 'update'])
                ->requirements(['id' => '\d+'])
                ->methods(['POST']);
        }
        $this->configureAppRoutes($routes);
    }

    /** The CKEditor release the pages load, part of every rich-text field's schema. */
    private static function ckeditorVersion(): string
    {
        $package = json_decode(
            (string) file_get_contents(self::repository() . '/node_modules/ckeditor5/package.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        return \is_array($package) && \is_string($package['version'] ?? null) ? $package['version'] : '0.0.0';
    }
}
