<?php

declare(strict_types=1);

namespace Janalis\Collaborate;

use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;
use Gedmo\Translatable\Translatable;
use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\DependencyInjection\Configuration;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Notification\NotificationAccess;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Shared drafts, presence and cursors for admin pages, carried by one Mercure stream per page.
 *
 * Every option is exposed as a `collaborate.<path>` container parameter (e.g. `collaborate.drafts.max_fields`)
 * that the service definitions in `config/services/*.php` read.
 */
final class CollaborateBundle extends AbstractBundle
{
    /** Bootstrap's `$colors`, in the order a user's colour is picked from (`id % count`). */
    public const array COLORS = [
        'blue',
        'indigo',
        'purple',
        'pink',
        'red',
        'orange',
        'yellow',
        'green',
        'teal',
        'cyan',
        'black',
        'white',
        'gray',
        'gray-dark',
    ];

    /** Region name => root selector, EasyAdmin's layout. */
    public const array REGIONS = [
        'modal' => '.modal.show',
        'header' => '.content-top',
        'mobile-header' => '.responsive-header',
        // `.ea-sidebar` from EasyAdmin 5.6, `.sidebar` before.
        'sidebar' => '.ea-sidebar, .sidebar',
        'content' => '.content-wrapper',
        'body' => 'body',
    ];

    public const array ANCHOR_ATTRIBUTES = ['data-id', 'data-column', 'name', 'id'];

    public const array IGNORED_PARAMETERS = ['_locale', '_token', '_switch_user'];

    /** Field names whose values never enter a draft, compared lowercased without `_` and `-`. */
    public const array SENSITIVE_FIELDS = [
        'password',
        'plainpassword',
        'salt',
        'secret',
        'clientsecret',
        'apikey',
        'apitoken',
        'token',
        'accesstoken',
        'refreshtoken',
        'confirmationtoken',
        'privatekey',
        'totpsecret',
        'backupcodes',
        'recoverycodes',
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        Configuration::define($definition);
    }

