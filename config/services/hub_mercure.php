<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Janalis\Collaborate\Hub\Transport\HubTransportInterface;
use Janalis\Collaborate\Hub\Transport\Mercure\MercureTransport;
use Janalis\Collaborate\Hub\Transport\PresenceProbeInterface;

/** `collaborate.hub.transport: mercure`: janalis_collaborate.mercure_hub is aliased to mercure.hub.<name>. */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(MercureTransport::class)->args([
        service('janalis_collaborate.mercure_hub'),
        service('logger'),
        service('http_client')->nullOnInvalid(),
        param('collaborate.hub.subscription_ttl'),
    ])->tag('monolog.logger', ['channel' => 'collaborate']);
    $services->alias(HubTransportInterface::class, MercureTransport::class);
    $services->alias(PresenceProbeInterface::class, MercureTransport::class);
};
