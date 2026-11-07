<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Janalis\Collaborate\Notification\Messenger\SendWebPush;
use Janalis\Collaborate\Notification\Messenger\SendWebPushHandler;
use Janalis\Collaborate\Notification\Push\PushDispatcher;

/* The handler of the pushes sent through a bus: imported when Messenger is installed and web push on. */
return static function (ContainerConfigurator $container): void {
    $container
        ->services()
        ->set(SendWebPushHandler::class)
        ->args([service(PushDispatcher::class)])
        ->tag('messenger.message_handler', ['handles' => SendWebPush::class]);
};
