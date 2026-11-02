<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Tests\Fixtures\Arrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Bundle\TwigBundle\DependencyInjection\TwigExtension;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

#[CoversClass(CollaborateBundle::class)]
final class CollaborateBundleConfigurationTest extends TestCase
{
    public function testTheDefaults(): void
    {
        $config = $this->process([]);
        self::assertSame('janalis_collaborate.identity.security', $config['identity']);
        self::assertSame('janalis_collaborate.access.role', $config['access']);
        self::assertSame('ROLE_ADMIN', $config['role']);
        self::assertSame('System', $config['system_label']);
        self::assertSame(CollaborateBundle::COLORS, $config['colors']);
        self::assertSame(
            [
                'name' => 'default',
                'topic_prefix' => 'urn:collaborate:',
                'subscription_ttl' => 300,
                'renew_interval' => 240,
                'publish_pause' => 5,
                'max_drafts' => 8,
            ],
            $config['hub'],
        );
        self::assertSame(
            [
                'enabled' => true,
                'rate_limiter' => null,
                'grant_ttl' => 36_000,
                'throttle' => 100,
                'heartbeat' => 5,
                'expiry' => 15,
                'max_avatars' => 5,
                'max_event_bytes' => 4096,
                'places' => null,
                'ignored_parameters' => CollaborateBundle::IGNORED_PARAMETERS,
                'regions' => CollaborateBundle::REGIONS,
                'key_attribute' => null,
                'key_scope' => 'body',
                'anchor_attributes' => CollaborateBundle::ANCHOR_ATTRIBUTES,
                'anchor_classes' => false,
                'caret_regions' => [],
                'popups' => [],
                'idle' => 0,
                'markers' => false,
                'edges' => null,
                'top_layer' => false,
                'presence' => 'avatars',
                'distinct_colors' => false,
                'silent_alone' => false,
                'share_stream' => false,
            ],
            $config['cursors'],
        );
        $drafts = Arrays::at($config, 'drafts');
        self::assertFalse($drafts['enabled']);
        self::assertNull($drafts['rate_limiter']);
        self::assertSame('cache.app', $drafts['grant_cache']);
        self::assertSame(2_097_152, $drafts['max_request_bytes']);
        self::assertSame(['bytes' => 1_048_576, 'fields' => 5_000, 'idle' => 60], $drafts['compact']);
        self::assertSame(
            ['block_prefixes' => [], 'schema_option' => null, 'ckeditor_version' => null],
            $drafts['rich_text'],
        );
        self::assertSame(CollaborateBundle::SENSITIVE_FIELDS, $drafts['sensitive_fields']);
        $updates = Arrays::at($config, 'updates');
        self::assertNull($updates['enabled']);
        self::assertSame('#^/admin(?:/|$)#', $updates['path_pattern']);
        self::assertSame(['updatedAt'], $updates['ignored_fields']);
    }

    public function testAHostDescribesItsPages(): void
    {
        $cursors = Arrays::at($this->process([
            'cursors' => [
                'places' => 'app.places',
                'regions' => ['modal' => 'dialog[open]', 'list-view' => '#list', 'body' => 'body'],
                'key_attribute' => 'data-key',
                'key_scope' => '#list',
                'anchor_attributes' => ['data-action', 'name'],
                'anchor_classes' => true,
                'caret_regions' => ['modal'],
                'popups' => ['metadata' => ['where' => 'in the properties of %%name%%'], 'share' => []],
                'idle' => 60,
                'markers' => true,
                'edges' => '.pane',
                'top_layer' => true,
                'presence' => 'menu',
                'distinct_colors' => true,
                'share_stream' => true,
            ],
        ]), 'cursors');
        self::assertSame('app.places', $cursors['places']);
        // The order of the regions is the one their roots are tried in.
        self::assertSame(['modal' => 'dialog[open]', 'list-view' => '#list', 'body' => 'body'], $cursors['regions']);
        self::assertSame('data-key', $cursors['key_attribute']);
        self::assertSame(['data-action', 'name'], $cursors['anchor_attributes']);
        self::assertSame(
            [
                // Escaped, as YAML writes them: the parameters resolve them.
                'metadata' => ['where' => 'in the properties of %%name%%', 'line' => 'Also open by %%names%%.'],
                'share' => ['where' => 'in %%name%%', 'line' => 'Also open by %%names%%.'],
            ],
            $cursors['popups'],
        );
        self::assertSame('menu', $cursors['presence']);
    }

