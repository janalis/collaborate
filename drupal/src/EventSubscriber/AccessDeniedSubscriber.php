<?php

declare(strict_types=1);

namespace Drupal\collaborate\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The bundle refuses with the security component's exception, which a Symfony firewall turns into a 403 or a
 * sign-in: Drupal knows none, and answers it as Drupal answers an access denied.
 */
final class AccessDeniedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Before Drupal's exception subscribers, which render it.
        return [KernelEvents::EXCEPTION => ['onException', 100]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        if ($error instanceof AccessDeniedException) {
            $event->setThrowable(new AccessDeniedHttpException($error->getMessage(), $error));
        }
    }
}
