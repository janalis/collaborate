<?php

declare(strict_types=1);

namespace Symfony\Component\Routing\Loader\Configurator;

use Janalis\Collaborate\Controller\NotificationController;
use Janalis\Collaborate\Controller\PushController;

/*
 * The notifications, imported by the application under the prefix it wants them at, e.g.:
 *
 *     $routes->import('@CollaborateBundle/config/routes/notifications.php');
 *
 * Every route answers 404 while `notifications.enabled` (or, for web push, `notifications.web_push.enabled`)
 * is off.
 */
return static function (RoutingConfigurator $routes): void {
    $routes
        ->add('collaborate_notifications', '/notifications')
        ->controller([NotificationController::class, 'list'])
        ->methods(['GET']);

    $routes
        ->add('collaborate_notifications_page', '/notifications/all')
        ->controller([NotificationController::class, 'page'])
        ->methods(['GET']);

    $routes
        ->add('collaborate_notifications_change', '/notifications/{action}')
        ->controller([NotificationController::class, 'change'])
        ->requirements(['action' => 'read|unread|clear'])
        ->methods(['POST']);

    $routes
        ->add('collaborate_notification_topics', '/notifications/topics')
        ->controller([NotificationController::class, 'topics'])
        ->methods(['GET']);

    $routes
        ->add('collaborate_notification_topic', '/notifications/topics/{topic}/{action}')
        ->controller([NotificationController::class, 'choose'])
        ->requirements(['topic' => '[a-z][a-z0-9_-]{0,63}', 'action' => 'subscribe|unsubscribe'])
        ->methods(['POST']);

    $routes
        ->add('collaborate_push_worker', '/notifications/push/worker.js')
        ->controller([PushController::class, 'worker'])
        ->methods(['GET']);

    $routes
        ->add('collaborate_push_subscription', '/notifications/push/{action}')
        ->controller([PushController::class, 'subscription'])
        ->requirements(['action' => 'subscribe|unsubscribe'])
        ->methods(['POST']);
};