    public function testRegionsReplaceTheDefaultsAcrossFiles(): void
    {
        $extension = $this->extension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);
        $config = new Processor()->processConfiguration($configuration, [
            ['cursors' => ['regions' => ['first' => '#a'], 'popups' => ['one' => []]]],
            ['cursors' => ['regions' => ['second' => '#b'], 'popups' => ['two' => []]]],
        ]);
        self::assertSame(['second' => '#b'], Arrays::at($config, 'cursors', 'regions'));
        self::assertSame(['two'], array_keys(Arrays::at($config, 'cursors', 'popups')));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidHosts(): iterable
    {
        yield 'no region' => [['regions' => []]];
        yield 'a region name with a capital' => [['regions' => ['Modal' => 'dialog']]];
        yield 'a region name ending with a dash' => [['regions' => ['modal-' => 'dialog']]];
        yield 'an empty region selector' => [['regions' => ['modal' => '']]];
        yield 'a key attribute with a space' => [['key_attribute' => 'data key']];
        yield 'a key attribute that is no string' => [['key_attribute' => ['data-key']]];
        yield 'an anchor attribute with a quote' => [['anchor_attributes' => ['data-"x']]];
        yield 'an anchor attribute that is no string' => [['anchor_attributes' => [true]]];
        yield 'a popup kind with an underscore' => [['popups' => ['meta_data' => []]]];
        yield 'an empty key scope' => [['key_scope' => '']];
        yield 'an unknown presence' => [['presence' => 'list']];
    }

