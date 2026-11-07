<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Notification\NotificationCatalog;
use Janalis\Collaborate\Notification\NotificationRepository;
use Janalis\Collaborate\Notification\Push\OnlineRecipients;
use Janalis\Collaborate\Notification\Push\PushDispatcher;
use Janalis\Collaborate\Notification\Push\PushPayload;
use Janalis\Collaborate\Notification\Push\PushSenderInterface;
use Janalis\Collaborate\Notification\Push\PushSubscriptions;
use Janalis\Collaborate\Notification\Push\WebPushSender;

/* Web push on: imported by CollaborateBundle::loadExtension(). */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // A host replaces it by defining PushSenderInterface itself; minishlink/web-push is then not needed.
    $services->set(WebPushSender::class)->args([
        param('collaborate.notifications.web_push.public_key'),
        param('collaborate.notifications.web_push.private_key'),
        param('collaborate.notifications.web_push.subject'),
        service('logger'),
        param('collaborate.notifications.web_push.ttl'),
        param('collaborate.notifications.web_push.urgency'),
    ])->tag('kernel.reset', ['method' => 'reset'])->tag('monolog.logger', ['channel' => 'collaborate']);
    $services->alias(PushSenderInterface::class, WebPushSender::class);

    $services->set(PushPayload::class)->args([
        service(NotificationCatalog::class),
        param('kernel.default_locale'),
        param('collaborate.notifications.web_push.title'),
        param('collaborate.notifications.web_push.icon'),
        param('collaborate.notifications.web_push.payload'),
        service('translator')->nullOnInvalid(),
        service('janalis_collaborate.notifications.locales')->nullOnInvalid(),
    ]);

    $services->set(OnlineRecipients::class)->args([
        service('janalis_collaborate.hub'),
        service(Topics::class),
        service('logger'),
        service('http_client')->nullOnInvalid(),
        param('collaborate.notifications.web_push.skip_online'),
    ])->tag('monolog.logger', ['channel' => 'collaborate']);

    $services
        ->set(PushDispatcher::class)
        ->args([
            service(PushSubscriptions::class),
            service(NotificationRepository::class),
            service(PushSenderInterface::class),
            service(PushPayload::class),
            service(OnlineRecipients::class),
            service('janalis_collaborate.notifications.bus')->nullOnInvalid(),
            param('collaborate.notifications.web_push.enabled'),
        ])
        ->tag('kernel.event_subscriber')->tag('kernel.reset', ['method' => 'reset']);
};
