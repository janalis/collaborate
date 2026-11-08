<?php

declare(strict_types=1);

namespace Janalis\Collaborate\DependencyInjection;

use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Notification\Push\PushEndpoints;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;

/**
 * The `collaborate` configuration tree: each option with its default, its bounds and the checks its value
 * passes. {@see CollaborateBundle} describes its configuration with it and keeps the defaults its code
 * reads elsewhere, such as the colours a user's colour is picked from.
 *
 * @internal
 */
final class Configuration
{
    /** A region or popup kind, or an attribute name. */
    private const string NAME = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';

    /** A topic or notification type: what a database column of 64 characters holds. */
    private const string KEY = '/^[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*$/D';

    public static function define(DefinitionConfigurator $definition): void
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
            ->defaultValue(CollaborateBundle::COLORS)
            ->requiresAtLeastOneElement()
            ->end()
            ->arrayNode('hub')
            ->addDefaultsIfNotSet()
            ->beforeNormalization()
            ->ifTrue(static fn(mixed $hub): bool => \is_array($hub) && \array_key_exists('name', $hub))
            ->then(static function (array $hub): array {
                if (\is_array($hub['mercure'] ?? null) && \array_key_exists('name', $hub['mercure'])) {
                    throw new \InvalidArgumentException(
                        'Set "collaborate.hub.mercure.name" alone, not "collaborate.hub.name" too.',
                    );
                }
                trigger_deprecation(
                    'janalis/collaborate',
                    '0.4',
                    'The "collaborate.hub.name" option is deprecated, use "collaborate.hub.mercure.name" instead.',
                );
                $hub['mercure'] =
                    ['name' => $hub['name']] + (\is_array($hub['mercure'] ?? null) ? $hub['mercure'] : []);
                unset($hub['name']);

                return $hub;
            })
            ->end()
            ->validate()
            ->ifTrue(
                /** @param array{renew_interval: int, subscription_ttl: int} $hub integer nodes, validated already */
                static fn(array $hub): bool => $hub['renew_interval'] >= $hub['subscription_ttl'],
            )
            ->thenInvalid('collaborate.hub.renew_interval must be shorter than collaborate.hub.subscription_ttl.')
            ->end()
            ->validate()
            ->ifTrue(
                static fn(array $hub): bool => (
                    'websocket' === ($hub['transport'] ?? null)
                    && null === ($hub['websocket']['public_url'] ?? null)
                ),
            )
            ->thenInvalid('The websocket transport needs collaborate.hub.websocket.public_url.')
            ->end()
            ->validate()
            ->ifTrue(
                static fn(array $hub): bool => (
                    'websocket' === ($hub['transport'] ?? null)
                    && null === ($hub['websocket']['secret'] ?? null)
                ),
            )
            ->thenInvalid(
                'The websocket transport needs collaborate.hub.websocket.secret, a dedicated secret of 32 bytes or more.',
            )
            ->end()
            ->children()
            ->enumNode('transport')
            ->info(
                'How notices reach the pages: a Mercure hub, or the bundle\'s WebSocket server (collaborate:hub:serve). Read when the container is compiled.',
            )
            ->values(['mercure', 'websocket'])
            ->defaultValue('mercure')
            ->end()
            ->arrayNode('mercure')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('name')
            ->info('Mercure hub name (mercure.hubs.<name>).')
            ->defaultValue('default')
            ->cannotBeEmpty()
            ->end()
            ->end()
            ->end()
            ->arrayNode('websocket')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('public_url')
            ->info(
                'Where browsers connect: a path on the application\'s origin behind its reverse proxy (recommended), or a ws:// or wss:// URL.',
            )
            ->defaultNull()
            ->end()
            ->scalarNode('path')
            ->info('Path the server accepts upgrades on; null for the path of public_url.')
            ->defaultNull()
            ->end()
            ->scalarNode('publish_url')
            ->info('Where the application publishes, on the server\'s private side.')
            ->defaultValue('http://127.0.0.1:8091')
            ->end()
            ->scalarNode('publish_socket')
            ->info(
                'Unix socket the application publishes through (curl), in place of publish_url\'s host; null for TCP.',
            )
            ->defaultNull()
            ->end()
            ->scalarNode('listen')
            ->info('Browser-facing address of collaborate:hub:serve: host:port, or unix:///path.')
            ->defaultValue('127.0.0.1:8090')
            ->end()
            ->scalarNode('publish_listen')
            ->info(
                'Private publishing address of collaborate:hub:serve: host:port (loopback unless --allow-remote-publish), or unix:///path.',
            )
            ->defaultValue('127.0.0.1:8091')
            ->end()
            ->integerNode('socket_mode')
            ->info('Permissions of the unix sockets the server creates.')
            ->defaultValue(0o660)
            ->min(0o600)
            ->max(0o777)
            ->end()
            ->scalarNode('secret')
            ->info(
                'Dedicated secret (32 bytes or more) the subscriber tokens and the publish requests are signed with.',
            )
            ->defaultNull()
            ->end()
            ->scalarNode('previous_secret')
            ->info('The secret being rotated out: the server still accepts what it signed.')
            ->defaultNull()
            ->end()
            ->arrayNode('allowed_origins')
            ->info(
                'Origins the server accepts at the handshake on top of the one each token names; empty for the token\'s alone.',
            )
            ->scalarPrototype()
            ->end()
            ->end()
            ->arrayNode('trusted_proxies')
            ->info(
                'Proxies (IPs, CIDRs or PRIVATE_SUBNETS) whose forwarding headers name the client address the limits count.',
            )
            ->scalarPrototype()
            ->end()
            ->end()
            ->booleanNode('cursor_events')
            ->info(
                'Pages send their pointer, caret and presence over their socket, which the server stamps and relays at once, in place of a request to the application each. Off, they post them to the application, which checks each against the page grant.',
            )
            ->defaultTrue()
            ->end()
            ->integerNode('ping_interval')
            ->info('Seconds between pings: under the idle timeout of every proxy in front of the server.')
            ->defaultValue(25)
            ->min(5)
            ->end()
            ->integerNode('max_connections')
            ->info(
                'Connections in all. Past 1000, the server needs ext-ev or ext-event: stream_select() handles no more.',
            )
            ->defaultValue(1000)
            ->min(1)
            ->end()
            ->integerNode('max_connections_per_ip')
            ->info('0 for no limit; ignored when every client comes through one untrusted proxy.')
            ->defaultValue(0)
            ->min(0)
            ->end()
            ->integerNode('max_connections_per_user')
            ->defaultValue(50)
            ->min(1)
            ->end()
            ->integerNode('max_pending')
            ->info('Connections that have not subscribed yet.')
            ->defaultValue(1000)
            ->min(1)
            ->end()
            ->integerNode('max_message_size')
            ->info('Largest message a browser may send, bytes.')
            ->defaultValue(16_384)
            ->min(1024)
            ->end()
            ->integerNode('max_buffer')
            ->info('Bytes queued for a slow client before it is dropped (it catches up when it reconnects).')
            ->defaultValue(1_048_576)
            ->min(65_536)
            ->end()
            ->integerNode('shutdown_timeout')
            ->info('Seconds the server waits for clients to close on SIGTERM.')
            ->defaultValue(10)
            ->min(0)
            ->end()
            ->end()
            ->end()
            ->scalarNode('topic_prefix')
            ->info('Prefix of every topic: <prefix>cursors:<room>, <prefix>draft:<id>, <prefix>updates.')
            ->defaultValue('urn:collaborate:')
            ->end()
            ->integerNode('subscription_ttl')
            ->info('Subscriber token lifetime, seconds.')
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
                'Service id of a Janalis\Collaborate\Cursor\PlaceProviderInterface naming the room of a page, or null for its route, parameters and query.',
            )
            ->defaultNull()
            ->end()
            ->arrayNode('ignored_parameters')
            ->info('Route and query parameters that do not separate rooms (without a place provider).')
            ->scalarPrototype()
            ->end()
            ->defaultValue(CollaborateBundle::IGNORED_PARAMETERS)
            ->end()
            ->arrayNode('regions')
            ->info(
                'The regions of a page, name => selector, the first one an element is in naming it: a pointer is placed from the root of its region on the peers\' pages.',
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
            ->defaultValue(CollaborateBundle::REGIONS)
            ->end()
            ->scalarNode('key_attribute')
            ->info(
                'Attribute naming a record wherever a page shows it (e.g. data-key): a pointer on it is placed from the record, whatever element shows it; null for none.',
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
            ->defaultValue(CollaborateBundle::ANCHOR_ATTRIBUTES)
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
                'Kinds of popup a peer may have open on a record (data-collaborate-popup="<kind>" beside the key attribute), each with what the presence menu says of a peer in it and the line under its title, both translated in the collaborate domain.',
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
            ->defaultValue('in %%name%%')
            ->end()
            ->scalarNode('line')
            ->info('The line under the popup\'s title, %names% naming the peers.')
            ->defaultValue('Also open by %%names%%.')
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
                'Draw a peer in the colour farthest from the others when an earlier one has theirs or one beside it in the colours list; otherwise in the colour the server picks.',
            )
            ->defaultFalse()
            ->end()
            ->booleanNode('silent_alone')
            ->info('Send no pointer while nobody else is on the page: a peer who comes says hello and is answered.')
            ->defaultFalse()
            ->end()
            ->booleanNode('share_stream')
            ->info(
                'Over HTTP/1.1, let one tab of a browser share its cursor at a time (Web Locks): each stream holds one of the six connections a browser opens to a site.',
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
            ->defaultValue(CollaborateBundle::SENSITIVE_FIELDS)
            ->end()
            ->end()
            ->end()
            ->append(self::notifications())
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

    /** The `notifications` branch: the bell, its topics and types, Messenger and web push. */
    private static function notifications(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('notifications');
        $node
            ->info(
                'Notifications: the bell of every page, the page of them all, the topics a user subscribes to, web push.',
            )
            ->canBeEnabled()
            ->validate()
            ->ifTrue(static fn(array $branch): bool => [] !== self::unknownTopics($branch))
            ->then(static function (array $branch): never {
                throw new \InvalidArgumentException(\sprintf('Every notification type names a configured topic: %s.', implode(
                    ', ',
                    self::unknownTopics($branch),
                )));
            })
            ->end()
            ->children()
            ->scalarNode('role')
            ->info('Role a user needs to be notified, see the bell and subscribe; null for collaborate.role.')
            ->defaultNull()
            ->end()
            ->append(self::topics())
            ->append(self::types())
            ->integerNode('latest')
            ->info('Notifications the bell lists.')
            ->defaultValue(20)
            ->min(1)
            ->end()
            ->integerNode('page_size')
            ->info('Notifications the page lists at a time.')
            ->defaultValue(30)
            ->min(1)
            ->end()
            ->integerNode('max_count')
            ->info('Count above which the bell shows N+.')
            ->defaultValue(99)
            ->min(1)
            ->end()
            ->scalarNode('layout')
            ->info(
                'Template the bundle\'s pages of the notifications and of the topics extend, filling its `body` block.',
            )
            ->defaultValue('base.html.twig')
            ->end()
            ->scalarNode('page_template')
            ->info(
                'Template of the page of all notifications; the host\'s own includes @JanalisCollaborate/notifications/_page.html.twig where its layout wants it.',
            )
            ->defaultValue('@JanalisCollaborate/notifications/page.html.twig')
            ->end()
            ->scalarNode('topics_template')
            ->info(
                'Template of the page of the topics; the host\'s own includes @JanalisCollaborate/notifications/_topics.html.twig.',
            )
            ->defaultValue('@JanalisCollaborate/notifications/topics.html.twig')
            ->end()
            ->scalarNode('locales')
            ->info(
                'Service id of a Janalis\\Collaborate\\Notification\\RecipientLocaleProviderInterface: the language a push is written in.',
            )
            ->defaultNull()
            ->end()
            ->scalarNode('stream_guard')
            ->info(
                'Service id of a Janalis\\Collaborate\\Notification\\StreamGuardInterface: whether the pages may hear of their notifications over the hub.',
            )
            ->defaultNull()
            ->end()
            ->scalarNode('bus')
            ->info('Messenger bus service id: notify() then dispatches a message per recipient rather than writing.')
            ->defaultNull()
            ->end()
            ->append(self::webPush())
            ->end();

        return $node;
    }

    private static function topics(): ArrayNodeDefinition
    {
        $node = self::named('topics', 'What a user subscribes to, name => its label and default.');
        $node
            ->arrayPrototype()
            ->children()
            ->scalarNode('label')
            ->isRequired()
            ->cannotBeEmpty()
            ->end()
            ->scalarNode('description')
            ->defaultNull()
            ->end()
            ->booleanNode('default')
            ->info('Whether a user who never chose is subscribed.')
            ->defaultTrue()
            ->end()
            ->booleanNode('mandatory')
            ->info('Whether a user may not unsubscribe.')
            ->defaultFalse()
            ->end()
            ->scalarNode('domain')
            ->info('Translation domain of the label and description.')
            ->defaultValue('messages')
            ->end()
            ->end()
            ->end();

        return $node;
    }

    private static function types(): ArrayNodeDefinition
    {
        $node = self::named('types', 'What a notification says, name => its topic, sentence, label and icon.');
        $node
            ->arrayPrototype()
            ->children()
            ->scalarNode('topic')
            ->isRequired()
            ->cannotBeEmpty()
            ->end()
            ->scalarNode('sentence')
            ->info('Translation key whose placeholders the parameters of a notification fill (a % is written %%).')
            ->isRequired()
            ->cannotBeEmpty()
            ->end()
            ->scalarNode('label')
            ->info('Name the page of the notifications filters the type by.')
            ->defaultNull()
            ->end()
            ->scalarNode('icon')
            ->info('Classes of the icon a notification is shown with, or null.')
            ->defaultNull()
            ->end()
            ->scalarNode('domain')
            ->info('Translation domain of the sentence and label.')
            ->defaultValue('messages')
            ->end()
            ->end()
            ->end();

        return $node;
    }

    private static function webPush(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('web_push');
        $node
            ->info('Web push: a system notification when no page of the application is open.')
            ->canBeEnabled()
            ->validate()
            ->ifTrue(
                static fn(array $push): bool => (
                    true === $push['enabled']
                    && (
                        !\is_string($push['public_key'] ?? null)
                        || '' === $push['public_key']
                        || !\is_string($push['private_key'] ?? null)
                        || '' === $push['private_key']
                        || !\is_string($push['subject'] ?? null)
                        || '' === $push['subject']
                    )
                ),
            )
            ->thenInvalid('Web push needs a VAPID public key, private key and subject (collaborate:push:setup): %s')
            ->end()
            ->children()
            ->scalarNode('public_key')
            ->info('VAPID public key, base64url.')
            ->defaultNull()
            ->end()
            ->scalarNode('private_key')
            ->info('VAPID private key, base64url.')
            ->defaultNull()
            ->end()
            ->scalarNode('subject')
            ->info('VAPID subject: a mailto: or https: URL push services may contact.')
            ->defaultNull()
            ->end()
            ->integerNode('ttl')
            ->info('Seconds a push service keeps a push for an offline browser.')
            ->defaultValue(86_400)
            ->min(0)
            ->end()
            ->enumNode('urgency')
            ->values(['very-low', 'low', 'normal', 'high'])
            ->defaultValue('normal')
            ->end()
            ->scalarNode('icon')
            ->info('URL of the icon of the system notification, or null.')
            ->defaultNull()
            ->end()
            ->scalarNode('title')
            ->info('Title of the system notification, a key of the collaborate domain.')
            ->defaultValue('New notification')
            ->end()
            ->enumNode('payload')
            ->info(
                'full: the sentence, translated in the language of its reader, encrypted to the browser; none: an empty push, a generic sentence.',
            )
            ->values(['full', 'none'])
            ->defaultValue('full')
            ->end()
            ->booleanNode('skip_online')
            ->info(
                'Push nothing to a user whose page holds the stream of their notifications (the subscriptions API of the hub), even a page that has not the focus; off, the service worker decides, in the page only while one has the focus.',
            )
            ->defaultFalse()
            ->end()
            ->arrayNode('endpoint_hosts')
            ->info(
                'Hosts (and their subdomains) a browser\'s push endpoint may be on: the server posts to every endpoint its users saved.',
            )
            ->scalarPrototype()
            ->cannotBeEmpty()
            ->end()
            ->defaultValue(PushEndpoints::HOSTS)
            ->requiresAtLeastOneElement()
            ->end()
            ->scalarNode('service_worker')
            ->info(
                'URL of the host\'s own service worker, which importScripts() the bundle\'s; null for the bundle\'s.',
            )
            ->defaultNull()
            ->end()
            ->end();

        return $node;
    }

    /** A map whose keys are lowercase words joined by "-" or "_", kept as they are written. */
    private static function named(string $name, string $info): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node
            ->info($info)
            ->useAttributeAsKey('name')
            ->normalizeKeys(false)
            ->performNoDeepMerging()
            ->validate()
            ->ifTrue(
                static fn(array $map): bool => (
                    [] !== array_filter(
                        array_keys($map),
                        static fn(int|string $key): bool => (
                            \strlen((string) $key) > 64
                            || !preg_match(self::KEY, (string) $key)
                        ),
                    )
                ),
            )
            ->thenInvalid(\ucfirst($name) . ' are lowercase words joined by "-" or "_", at most 64 characters: %s')
            ->end();

        return $node;
    }

    /**
     * @param array<array-key, mixed> $branch
     *
     * @return list<array-key> the types whose topic is not configured: names a `named()` map validated as words
     */
    private static function unknownTopics(array $branch): array
    {
        $topics = \is_array($branch['topics'] ?? null) ? array_keys($branch['topics']) : [];
        $types = \is_array($branch['types'] ?? null) ? $branch['types'] : [];
        // A type holds its topic once processed (`isRequired()`); one of another kind than a name is no topic.
        $unknown = array_filter(
            $types,
            static fn(mixed $type): bool => !\in_array(\is_array($type) ? $type['topic'] : null, $topics, true),
        );

        return array_keys($unknown);
    }
}
