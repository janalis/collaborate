<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Janalis\Collaborate\Command\HubServeCommand;
use Janalis\Collaborate\Cursor\CursorEvents;
use Janalis\Collaborate\Hub\Transport\HubTransportInterface;
use Janalis\Collaborate\Hub\Transport\PresenceProbeInterface;
use Janalis\Collaborate\Hub\Transport\WebSocket\Keys;
use Janalis\Collaborate\Hub\Transport\WebSocket\RequestSigner;
use Janalis\Collaborate\Hub\Transport\WebSocket\SubscriberToken;
use Janalis\Collaborate\Hub\Transport\WebSocket\WebSocketTransport;

/**
 * `collaborate.hub.transport: websocket`. Keys' arguments, the secrets, are set by CollaborateBundle: they are
 * no container parameters. Nothing here names a ReactPHP type: the server is built by the command it runs in.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(Keys::class);
    $services->set(RequestSigner::class)->args([service(Keys::class)]);
    $services->set(SubscriberToken::class)->args([
        service(Keys::class),
        param('collaborate.hub.websocket.public_url'),
        param('collaborate.hub.topic_prefix'),
        param('collaborate.hub.subscription_ttl'),
        param('collaborate.hub.max_drafts'),
    ]);
    $services->set(WebSocketTransport::class)->args([
        service('http_client'),
        service(RequestSigner::class),
        service(SubscriberToken::class),
        param('collaborate.hub.websocket.public_url'),
        param('collaborate.hub.websocket.publish_url'),
        param('collaborate.hub.websocket.publish_socket'),
        service('logger'),
        service('clock'),
    ])->tag('monolog.logger', ['channel' => 'collaborate']);
    $services->alias(HubTransportInterface::class, WebSocketTransport::class);
    $services->alias(PresenceProbeInterface::class, WebSocketTransport::class);

    $services
        ->set(HubServeCommand::class)
        ->args([
            service(SubscriberToken::class),
            service(RequestSigner::class),
            param('collaborate.hub.topic_prefix'),
            service(CursorEvents::class),
            param('collaborate.cursors.enabled'),
            param('collaborate.cursors.max_event_bytes'),
            param('collaborate.cursors.throttle'),
            param('collaborate.hub.websocket'),
            service('logger'),
        ])
        ->tag('console.command')->tag('monolog.logger', ['channel' => 'collaborate']);
};
