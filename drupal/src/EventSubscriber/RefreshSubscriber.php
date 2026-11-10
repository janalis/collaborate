<?php

declare(strict_types=1);

namespace Drupal\collaborate\EventSubscriber;

use Drupal\collaborate\Bridge\EntityForms;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers a page's refresh (`X-Collaborate-Refresh: 1`, a GET of its form's action) with the configuration its
 * form was given and its new tokens, instead of the page: the scripts take a new grant without reloading.
 */
final class RefreshSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -100]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (
            !$event->isMainRequest()
            || '1' !== $request->headers->get('X-Collaborate-Refresh')
            || !$request->isMethodSafe()
        ) {
            return;
        }
        $refresh = $request->attributes->get(EntityForms::REFRESH);
        $event->setResponse(new JsonResponse(
            [
                'configuration' => \is_array($refresh) ? $refresh['configuration'] ?? null : null,
                'tokens' => \is_array($refresh) ? $refresh['tokens'] ?? [] : [],
            ],
            200,
            ['Cache-Control' => 'no-store'],
        ));
    }
}
