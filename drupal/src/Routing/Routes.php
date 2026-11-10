<?php

declare(strict_types=1);

namespace Drupal\collaborate\Routing;

use Drupal\collaborate\Controller\NotificationPages;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Janalis\Collaborate\Controller\CursorController;
use Janalis\Collaborate\Controller\DraftController;
use Janalis\Collaborate\Controller\HubController;
use Janalis\Collaborate\Controller\NotificationController;
use Janalis\Collaborate\Controller\PushController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The bundle's routes, named as its scripts and templates name them, under `/admin`: the hub's session, the cursor
 * events, the draft actions, and the notifications' when they are on. Each controller checks its user, its token
 * and its grant itself, as it does in a Symfony application.
 */
final readonly class Routes implements ContainerInjectionInterface
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    public static function create(ContainerInterface $container): self
    {
        return new self($container);
    }

    public function routes(): RouteCollection
    {
        $routes = new RouteCollection();
        $add = static function (
            string $name,
            string $path,
            string $controller,
            array $methods,
            array $requirements = [],
        ) use ($routes): void {
            $routes->add(
                $name,
                new Route(
                    '/admin' . $path,
                    ['_controller' => $controller],
                    ['_access' => 'TRUE'] + $requirements,
                    // The controllers answer JSON and never a cached page.
                    ['no_cache' => true],
                    methods: $methods,
                ),
            );
        };
        $add('collaborate_session', '/cursors/session', HubController::class . '::__invoke', ['POST']);
        $add('collaborate_cursor_events', '/cursors/events', CursorController::class . '::__invoke', ['POST']);
        $add(
            'collaborate_draft',
            '/collaborate/{action}',
            DraftController::class . '::handle',
            ['POST'],
            [
                'action' => 'join|replay|append|discard|reconcile|report',
            ],
        );
        if (!$this->container->has(NotificationController::class)) {
            return $routes;
        }
        $notifications = NotificationController::class;
        $add('collaborate_notifications', '/notifications', $notifications . '::list', ['GET']);
        // The two pages, in the administration theme's page; their own requests answered by the bundle.
        $add('collaborate_notifications_page', '/notifications/all', NotificationPages::class . '::page', ['GET']);
        $add(
            'collaborate_notifications_change',
            '/notifications/{action}',
            $notifications . '::change',
            ['POST'],
            [
                'action' => 'read|unread|clear',
            ],
        );
        $add(
            'collaborate_notification_topics',
            '/notifications/topics',
            NotificationPages::class . '::topics',
            ['GET'],
        );
        $add(
            'collaborate_notification_topic',
            '/notifications/topics/{topic}/{action}',
            $notifications . '::choose',
            ['POST'],
            [
                'topic' => '[a-z][a-z0-9_-]{0,63}',
                'action' => 'subscribe|unsubscribe',
            ],
        );
        if ($this->container->has(PushController::class)) {
            $add(
                'collaborate_push_worker',
                '/notifications/push/worker.js',
                PushController::class . '::worker',
                ['GET'],
            );
            $add(
                'collaborate_push_subscription',
                '/notifications/push/{action}',
                PushController::class . '::subscription',
                ['POST'],
                [
                    'action' => 'subscribe|unsubscribe',
                ],
            );
        }

        return $routes;
    }
}
