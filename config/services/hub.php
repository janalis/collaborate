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
        service('janalis_collaborate.places')->nullOnInvalid(),
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
            [
                'regions' => param('collaborate.cursors.regions'),
                'keyAttribute' => param('collaborate.cursors.key_attribute'),
                'keyScope' => param('collaborate.cursors.key_scope'),
                'anchorAttributes' => param('collaborate.cursors.anchor_attributes'),
                'anchorClasses' => param('collaborate.cursors.anchor_classes'),
                'caretRegions' => param('collaborate.cursors.caret_regions'),
                'popups' => param('collaborate.cursors.popups'),
                'idle' => param('collaborate.cursors.idle'),
                'markers' => param('collaborate.cursors.markers'),
                'edges' => param('collaborate.cursors.edges'),
                'topLayer' => param('collaborate.cursors.top_layer'),
                'presence' => param('collaborate.cursors.presence'),
                'distinctColors' => param('collaborate.cursors.distinct_colors'),
                'shareStream' => param('collaborate.cursors.share_stream'),
                'silentAlone' => param('collaborate.cursors.silent_alone'),
            ],
            service('translator')->nullOnInvalid(),
        ])
        ->tag('twig.extension');
};
