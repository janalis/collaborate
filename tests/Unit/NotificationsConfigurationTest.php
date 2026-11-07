<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\DependencyInjection\Configuration;
use Janalis\Collaborate\Notification\Notifier;
use Janalis\Collaborate\Notification\Push\PushDispatcher;
use Janalis\Collaborate\Notification\Push\PushEndpoints;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Bundle\TwigBundle\DependencyInjection\TwigExtension;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

/** The `notifications` branch: its defaults, what it refuses, and what it registers. */
#[CoversClass(CollaborateBundle::class)]
#[CoversClass(Configuration::class)]
final class NotificationsConfigurationTest extends TestCase
{
    private const array BRANCH = [
        'topics' => ['shares' => ['label' => 'Shares']],
        'types' => ['shared' => ['topic' => 'shares', 'sentence' => '%%name%% shared']],
    ];

    private const array PUSH = [
        'enabled' => true,
        'public_key' => 'pub',
        'private_key' => 'priv',
        'subject' => 'mailto:a@b.c',
    ];

    public function testTheDefaults(): void
    {
        self::assertSame(
            [
                'enabled' => false,
                'role' => null,
                'topics' => [],
                'types' => [],
                'latest' => 20,
                'page_size' => 30,
                'max_count' => 99,
                'layout' => 'base.html.twig',
                'page_template' => '@JanalisCollaborate/notifications/page.html.twig',
                'topics_template' => '@JanalisCollaborate/notifications/topics.html.twig',
                'locales' => null,
                'stream_guard' => null,
                'bus' => null,
                'web_push' => [
                    'enabled' => false,
                    'public_key' => null,
                    'private_key' => null,
                    'subject' => null,
                    'ttl' => 86_400,
                    'urgency' => 'normal',
                    'icon' => null,
                    'title' => 'New notification',
                    'payload' => 'full',
                    'skip_online' => true,
                    'endpoint_hosts' => PushEndpoints::HOSTS,
                    'service_worker' => null,
                ],
            ],
            $this->process([])['notifications'],
        );
        $branch = $this->notifications(['notifications' => self::BRANCH]);
        self::assertTrue($branch['enabled']);
        self::assertSame(
            ['shares' => [
                'label' => 'Shares',
                'description' => null,
                'default' => true,
                'mandatory' => false,
                'domain' => 'messages',
            ]],
            $branch['topics'],
        );
        self::assertSame(
            ['shared' => [
                'topic' => 'shares',
                'sentence' => '%%name%% shared',
                'label' => null,
                'icon' => null,
                'domain' => 'messages',
            ]],
            $branch['types'],
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'a type of no topic' => [
            [
                'types' => [
                    'shared' => ['topic' => 'nope', 'sentence' => 'x'],
                    'other' => ['topic' => 'none', 'sentence' => 'y'],
                ],
            ],
            'Every notification type names a configured topic: shared, other.',
        ];
        yield 'a topic named as no column holds' => [
            ['topics' => ['Shares' => ['label' => 'x']]],
            'Topics are lowercase words joined by "-" or "_", at most 64 characters',
        ];
        yield 'a type named as no column holds' => [
            ['topics' => ['a' => ['label' => 'x']], 'types' => ['a b' => ['topic' => 'a', 'sentence' => 'x']]],
            'Types are lowercase words joined by "-" or "_", at most 64 characters',
        ];
        yield 'a type whose topic is no name' => [
            ['topics' => ['a' => ['label' => 'x']], 'types' => ['shared' => ['topic' => true, 'sentence' => 'x']]],
            'Every notification type names a configured topic: shared.',
        ];
        foreach (['latest', 'page_size', 'max_count'] as $count) {
            yield 'no ' . $count => [[$count => 0], 'Should be greater than or equal to 1'];
        }
        yield 'a push that lived before it was sent' => [
            ['web_push' => ['enabled' => false, 'ttl' => -1]],
            'Should be greater than or equal to 0',
        ];
        yield 'a name too long' => [
            ['topics' => [str_repeat('a', 65) => ['label' => 'x']]],
            'Topics are lowercase words',
        ];
        foreach (['public_key', 'private_key', 'subject'] as $missing) {
            $push = self::PUSH;
            $push[$missing] = '';
            yield 'web push without its ' . $missing => [
                ['web_push' => $push],
                'Web push needs a VAPID public key, private key and subject',
            ];
            unset($push[$missing]);
            yield 'web push with no ' . $missing => [
                ['web_push' => $push],
                'Web push needs a VAPID public key, private key and subject',
            ];
        }
    }

    /** @param array<string, mixed> $branch */
    #[DataProvider('invalid')]
    public function testRefusesAnInvalidBranch(array $branch, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->process(['notifications' => $branch]);
    }

    public function testANameOfSixtyFourCharactersAndDashesIsOne(): void
    {
        $name = 'a-b_' . str_repeat('c', 60);
        $branch = $this->notifications(['notifications' => ['topics' => [$name => ['label' => 'x']]]]);
        self::assertIsArray($branch['topics']);
        self::assertArrayHasKey($name, $branch['topics']);
        // Off, web push needs no key.
        $this->process(['notifications' => ['web_push' => ['enabled' => false, 'public_key' => '']]]);
        // A dash alone is kept as it is written.
        $dashed = $this->notifications(['notifications' => ['topics' => ['a-b' => ['label' => 'x']]]]);
        self::assertIsArray($dashed['topics']);
        self::assertSame(['a-b'], array_keys($dashed['topics']));
    }

    public function testTheSmallestCountsAndEveryUrgencyAndPayloadAreAccepted(): void
    {
        $branch = $this->notifications([
            'notifications' => [
                'latest' => 1,
                'page_size' => 1,
                'max_count' => 1,
                'web_push' => ['enabled' => false, 'ttl' => 0, 'urgency' => 'very-low', 'payload' => 'full'],
            ],
        ]);
        self::assertSame([1, 1, 1], [$branch['latest'], $branch['page_size'], $branch['max_count']]);
        self::assertIsArray($branch['web_push']);
        self::assertSame([0, 'very-low', 'full'], [
            $branch['web_push']['ttl'],
            $branch['web_push']['urgency'],
            $branch['web_push']['payload'],
        ]);
        foreach (['low', 'normal', 'high'] as $urgency) {
            self::assertIsArray($this->notifications([
                'notifications' => ['web_push' => ['enabled' => false, 'urgency' => $urgency]],
            ]));
        }
        self::assertIsArray($this->notifications([
            'notifications' => ['web_push' => ['enabled' => false, 'payload' => 'none']],
        ]));
    }

    public function testTheRoleFollowsTheBundlesUnlessSet(): void
    {
        self::assertSame(
            'ROLE_EDITOR',
            $this->load(['role' => 'ROLE_EDITOR'])->getParameter('collaborate.notifications.role'),
        );
        self::assertSame(
            'ROLE_USER',
            $this->load(['role' => 'ROLE_EDITOR', 'notifications' => ['role' => 'ROLE_USER']])->getParameter(
                'collaborate.notifications.role',
            ),
        );
    }

    public function testRegistersPushAndTheHandlersOnlyWhenTheyAreOn(): void
    {
        $off = $this->load([]);
        self::assertTrue($off->hasDefinition(Notifier::class));
        self::assertFalse($off->hasDefinition(PushDispatcher::class));
        self::assertNotContains('notifications_messenger.php', self::files($off));
        self::assertFalse($off->hasAlias('janalis_collaborate.notifications.bus'));
        // Off, nothing asks notifications of the signed-in user.
        self::assertFalse($off->hasAlias('janalis_collaborate.notifications.access'));

        $on = $this->load(['notifications' => self::BRANCH]);
        self::assertSame(
            'Janalis\\Collaborate\\Notification\\NotificationAccess',
            (string) $on->getAlias('janalis_collaborate.notifications.access'),
        );
        self::assertFalse($on->hasDefinition(PushDispatcher::class));
        self::assertContains('notifications_messenger.php', self::files($on));
        self::assertNotContains('notifications_push_messenger.php', self::files($on));

        $push = $this->load([
            'notifications' => self::BRANCH
                + [
                    'web_push' => self::PUSH,
                    'bus' => 'messenger.default_bus',
                    'locales' => 'app.locales',
                    'stream_guard' => 'app.stream',
                ],
        ]);
        self::assertTrue($push->hasDefinition(PushDispatcher::class));
        self::assertContains('notifications_push_messenger.php', self::files($push));
        self::assertSame('messenger.default_bus', (string) $push->getAlias('janalis_collaborate.notifications.bus'));
        self::assertSame('app.locales', (string) $push->getAlias('janalis_collaborate.notifications.locales'));
        self::assertSame('app.stream', (string) $push->getAlias('janalis_collaborate.notifications.stream_guard'));
        $unbused = $this->load(['notifications' => self::BRANCH + ['bus' => '', 'locales' => 'app.locales']]);
        self::assertFalse($unbused->hasAlias('janalis_collaborate.notifications.bus'));
        // A bus left empty does not keep the locales from being named.
        self::assertSame('app.locales', (string) $unbused->getAlias('janalis_collaborate.notifications.locales'));
    }

    public function testAnEnvDrivenWebPushFlagRegistersItsServicesWhichReadItAtRuntime(): void
    {
        foreach (['1' => true, '0' => false] as $flag => $expected) {
            // As a kernel loads it: the env placeholder reaches the extension as a placeholder string.
            $container = new ContainerBuilder();
            $container->setParameter('kernel.environment', 'test');
            $container->setParameter('kernel.build_dir', sys_get_temp_dir());
            // The bundle prepends the configuration of these extensions, which load nothing here.
            foreach (['framework', 'twig', 'doctrine'] as $prepended) {
                $container->registerExtension(new class($prepended) extends Extension {
                    public function __construct(
                        private readonly string $name,
                    ) {}

                    public function getAlias(): string
                    {
                        return $this->name;
                    }

                    public function load(array $configs, ContainerBuilder $container): void {}
                });
            }
            $container->registerExtension($this->extension());
            $container->loadFromExtension('collaborate', [
                'notifications' => self::BRANCH
                    + [
                        'web_push' => ['enabled' => '%env(bool:COLLABORATE_TEST_PUSH)%'] + self::PUSH,
                    ],
            ]);
            $container->setParameter('env(COLLABORATE_TEST_PUSH)', (string) $flag);
            new MergeExtensionConfigurationPass()->process($container);
            self::assertTrue($container->hasDefinition(PushDispatcher::class));
            self::assertContains('notifications_push_messenger.php', self::files($container));
            self::assertSame(
                '%collaborate.notifications.web_push.enabled%',
                $container->getDefinition(PushDispatcher::class)->getArgument(6),
            );
            self::assertSame($expected, $container->resolveEnvPlaceholders(
                $container->getParameter('collaborate.notifications.web_push.enabled'),
                true,
            ));
        }
        self::assertFalse($this->load([
            'notifications' => self::BRANCH + ['web_push' => ['enabled' => false]],
        ])->hasDefinition(PushDispatcher::class));
    }

    /** @return iterable<string, array{list<array<string, mixed>>, bool}> */
    public static function mappings(): iterable
    {
        yield 'no configuration' => [[], false];
        yield 'none of notifications' => [[['role' => 'ROLE_USER']], false];
        yield 'a branch' => [[['notifications' => ['topics' => []]]], true];
        yield 'a branch turned off' => [[['notifications' => ['enabled' => false]]], false];
        yield 'a branch turned on' => [[['notifications' => ['enabled' => true]]], true];
        yield 'true' => [[['notifications' => true]], true];
        yield 'false' => [[['notifications' => false]], false];
        yield 'null' => [[['notifications' => null]], true];
        yield 'the last file wins' => [[['notifications' => true], ['role' => 'x'], ['notifications' => false]], false];
        yield 'a later file turns them on' => [[['notifications' => false], ['notifications' => []]], true];
    }

    /** @param list<array<string, mixed>> $configs */
    #[DataProvider('mappings')]
    public function testMapsTheNotificationsOnlyWhenTheyAreOn(array $configs, bool $mapped): void
    {
        $container = new ContainerBuilder();
        // Symfony 7.4.0 reads the environment while loading a bundle extension.
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->registerExtension(new FrameworkExtension());
        $container->registerExtension(new TwigExtension());
        $container->registerExtension(new DoctrineExtension());
        // Prepended: the last file a host writes is the first in the list.
        foreach (array_reverse($configs) as $config) {
            $container->prependExtensionConfig('collaborate', $config);
        }
        $extension = $this->extension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $extension->prepend($container);
        $mappings = self::mappingsOf($container);
        self::assertArrayHasKey('JanalisCollaborate', $mappings);
        self::assertSame($mapped, isset($mappings['JanalisCollaborateNotifications']));
        if (!$mapped) {
            return;
        }
        $mapping = $mappings['JanalisCollaborateNotifications'];
        self::assertSame('Janalis\Collaborate\Notification\Entity', $mapping['prefix'] ?? null);
        self::assertSame('JanalisCollaborateNotifications', $mapping['alias'] ?? null);
        self::assertFalse($mapping['is_bundle'] ?? null);
        self::assertSame('attribute', $mapping['type'] ?? null);
        self::assertIsString($mapping['dir'] ?? null);
        self::assertDirectoryExists($mapping['dir']);
        self::assertSame(\dirname(__DIR__, 2) . '/src/Notification/Entity', realpath($mapping['dir']));
    }

    /** @return array<string, array<array-key, mixed>> the Doctrine mappings the prepended configurations name */
    private static function mappingsOf(ContainerBuilder $container): array
    {
        $mappings = [];
        foreach ($container->getExtensionConfig('doctrine') as $doctrine) {
            if (!\is_array($doctrine['orm'] ?? null) || !\is_array($doctrine['orm']['mappings'] ?? null)) {
                continue;
            }
            foreach (array_keys($doctrine['orm']['mappings']) as $name) {
                if (!\is_array($doctrine['orm']['mappings'][$name])) {
                    continue;
                }
                $mappings[(string) $name] ??= $doctrine['orm']['mappings'][$name];
            }
        }

        return $mappings;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed> the processed `notifications` branch
     */
    private function notifications(array $config): array
    {
        // @mago-expect analysis:mixed-assignment a processed branch is mixed: the next line narrows it
        $branch = $this->process($config)['notifications'] ?? null;
        self::assertIsArray($branch);

        return $branch;
    }

    /** @return list<string> */
    private static function files(ContainerBuilder $container): array
    {
        $files = [];
        foreach ($container->getResources() as $resource) {
            if (!$resource instanceof FileResource) {
                continue;
            }
            $files[] = basename($resource->getResource());
        }

        return $files;
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
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);

        return new Processor()->processConfiguration($configuration, [$config]);
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $this->extension()->load([$config], $container);

        return $container;
    }

    private function extension(): ExtensionInterface
    {
        $extension = new CollaborateBundle()->getContainerExtension();
        self::assertNotNull($extension);

        return $extension;
    }
}
