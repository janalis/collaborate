<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Janalis\Collaborate\Command\PushSetupCommand;
use Janalis\Collaborate\Controller\NotificationController;
use Janalis\Collaborate\Controller\PushController;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Notification\NotificationAccess;
use Janalis\Collaborate\Notification\NotificationCatalog;
use Janalis\Collaborate\Notification\NotificationRepository;
use Janalis\Collaborate\Notification\Notifier;
use Janalis\Collaborate\Notification\Preferences;
use Janalis\Collaborate\Notification\Push\PushDispatcher;
use Janalis\Collaborate\Notification\Push\PushEndpoints;
use Janalis\Collaborate\Notification\Push\PushSubscriptions;
use Janalis\Collaborate\Security\CurrentUserInterface;
use Janalis\Collaborate\Twig\NotificationRuntime;

/*
 * Notifications, registered whatever `notifications.enabled` says: while it is off, the routes answer 404, the
 * hub refuses their topic and `collaborate_notifications()` renders nothing. Web push and the Messenger handlers
 * are imported by CollaborateBundle::loadExtension() only when they are on.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(NotificationAccess::class)->args([
        service(CurrentUserInterface::class),
        service(CollaboratorProviderInterface::class),
        param('collaborate.notifications.enabled'),
        param('collaborate.notifications.role'),
        // Aliased by CollaborateBundle::loadExtension() when `notifications.stream_guard` names a service.
        service('janalis_collaborate.notifications.stream_guard')->nullOnInvalid(),
    ]);

    $services->set(NotificationCatalog::class)->args([
        param('collaborate.notifications.topics'),
        param('collaborate.notifications.types'),
    ]);
    $services->set(NotificationRepository::class)->args([service('doctrine.orm.entity_manager')]);
    $services->set(Preferences::class)->args([
        service('doctrine.orm.entity_manager'),
        service(NotificationCatalog::class),
        service('clock'),
    ]);
    $services->set(PushSubscriptions::class)->args([service('doctrine.orm.entity_manager'), service('clock')]);

    $services
        ->set(Notifier::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(NotificationCatalog::class),
            service(Preferences::class),
            service(NotificationRepository::class),
            service(CollaboratorProviderInterface::class),
            service(HubPublisher::class),
            service(Topics::class),
            service('clock'),
            // Registered by notifications_push.php when web push is on.
            service(PushDispatcher::class)->nullOnInvalid(),
            // Aliased by CollaborateBundle::loadExtension() when `notifications.bus` names a bus.
            service('janalis_collaborate.notifications.bus')->nullOnInvalid(),
        ])
        ->public();

    $services
        ->set(NotificationController::class)
        ->args([
            service(NotificationAccess::class),
            service(NotificationRepository::class),
            service(NotificationCatalog::class),
            service(Preferences::class),
            service(Notifier::class),
            service('twig'),
            service('router'),
            service('security.csrf.token_manager'),
            service('clock'),
            param('collaborate.notifications.latest'),
            param('collaborate.notifications.page_size'),
            param('collaborate.notifications.page_template'),
            param('collaborate.notifications.topics_template'),
        ])
        ->tag('controller.service_arguments');

    $services->set(PushEndpoints::class)->args([param('collaborate.notifications.web_push.endpoint_hosts')]);

    $services
        ->set(PushController::class)
        ->args([
            service(NotificationAccess::class),
            service(PushSubscriptions::class),
            service('security.csrf.token_manager'),
            service('router'),
            __DIR__ . '/../../assets/notifications/push-worker.js',
            param('collaborate.notifications.web_push.enabled'),
            param('collaborate.notifications.web_push.icon'),
            service('translator')->nullOnInvalid(),
            service(PushEndpoints::class),
        ])
        ->tag('controller.service_arguments');

    $services
        ->set(NotificationRuntime::class)
        ->args([
            service(NotificationAccess::class),
            service(NotificationRepository::class),
            service(NotificationCatalog::class),
            service('router'),
            service('security.csrf.token_manager'),
            param('collaborate.notifications.latest'),
            param('collaborate.notifications.max_count'),
            param('collaborate.notifications.web_push.enabled'),
            param('collaborate.notifications.web_push.public_key'),
            param('collaborate.notifications.web_push.service_worker'),
            service('translator')->nullOnInvalid(),
            param('collaborate.notifications.layout'),
        ])
        ->tag('twig.runtime');

    $services
        ->set(PushSetupCommand::class)
        ->args([
            param('kernel.project_dir'),
            param('collaborate.notifications.web_push.enabled'),
            param('collaborate.notifications.web_push.public_key'),
            param('collaborate.notifications.web_push.private_key'),
            param('collaborate.notifications.web_push.subject'),
            service('secrets.vault')->nullOnInvalid(),
            service(PushSubscriptions::class)->nullOnInvalid(),
        ])
        ->tag('console.command');
};
