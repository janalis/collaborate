<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Janalis\Collaborate\Notification\Messenger\SendNotification;
use Janalis\Collaborate\Notification\Messenger\SendNotificationHandler;
use Janalis\Collaborate\Notification\Notifier;

/* The handler of the message notify() sends through a bus: imported when Messenger is installed. */
return static function (ContainerConfigurator $container): void {
    $container
        ->services()
        ->set(SendNotificationHandler::class)
        ->args([service(Notifier::class)])
        ->tag('messenger.message_handler', ['handles' => SendNotification::class]);
};
