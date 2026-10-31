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
            ->info('Colour names, matched by the stylesheet\'s colour map; a user\'s colour is its id modulo their count.')
            ->scalarPrototype()->end()
            ->defaultValue(self::COLORS)
            ->requiresAtLeastOneElement()
            ->end()
            ->arrayNode('hub')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('name')->info('Mercure hub name (mercure.hubs.<name>).')->defaultValue('default')->end()
            ->scalarNode('topic_prefix')
            ->info('Prefix of every topic: <prefix>cursors:<room>, <prefix>draft:<id>, <prefix>updates.')
            ->defaultValue('urn:collaborate:')
            ->end()
            ->integerNode('subscription_ttl')->info('Subscriber JWT lifetime, seconds.')->defaultValue(300)->min(30)->end()
            ->integerNode('renew_interval')->info('Client renewal of the subscription, seconds.')->defaultValue(240)->min(10)->end()
            ->integerNode('publish_pause')->info('Seconds a worker stops publishing after a hub failure.')->defaultValue(5)->min(0)->end()
            ->integerNode('max_drafts')->info('Draft topics one page subscription may carry.')->defaultValue(8)->min(1)->end()
            ->end()
            ->end()
            ->arrayNode('cursors')
            ->canBeDisabled()
            ->children()
            ->scalarNode('rate_limiter')->info('Rate limiter factory service id for cursor events, or null.')->defaultNull()->end()
            ->integerNode('grant_ttl')->info('Page grant lifetime, seconds.')->defaultValue(36_000)->min(60)->end()
            ->integerNode('throttle')->info('Minimum interval between two cursor events of a tab, milliseconds.')->defaultValue(100)->min(16)->end()
            ->integerNode('heartbeat')->info('Heartbeat interval, seconds.')->defaultValue(5)->min(1)->end()
            ->integerNode('expiry')->info('Seconds of silence after which a peer and its presence leave.')->defaultValue(15)->min(2)->end()
            ->integerNode('max_avatars')->info('Peers shown in the header before a +N counter.')->defaultValue(5)->min(1)->end()
            ->integerNode('max_event_bytes')->info('Largest cursor event body, bytes.')->defaultValue(4096)->min(512)->end()
            ->end()
            ->end()
            ->arrayNode('drafts')
            ->canBeEnabled()
            ->children()
            ->scalarNode('rate_limiter')->info('Rate limiter factory service id for draft requests, or null.')->defaultNull()->end()
            ->integerNode('grant_ttl')->info('Field grant lifetime, seconds.')->defaultValue(36_000)->min(60)->end()
            ->integerNode('signature_ttl')->info('Seconds a verified grant is resolved from its signature alone.')->defaultValue(900)->min(10)->end()
            ->scalarNode('grant_cache')->info('PSR-6 pool caching verified grants.')->defaultValue('cache.app')->end()
            ->integerNode('max_request_bytes')->defaultValue(2_097_152)->min(65_536)->end()
            ->integerNode('max_update_bytes')->defaultValue(262_144)->min(4096)->end()
            ->integerNode('max_draft_bytes')->defaultValue(10_485_760)->min(65_536)->end()
            ->integerNode('max_fields')->defaultValue(10_000)->min(10)->end()
            ->integerNode('max_value_depth')->defaultValue(8)->min(1)->end()
            ->integerNode('label_length')->info('Longest option label a choice event carries, characters.')->defaultValue(255)->min(16)->end()
            ->arrayNode('compact')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('bytes')->defaultValue(1_048_576)->min(1024)->end()
            ->integerNode('fields')->defaultValue(5_000)->min(1)->end()
            ->integerNode('idle')->info('Seconds nobody wrote to the draft before Join compacts it.')->defaultValue(60)->min(0)->end()
            ->end()
            ->end()
            ->arrayNode('locks')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('save')->info('Seconds a Save waits for another Save.')->defaultValue(10)->min(1)->end()
            ->integerNode('writer')->info('Seconds other writers wait before answering 503.')->defaultValue(1)->min(0)->end()
            ->end()
            ->end()
            ->arrayNode('purge')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('clean_days')->info('Idle days before a draft with nothing unsaved is purged.')->defaultValue(7)->min(1)->end()
            ->integerNode('unsaved_days')->info('Idle days before any draft is purged.')->defaultValue(90)->min(1)->end()
            ->end()
            ->end()
            ->arrayNode('replay')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('connected')->info('Replay interval while the hub stream is up, seconds.')->defaultValue(30)->min(1)->end()
            ->integerNode('disconnected')->info('Replay interval while it is down, seconds.')->defaultValue(5)->min(1)->end()
            ->integerNode('join_retry')->info('Seconds a busy Join is retried before falling back.')->defaultValue(12)->min(0)->end()
            ->end()
            ->end()
            ->arrayNode('rich_text')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('block_prefixes')
            ->info('Form block prefixes rendered as a CKEditor rich-text field.')
            ->scalarPrototype()->end()
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
            ->scalarPrototype()->end()
            ->defaultValue(self::SENSITIVE_FIELDS)
            ->end()
            ->end()
            ->end()
            ->arrayNode('updates')
            ->info('Saved-list and saved-detail refresh; follows drafts.enabled unless set.')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')->defaultNull()->end()
            ->scalarNode('path_pattern')
            ->info('Regex of the request paths whose writes announce updates.')
            ->defaultValue('#^/admin(?:/|$)#')
            ->end()
            ->arrayNode('routes')
            ->info('Other routes whose writes announce updates (e.g. a sort endpoint outside the admin path).')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->integerNode('association_depth')->defaultValue(2)->min(0)->end()
            ->arrayNode('ignored_fields')
            ->info('Changed fields that alone never announce an update.')
            ->scalarPrototype()->end()
            ->defaultValue(['updatedAt'])
            ->end()
            ->integerNode('chunk')->info('Records named per draft notice.')->defaultValue(100)->min(1)->end()
            ->end()
            ->end()
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $updates = $config['updates'];
        \assert(\is_array($updates));
        $drafts = $config['drafts'];
        \assert(\is_array($drafts));
        $updates['enabled'] ??= $drafts['enabled'];
        $config['updates'] = $updates;

        foreach ($this->flatten('collaborate', $config) as $name => $value) {
            $container->parameters()->set($name, $value);
        }

        $container->import(__DIR__.'/../config/services/core.php');
        \assert(\is_string($config['identity']) && \is_string($config['access']) && \is_string($drafts['grant_cache']));
        $builder->setAlias(CollaboratorProviderInterface::class, $config['identity']);
        $builder->setAlias(DraftAccessCheckerInterface::class, $config['access']);
        $builder->setAlias('janalis_collaborate.grant_cache', $drafts['grant_cache']);
        $hub = $config['hub'];
        \assert(\is_array($hub) && \is_string($hub['name']));
        $builder->setAlias('janalis_collaborate.hub', 'mercure.hub.'.$hub['name']);
        $container->import(__DIR__.'/../config/services/hub.php');
        if (true === ($config['cursors']['enabled'] ?? false)) {
            $container->import(__DIR__.'/../config/services/cursors.php');
        }
        $container->import(__DIR__.'/../config/services/drafts.php');
        if (class_exists(EasyAdminBundle::class)) {
            $container->import(__DIR__.'/../config/services/easyadmin.php');
        }
        if (interface_exists(Translatable::class)) {
            $container->import(__DIR__.'/../config/services/gedmo.php');
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (interface_exists(AssetMapperInterface::class)) {
            $container->extension('framework', [
                'asset_mapper' => ['paths' => [__DIR__.'/../assets' => '@janalis/collaborate']],
            ]);
        }
        $container->extension('twig', ['paths' => [__DIR__.'/../templates' => 'JanalisCollaborate']]);
        $container->extension('doctrine', [
            'orm' => [
                'mappings' => [
                    'JanalisCollaborate' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__.'/Entity',
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
        $parameters = [$prefix => $values];
        if (array_is_list($values)) {
            return $parameters;
        }
        foreach ($values as $key => $value) {
            $name = $prefix.'.'.$key;
            if (\is_array($value) && [] !== $value && !array_is_list($value)) {
                $parameters += $this->flatten($name, $value);

                continue;
            }
            $parameters[$name] = $value;
        }

        return $parameters;
    }
}
