<?php

declare(strict_types=1);

namespace Drupal\collaborate\EventSubscriber;

use Drupal\Core\Form\EnforcedResponseException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drupal ends a submitted form by throwing the redirect it answers with: that is the save's response, not its
 * failure. Answered here, before the bundle's draft save hears of an exception (which would release a save that
 * succeeded), it reaches the response listeners, the save's checkpoint among them.
 */
final class EnforcedResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Before \Janalis\Collaborate\Draft\DraftSave::failed() (2048), as Drupal's own subscriber would (128).
        return [KernelEvents::EXCEPTION => ['onException', 4096]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        while (null !== $error) {
            if ($error instanceof EnforcedResponseException) {
                $event->setResponse($error->getResponse());

                return;
            }
            $error = $error->getPrevious();
        }
    }
}