    /** @param array<array-key, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $drafts = self::branch($config, 'drafts');
        $updates = self::branch($config, 'updates');
        // The raw value: an env-driven `drafts.enabled` is a placeholder here, resolved when the container runs.
        $updates['enabled'] ??= $drafts['enabled'];
        $config['updates'] = $updates;
        $notifications = self::branch($config, 'notifications');
        // Notifications are told to whoever collaborates unless they name a role of their own.
        $notifications['role'] ??= $config['role'] ?? null;
        $config['notifications'] = $notifications;

        // A `%` the configuration holds reaches the extension escaped still (the `%name%` of a popup's sentence is
        // written `%%name%%`): the parameter resolves it once the container is compiled.
        $parameters = $this->flatten('collaborate', $config);
        foreach (array_keys($parameters) as $name) {
            $configurator->parameters()->set($name, $parameters[$name]);
        }

        $configurator->import(__DIR__ . '/../config/services/core.php');
        $container->setAlias(CollaboratorProviderInterface::class, self::string($config, 'identity'));
        $container->setAlias(DraftAccessCheckerInterface::class, self::string($config, 'access'));
        $container->setAlias('janalis_collaborate.grant_cache', self::string($drafts, 'grant_cache'));
        $container->setAlias(
            'janalis_collaborate.hub',
            'mercure.hub.' . self::string(self::branch($config, 'hub'), 'name'),
        );
        $cursors = self::branch($config, 'cursors');
        if (\is_string($cursors['places'] ?? null) && '' !== $cursors['places']) {
            $container->setAlias('janalis_collaborate.places', $cursors['places']);
        }
        // Without a configured limiter, its consumers receive null and do not limit.
        foreach (['cursors' => self::branch($config, 'cursors'), 'drafts' => $drafts] as $name => $branch) {
            if (!\is_string($branch['rate_limiter'] ?? null) || '' === $branch['rate_limiter']) {
                continue;
            }
            $container->setAlias('janalis_collaborate.limiter.' . $name, $branch['rate_limiter']);
        }
        $configurator->import(__DIR__ . '/../config/services/hub.php');
        // Always registered: the cursor events route answers 404 when cursors are disabled.
        $configurator->import(__DIR__ . '/../config/services/cursors.php');
        $configurator->import(__DIR__ . '/../config/services/drafts.php');
        $configurator->import(__DIR__ . '/../config/services/notifications.php');
        if (true === $notifications['enabled']) {
            $this->loadNotifications($notifications, $configurator, $container);
        }
        // Registered, not only installed: EasyAdmin may be a dependency of another package.
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];
        if (\is_array($bundles) && \in_array(EasyAdminBundle::class, $bundles, true)) {
            $configurator->import(__DIR__ . '/../config/services/easyadmin.php');
        }
        if (interface_exists(Translatable::class)) {
            $configurator->import(__DIR__ . '/../config/services/gedmo.php');
        }
    }

    /** @param array<array-key, mixed> $notifications */
    private function loadNotifications(
        array $notifications,
        ContainerConfigurator $configurator,
        ContainerBuilder $container,
    ): void {
        // What the hub, the grants and the layer ask of notifications: nothing of them runs while they are off.
        $container->setAlias('janalis_collaborate.notifications.access', NotificationAccess::class);
        foreach (['bus', 'locales', 'stream_guard'] as $option) {
            if (!\is_string($notifications[$option] ?? null) || '' === $notifications[$option]) {
                continue;
            }
            $container->setAlias('janalis_collaborate.notifications.' . $option, $notifications[$option]);
        }
        $push = self::branch($notifications, 'web_push');
        // An env-driven flag is a placeholder here: its services are registered, and they read it at runtime.
        $push['enabled'] = false !== $push['enabled'];
        $messenger = interface_exists(MessageBusInterface::class);
        if (true === $push['enabled']) {
            $configurator->import(__DIR__ . '/../config/services/notifications_push.php');
        }
        if ($messenger) {
            $configurator->import(__DIR__ . '/../config/services/notifications_messenger.php');
            if (true === $push['enabled']) {
                $configurator->import(__DIR__ . '/../config/services/notifications_push_messenger.php');
            }
        }
    }

    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        if (interface_exists(AssetMapperInterface::class)) {
            $configurator->extension('framework', [
                'asset_mapper' => ['paths' => [__DIR__ . '/../assets' => '@janalis/collaborate']],
            ]);
        }
        $configurator->extension('twig', ['paths' => [__DIR__ . '/../templates' => 'JanalisCollaborate']]);
        $configurator->extension('doctrine', [
            'orm' => [
                'mappings' =>
                    [
                        'JanalisCollaborate' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => __DIR__ . '/Entity',
                            'prefix' => 'Janalis\Collaborate\Entity',
                            'alias' => 'JanalisCollaborate',
                        ],
                    ]
                        + (
                            self::notificationsEnabled($container)
                                ? [
                                    // Mapped only when notifications are on: a host without them has no tables of theirs to create.
                                    'JanalisCollaborateNotifications' => [
                                        'type' => 'attribute',
                                        'is_bundle' => false,
                                        'dir' => __DIR__ . '/Notification/Entity',
                                        'prefix' => 'Janalis\Collaborate\Notification\Entity',
                                        'alias' => 'JanalisCollaborateNotifications',
                                    ],
                                ] : []
                        ),
            ],
        ]);
    }

    /**
     * Whether the configurations turn notifications on, as the processed tree will (`canBeEnabled()`: a branch
     * given without `enabled` is on), read before they are processed: the mapping is prepended.
     */
    private static function notificationsEnabled(ContainerBuilder $container): bool
    {
        $enabled = false;
        foreach ($container->getExtensionConfig('collaborate') as $config) {
            if (!\array_key_exists('notifications', $config)) {
                continue;
            }
            $enabled = match (true) {
                \is_array($config['notifications']) => !\array_key_exists('enabled', $config['notifications'])
                    || true === $config['notifications']['enabled'],
                \is_bool($config['notifications']) => $config['notifications'],
                default => true,
            };
        }

        return $enabled;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed> one parameter per option, lists and the whole tree's branches included
     */
    private function flatten(string $prefix, array $values): array
    {
        // Only the root and associative branches are flattened: a list is one parameter.
        $parameters = [$prefix => $values];
        foreach (array_keys($values) as $key) {
            $name = $prefix . '.' . $key;
            if (\is_array($values[$key]) && [] !== $values[$key] && !array_is_list($values[$key])) {
                $parameters += $this->flatten($name, $values[$key]);

                continue;
            }
            $parameters[$name] = $values[$key];
        }

        return $parameters;
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function branch(array $config, string $key): array
    {
        if (!\is_array($config[$key] ?? null)) {
            throw new \LogicException(\sprintf('The "%s" option must be an array.', $key));
        }

        return $config[$key];
    }

    /** @param array<array-key, mixed> $config */
    private static function string(array $config, string $key): string
    {
        if (!\is_string($config[$key] ?? null)) {
            throw new \LogicException(\sprintf('The "%s" option must be a string.', $key));
        }

        return $config[$key];
    }
}
