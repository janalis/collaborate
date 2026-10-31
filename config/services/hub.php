<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Janalis\Collaborate\Controller\HubController;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\RenewSubscriber;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Twig\CollaborateExtension;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set(HubPublisher::class)
        ->args([
            service('janalis_collaborate.hub'),
            service('logger'),
            service('clock'),
            param('collaborate.hub.publish_pause'),
        ])
        ->tag('kernel.event_subscriber')
        ->tag('kernel.reset', ['method' => 'reset'])
        ->tag('monolog.logger', ['channel' => 'collaborate']);

    // janalis_collaborate.limiter.cursors is aliased by CollaborateBundle::loadExtension() when configured.
    $services->set(HubGuard::class)->args([
        service('security.helper'),
        service(CollaboratorProviderInterface::class),
        service(PageGrant::class),
        service('security.csrf.token_manager'),
        param('collaborate.role'),
        service('janalis_collaborate.limiter.cursors')->nullOnInvalid(),
    ]);

    $services
        ->set(HubController::class)
        ->args([
            service(HubGuard::class),
            service(DraftAccess::class),
            service(Topics::class),
            service('janalis_collaborate.hub'),
            param('collaborate.updates.enabled'),
            param('collaborate.hub.max_drafts'),
            param('collaborate.hub.subscription_ttl'),
        ])
        ->tag('controller.service_arguments');

    $services->set(RenewSubscriber::class)->tag('kernel.event_subscriber');

    $services
        ->set(CollaborateExtension::class)
        ->args([
            service('request_stack'),
            service('security.helper'),
            service(CollaboratorProviderInterface::class),
            service(PageGrant::class),
            service(DraftAccess::class),
            param('collaborate.role'),
            [
                'cursors' => param('collaborate.cursors.enabled'),
                'updates' => param('collaborate.updates.enabled'),
                'throttle' => param('collaborate.cursors.throttle'),
                'heartbeat' => param('collaborate.cursors.heartbeat'),
                'expiry' => param('collaborate.cursors.expiry'),
                'maxAvatars' => param('collaborate.cursors.max_avatars'),
                'renew' => param('collaborate.hub.renew_interval'),
                'colors' => param('collaborate.colors'),
            ],
        ])
        ->tag('twig.extension');
};
