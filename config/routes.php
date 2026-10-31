<?php

declare(strict_types=1);

namespace Symfony\Component\Routing\Loader\Configurator;

use Janalis\Collaborate\Controller\CursorController;
use Janalis\Collaborate\Controller\HubController;

/*
 * Imported by the application under its own prefix, e.g.:
 *
 *     $routes->import('@CollaborateBundle/config/routes.php')->prefix('/{_locale}/admin');
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('collaborate_session', '/cursors/session')->controller(HubController::class)->methods(['POST']);

    $routes
        ->add('collaborate_cursor_events', '/cursors/events')
        ->controller(CursorController::class)
        ->methods(['POST']);

    $routes
        ->add('collaborate_draft', '/collaborate/{action}')
        ->controller('Janalis\Collaborate\Controller\DraftController::handle')
        ->requirements(['action' => 'join|replay|append|discard|reconcile'])
        ->methods(['POST']);
};
