<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Janalis\Collaborate\Hub\HubPublisher;

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
};
