<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Janalis\Collaborate\Controller\CursorController;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Registered whatever `cursors.enabled` says, so its route resolves to a 404 rather than a broken controller.
    $services
        ->set(CursorController::class)
        ->args([
            service(HubGuard::class),
            service(CollaboratorProviderInterface::class),
            service(HubPublisher::class),
            param('collaborate.cursors.enabled'),
            param('collaborate.colors'),
            param('collaborate.cursors.max_event_bytes'),
            param('collaborate.cursors.regions'),
            param('collaborate.cursors.popups'),
            param('collaborate.cursors.caret_regions'),
        ])
        ->tag('controller.service_arguments');
};
