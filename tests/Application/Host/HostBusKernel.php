<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Host;

use Janalis\Collaborate\Notification\Messenger\SendNotification;
use Janalis\Collaborate\Notification\Messenger\SendWebPush;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

/**
 * The library whose notifications go through Messenger: notify() dispatches, a worker writes, and each push is
 * a message of its own, on an in-memory transport the tests consume.
 */
final class HostBusKernel extends HostKernel
{
    public const string TRANSPORT = 'notifications';

    #[\Override]
    public static function app(): string
    {
        return 'hostbus';
    }

    #[\Override]
    protected function configureApp(ContainerConfigurator $container, ServicesConfigurator $services): void
    {
        parent::configureApp($container, $services);
        $container->extension('framework', [
            'messenger' => [
                'transports' => [self::TRANSPORT => 'in-memory://'],
                'routing' => [SendNotification::class => self::TRANSPORT, SendWebPush::class => self::TRANSPORT],
            ],
        ]);
        $container->extension('collaborate', ['notifications' => ['bus' => 'messenger.default_bus']]);
    }
}