    /** @param array<string, mixed> $cursors */
    #[DataProvider('invalidHosts')]
    public function testRefusesAnInvalidHost(array $cursors): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['cursors' => $cursors]);
    }

    public function testAKeyAttributeMayBeUnset(): void
    {
        self::assertNull(
            Arrays::at($this->process(['cursors' => ['key_attribute' => null]]), 'cursors')['key_attribute'],
        );
    }

    public function testPrependsTheAssetTemplateAndMappingPaths(): void
    {
        $container = new ContainerBuilder();
        // Symfony 7.4.0 reads the environment and build directory while prepending a bundle extension's configuration.
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        foreach ([new FrameworkExtension(), new TwigExtension(), new DoctrineExtension()] as $extension) {
            $container->registerExtension($extension);
        }
        $extension = $this->extension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $extension->prepend($container);
        $root = \dirname(__DIR__, 2);

        $assets = Arrays::at($container->getExtensionConfig('framework'), 0, 'asset_mapper', 'paths');
        self::assertSame(['@janalis/collaborate'], array_values($assets));
        self::assertSame(realpath($root . '/assets'), realpath((string) array_key_first($assets)));
        $templates = Arrays::at($container->getExtensionConfig('twig'), 0, 'paths');
        self::assertSame(['JanalisCollaborate'], array_values($templates));
        self::assertSame(realpath($root . '/templates'), realpath((string) array_key_first($templates)));
        $mapping = Arrays::at($container->getExtensionConfig('doctrine'), 0, 'orm', 'mappings', 'JanalisCollaborate');
        self::assertSame('attribute', $mapping['type'] ?? null);
        self::assertFalse($mapping['is_bundle'] ?? null);
        self::assertSame('Janalis\Collaborate\Entity', $mapping['prefix'] ?? null);
        self::assertIsString($mapping['dir'] ?? null);
        self::assertSame(realpath($root . '/src/Entity'), realpath($mapping['dir']));
    }

    public function testRefusesAnUnprocessedBranch(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "drafts" option must be an array.');
        $this->loadUnprocessed(['drafts' => true, 'updates' => []]);
    }

    public function testRefusesAnUnprocessedServiceId(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "identity" option must be a string.');
        $this->loadUnprocessed(['drafts' => ['enabled' => false], 'updates' => ['enabled' => false]]);
    }

    /** @param array<array-key, mixed> $config */
    private function loadUnprocessed(array $config): void
    {
        $container = new ContainerBuilder();
        $file = \dirname(__DIR__, 2) . '/src/CollaborateBundle.php';
        $loader = new PhpFileLoader($container, new FileLocator(\dirname($file)));
        $instanceof = [];
        new CollaborateBundle()->loadExtension(
            $config,
            new ContainerConfigurator($container, $loader, $instanceof, $file, $file),
            $container,
        );
    }

    /** @return iterable<string, array{string, int, int}> each integer option: its path, default and minimum */
    public static function integers(): iterable
    {
        $options = [
            'hub.subscription_ttl' => [300, 30],
            'hub.renew_interval' => [240, 10],
            'hub.publish_pause' => [5, 0],
            'hub.max_drafts' => [8, 1],
            'cursors.grant_ttl' => [36_000, 60],
            'cursors.throttle' => [100, 16],
            'cursors.heartbeat' => [5, 1],
            'cursors.expiry' => [15, 2],
            'cursors.max_avatars' => [5, 1],
            'cursors.max_event_bytes' => [4096, 512],
            'cursors.idle' => [0, 0],
            'drafts.grant_ttl' => [36_000, 60],
            'drafts.signature_ttl' => [900, 10],
            'drafts.max_request_bytes' => [2_097_152, 65_536],
            'drafts.max_update_bytes' => [262_144, 4096],
            'drafts.max_draft_bytes' => [10_485_760, 65_536],
            'drafts.max_fields' => [10_000, 10],
            'drafts.max_value_depth' => [8, 1],
            'drafts.label_length' => [255, 16],
            'drafts.compact.bytes' => [1_048_576, 1024],
            'drafts.compact.fields' => [5_000, 1],
            'drafts.compact.idle' => [60, 0],
            'drafts.locks.save' => [10, 1],
            'drafts.locks.writer' => [1, 0],
            'drafts.purge.clean_days' => [7, 1],
            'drafts.purge.unsaved_days' => [90, 1],
            'drafts.replay.connected' => [30, 1],
            'drafts.replay.disconnected' => [5, 1],
            'drafts.replay.join_retry' => [12, 0],
            'updates.association_depth' => [2, 0],
            'updates.chunk' => [100, 1],
        ];
        foreach ($options as $path => [$default, $minimum]) {
            yield $path => [$path, $default, $minimum];
        }
    }

    #[DataProvider('integers')]
    public function testEachIntegerOptionHasItsDefaultAndItsMinimum(string $path, int $default, int $minimum): void
    {
        self::assertSame($default, self::at($this->process([]), $path));
        self::assertSame($minimum, self::at($this->process(self::option($path, $minimum)), $path));

        $this->expectException(InvalidConfigurationException::class);
        $this->process(self::option($path, $minimum - 1));
    }

    /** @return array<string, mixed> the configuration setting `$path` to `$value` */
    private static function option(string $path, int $value): array
    {
        $config = $value;
        foreach (array_reverse(explode('.', $path)) as $key) {
            $config = [$key => $config];
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    /** @param array<array-key, mixed> $config */
    private static function at(array $config, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            self::assertIsArray($config);
            self::assertArrayHasKey($key, $config);
            $config = $config[$key];
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function process(array $config): array
    {
        $extension = $this->extension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);

        return new Processor()->processConfiguration($configuration, [$config]);
    }

    private function extension(): ExtensionInterface
    {
        $extension = new CollaborateBundle()->getContainerExtension();
        self::assertNotNull($extension);

        return $extension;
    }
}
