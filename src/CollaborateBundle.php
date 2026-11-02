<?php

declare(strict_types=1);

namespace Janalis\Collaborate;

use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;
use Gedmo\Translatable\Translatable;
use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

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

    /** A region or popup kind, or an attribute name. */
    private const string NAME = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';

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
        $definition
            ->rootNode()
            ->children()
            ->scalarNode('identity')
            ->info('Service id of a Janalis\Collaborate\Identity\CollaboratorProviderInterface.')
            ->defaultValue('janalis_collaborate.identity.security')
            ->end()
            ->scalarNode('access')
            ->info('Service id of a Janalis\Collaborate\Access\DraftAccessCheckerInterface.')
            ->defaultValue('janalis_collaborate.access.role')
            ->end()
            ->scalarNode('role')
            ->info('Role every collaboration endpoint, grant and layer requires.')
            ->defaultValue('ROLE_ADMIN')
            ->end()
            ->scalarNode('system_label')
            ->info('Name shown for writes no editor made (a compaction, a reconciliation, the purge).')
            ->defaultValue('System')
            ->end()
            ->arrayNode('colors')
            ->info(
                'Colour names, matched by the stylesheet\'s colour map; a user\'s colour is its id modulo their count.',
            )
            ->scalarPrototype()
            ->end()
            ->defaultValue(self::COLORS)
            ->requiresAtLeastOneElement()
            ->end()
            ->arrayNode('hub')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('name')
            ->info('Mercure hub name (mercure.hubs.<name>).')
            ->defaultValue('default')
            ->end()
            ->scalarNode('topic_prefix')
            ->info('Prefix of every topic: <prefix>cursors:<room>, <prefix>draft:<id>, <prefix>updates.')
            ->defaultValue('urn:collaborate:')
            ->end()
            ->integerNode('subscription_ttl')
            ->info('Subscriber JWT lifetime, seconds.')
            ->defaultValue(300)
            ->min(30)
            ->end()
            ->integerNode('renew_interval')
            ->info('Client renewal of the subscription, seconds.')
            ->defaultValue(240)
            ->min(10)
            ->end()
            ->integerNode('publish_pause')
            ->info('Seconds a worker stops publishing after a hub failure.')
            ->defaultValue(5)
            ->min(0)
            ->end()
            ->integerNode('max_drafts')
            ->info('Draft topics one page subscription may carry.')
            ->defaultValue(8)
            ->min(1)
            ->end()
            ->end()
            ->end()
            ->arrayNode('cursors')
            ->canBeDisabled()
            ->children()
            ->scalarNode('rate_limiter')
            ->info('Rate limiter factory service id for cursor events, or null.')
            ->defaultNull()
            ->end()
            ->integerNode('grant_ttl')
            ->info('Page grant lifetime, seconds.')
            ->defaultValue(36_000)
            ->min(60)
            ->end()
            ->integerNode('throttle')
            ->info('Minimum interval between two cursor events of a tab, milliseconds.')
            ->defaultValue(100)
            ->min(16)
            ->end()
            ->integerNode('heartbeat')
            ->info('Heartbeat interval, seconds.')
            ->defaultValue(5)
            ->min(1)
            ->end()
            ->integerNode('expiry')
            ->info('Seconds of silence after which a peer and its presence leave.')
            ->defaultValue(15)
            ->min(2)
            ->end()
            ->integerNode('max_avatars')
            ->info('Peers shown in the header before a +N counter.')
            ->defaultValue(5)
            ->min(1)
            ->end()
            ->integerNode('max_event_bytes')
            ->info('Largest cursor event body, bytes.')
            ->defaultValue(4096)
            ->min(512)
            ->end()
            ->scalarNode('places')
            ->info(
                'Service id of a Janalis\Collaborate\Cursor\PlaceProviderInterface naming the room of a page, '
                . 'or null for its route, parameters and query.',
            )
            ->defaultNull()
            ->end()
            ->arrayNode('ignored_parameters')
            ->info('Route and query parameters that do not separate rooms (without a place provider).')
            ->scalarPrototype()
            ->end()
            ->defaultValue(self::IGNORED_PARAMETERS)
            ->end()
            ->arrayNode('regions')
            ->info(
                'The regions of a page, name => selector, the first one an element is in naming it: a pointer is '
                . 'placed from the root of its region on the peers\' pages.',
            )
            ->useAttributeAsKey('name')
            // `mobile-header` stays one: keys are not `-` to `_` normalized.
            ->normalizeKeys(false)
            ->performNoDeepMerging()
            ->requiresAtLeastOneElement()
            ->validate()
            ->ifTrue(
                static fn(array $regions): bool => (
                    [] !== array_filter(
                        array_keys($regions),
                        static fn(int|string $name): bool => !preg_match(self::NAME, (string) $name),
                    )
                ),
            )
            ->thenInvalid('Region names are lowercase words joined by "-": %s')
            ->end()
            ->scalarPrototype()
            ->cannotBeEmpty()
            ->end()
            ->defaultValue(self::REGIONS)
            ->end()
            ->scalarNode('key_attribute')
            ->info(
                'Attribute naming a record wherever a page shows it (e.g. data-key): a pointer on it is placed '
                . 'from the record, whatever element shows it; null for none.',
            )
            ->defaultNull()
            ->validate()
            ->ifTrue(
                static fn(mixed $name): bool => (
                    null !== $name
                    && (!\is_string($name) || !preg_match(self::NAME, $name))
                ),
            )
            ->thenInvalid('The key attribute is a lowercase attribute name: %s')
            ->end()
            ->end()
            ->scalarNode('key_scope')
            ->info('Where the records a page shows are found, for markers, popups and finding a peer.')
            ->defaultValue('body')
            ->cannotBeEmpty()
            ->end()
            ->arrayNode('anchor_attributes')
            ->info('Attributes that name an element on the way from its region to a pointer, in order of preference.')
            ->scalarPrototype()
            ->validate()
            ->ifTrue(static fn(mixed $name): bool => !\is_string($name) || !preg_match(self::NAME, $name))
            ->thenInvalid('Anchor attributes are lowercase attribute names: %s')
            ->end()
            ->end()
            ->defaultValue(self::ANCHOR_ATTRIBUTES)
            ->end()
            ->booleanNode('anchor_classes')
            ->info('Name an element by a class no sibling shares before its rank among its siblings.')
            ->defaultFalse()
            ->end()
            ->arrayNode('caret_regions')
            ->info('Regions whose text fields share their caret; empty for every region.')
            ->scalarPrototype()
            ->end()
            ->defaultValue([])
            ->end()
            ->arrayNode('popups')
            ->info(
                'Kinds of popup a peer may have open on a record (data-collaborate-popup="<kind>" beside the key '
                . 'attribute), each with what the presence menu says of a peer in it and the line under its title, '
                . 'both translated in the collaborate domain.',
            )
            ->useAttributeAsKey('kind')
            ->normalizeKeys(false)
            ->performNoDeepMerging()
            ->validate()
            ->ifTrue(
                static fn(array $popups): bool => (
                    [] !== array_filter(
                        array_keys($popups),
                        static fn(int|string $kind): bool => !preg_match(self::NAME, (string) $kind),
                    )
                ),
            )
            ->thenInvalid('Popup kinds are lowercase words joined by "-": %s')
            ->end()
            ->arrayPrototype()
            ->children()
            ->scalarNode('where')
            ->info('Where a peer is, %name% naming the record.')
            ->defaultValue('in %name%')
            ->end()
            ->scalarNode('line')
            ->info('The line under the popup\'s title, %names% naming the peers.')
            ->defaultValue('Also open by %names%.')
            ->end()
            ->end()
            ->end()
            ->defaultValue([])
            ->end()
            ->integerNode('idle')
            ->info('Seconds after which a pointer that did not move is dimmed and its peer said idle; 0 never.')
            ->defaultValue(0)
            ->min(0)
            ->end()
            ->booleanNode('markers')
            ->info('Mark the records peers point at or have a popup of (requires key_attribute).')
            ->defaultFalse()
            ->end()
            ->scalarNode('edges')
            ->info('Selector of the panes whose edge shows an arrow for a pointer scrolled out of them, or null.')
            ->defaultNull()
            ->end()
            ->booleanNode('top_layer')
            ->info('Draw pointers in the top layer (a popover), above an open modal dialog.')
            ->defaultFalse()
            ->end()
            ->enumNode('presence')
            ->info('Peers in the header as avatars, or as the button of a menu that finds them.')
            ->values(['avatars', 'menu'])
            ->defaultValue('avatars')
            ->end()
            ->booleanNode('distinct_colors')
            ->info(
                'Draw a peer in the colour farthest from the others when an earlier one has theirs or one beside '
                . 'it in the colours list; otherwise in the colour the server picks.',
            )
            ->defaultFalse()
            ->end()
            ->booleanNode('share_stream')
            ->info(
                'Over HTTP/1.1, let one tab of a browser share its cursor at a time (Web Locks): each stream holds '
                . 'one of the six connections a browser opens to a site.',
            )
            ->defaultFalse()
            ->end()
            ->end()
            ->end()
            ->arrayNode('drafts')
            ->canBeEnabled()
            ->children()
            ->scalarNode('rate_limiter')
            ->info('Rate limiter factory service id for draft requests, or null.')
            ->defaultNull()
            ->end()
            ->integerNode('grant_ttl')
            ->info('Field grant lifetime, seconds.')
            ->defaultValue(36_000)
            ->min(60)
            ->end()
            ->integerNode('signature_ttl')
            ->info('Seconds a verified grant is resolved from its signature alone.')
            ->defaultValue(900)
            ->min(10)
            ->end()
            ->scalarNode('grant_cache')
            ->info('PSR-6 pool caching verified grants.')
            ->defaultValue('cache.app')
            ->end()
            ->integerNode('max_request_bytes')
            ->defaultValue(2_097_152)
            ->min(65_536)
            ->end()
            ->integerNode('max_update_bytes')
            ->defaultValue(262_144)
            ->min(4096)
            ->end()
            ->integerNode('max_draft_bytes')
            ->defaultValue(10_485_760)
            ->min(65_536)
            ->end()
            ->integerNode('max_fields')
            ->defaultValue(10_000)
            ->min(10)
            ->end()
            ->integerNode('max_value_depth')
            ->defaultValue(8)
            ->min(1)
            ->end()
            ->integerNode('label_length')
            ->info('Longest option label a choice event carries, characters.')
            ->defaultValue(255)
            ->min(16)
            ->end()
            ->arrayNode('compact')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('bytes')
            ->defaultValue(1_048_576)
            ->min(1024)
            ->end()
            ->integerNode('fields')
            ->defaultValue(5_000)
            ->min(1)
            ->end()
            ->integerNode('idle')
            ->info('Seconds nobody wrote to the draft before Join compacts it.')
            ->defaultValue(60)
            ->min(0)
            ->end()
            ->end()
            ->end()
            ->arrayNode('locks')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('save')
            ->info('Seconds a Save waits for another Save.')
            ->defaultValue(10)
            ->min(1)
            ->end()
            ->integerNode('writer')
            ->info('Seconds other writers wait before answering 503.')
            ->defaultValue(1)
            ->min(0)
            ->end()
            ->end()
            ->end()
            ->arrayNode('purge')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('clean_days')
            ->info('Idle days before a draft with nothing unsaved is purged.')
            ->defaultValue(7)
            ->min(1)
            ->end()
            ->integerNode('unsaved_days')
            ->info('Idle days before any draft is purged.')
            ->defaultValue(90)
            ->min(1)
            ->end()
            ->end()
            ->end()
            ->arrayNode('replay')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('connected')
            ->info('Replay interval while the hub stream is up, seconds.')
            ->defaultValue(30)
            ->min(1)
            ->end()
            ->integerNode('disconnected')
            ->info('Replay interval while it is down, seconds.')
            ->defaultValue(5)
            ->min(1)
            ->end()
            ->integerNode('join_retry')
            ->info('Seconds a busy Join is retried before falling back.')
            ->defaultValue(12)
            ->min(0)
            ->end()
            ->end()
            ->end()
            ->arrayNode('rich_text')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('block_prefixes')
            ->info('Form block prefixes rendered as a CKEditor rich-text field.')
            ->scalarPrototype()
            ->end()
            ->defaultValue([])
            ->end()
            ->scalarNode('schema_option')
            ->info('Boolean form option selecting the reduced editor build, part of a field\'s schema.')
            ->defaultNull()
            ->end()
            ->scalarNode('ckeditor_version')
            ->info('CKEditor version in the field schema; null reads `ckeditor5` from the importmap.')
            ->defaultNull()
            ->end()
            ->end()
            ->end()
            ->scalarNode('position_field')
            ->info('Child name that marks an ordered collection entry.')
            ->defaultValue('position')
            ->end()
            ->arrayNode('sensitive_fields')
            ->scalarPrototype()
            ->end()
            ->defaultValue(self::SENSITIVE_FIELDS)
            ->end()
            ->end()
            ->end()
            ->arrayNode('updates')
            ->info('Saved-list and saved-detail refresh; follows drafts.enabled unless set.')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->defaultNull()
            ->end()
            ->scalarNode('path_pattern')
            ->info('Regex of the request paths whose writes announce updates.')
            ->defaultValue('#^/admin(?:/|$)#')
            ->end()
            ->arrayNode('routes')
            ->info('Other routes whose writes announce updates (e.g. a sort endpoint outside the admin path).')
            ->scalarPrototype()
            ->end()
            ->defaultValue([])
            ->end()
            ->integerNode('association_depth')
            ->defaultValue(2)
            ->min(0)
            ->end()
            ->arrayNode('ignored_fields')
            ->info('Changed fields that alone never announce an update.')
            ->scalarPrototype()
            ->end()
            ->defaultValue(['updatedAt'])
            ->end()
            ->integerNode('chunk')
            ->info('Records named per draft notice.')
            ->defaultValue(100)
            ->min(1)
            ->end()
            ->end()
            ->end()
            ->end();
    }

    /** @param array<array-key, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $drafts = self::branch($config, 'drafts');
        $updates = self::branch($config, 'updates');
        // The raw value: an env-driven `drafts.enabled` is a placeholder here, resolved when the container runs.
        $updates['enabled'] ??= $drafts['enabled'];
        $config['updates'] = $updates;

        // The configuration reaching the extension is resolved already: a `%` it holds (the `%name%` of a popup's
        // sentence, written `%%name%%` in YAML) is escaped again, or the parameter would be resolved twice.
        $parameters = $this->flatten('collaborate', self::escape($config));
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
        $places = self::branch($config, 'cursors')['places'] ?? null;
        if (\is_string($places) && '' !== $places) {
            $container->setAlias('janalis_collaborate.places', $places);
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
        // Registered, not only installed: EasyAdmin may be a dependency of another package.
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];
        if (\is_array($bundles) && \in_array(EasyAdminBundle::class, $bundles, true)) {
            $configurator->import(__DIR__ . '/../config/services/easyadmin.php');
        }
        if (interface_exists(Translatable::class)) {
            $configurator->import(__DIR__ . '/../config/services/gedmo.php');
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
                'mappings' => [
                    'JanalisCollaborate' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__ . '/Entity',
                        'prefix' => 'Janalis\Collaborate\Entity',
                        'alias' => 'JanalisCollaborate',
                    ],
                ],
            ],
        ]);
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
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function escape(array $values): array
    {
        foreach (array_keys($values) as $key) {
            $values[$key] = match (true) {
                \is_string($values[$key]) => str_replace('%', '%%', $values[$key]),
                \is_array($values[$key]) => self::escape($values[$key]),
                default => $values[$key],
            };
        }

        return $values;
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
