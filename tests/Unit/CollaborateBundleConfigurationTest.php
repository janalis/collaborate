<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Tests\Fixtures\Arrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Bundle\TwigBundle\DependencyInjection\TwigExtension;
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

    public function testPrependsTheAssetTemplateAndMappingPaths(): void
    {
        $container = new ContainerBuilder();
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
