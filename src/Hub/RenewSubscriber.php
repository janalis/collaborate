<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A page whose page grant or CSRF token was refused loads itself again in the background (`hub.js`) with
 * `X-Collaborate-Renew: 1`, to read a fresh grant from its layer. Rendering the page reads the session's
 * flash messages, so a message another tab queued for its next page would be lost to a page nobody sees:
 * the flashes the render consumed are put back before the session is saved, which leaves the session data
 * unchanged.
 */
final class RenewSubscriber implements EventSubscriberInterface
{
    public const string HEADER = 'X-Collaborate-Renew';
    private const string ATTRIBUTE = '_collaborate_renew';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'keep',
            // Before the session listener saves the session (priority -1000).
            KernelEvents::RESPONSE => 'restore',
        ];
    }

    public function keep(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (
            !$event->isMainRequest()
            || '1' !== $request->headers->get(self::HEADER)
            || !$request->hasPreviousSession()
        ) {
            return;
        }
        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }
        $bag = $session->getFlashBag();
        $kept = [];
        foreach (array_keys($bag->peekAll()) as $type) {
            $kept[] = [(string) $type, $bag->peek((string) $type)];
        }
        $request->attributes->set(self::ATTRIBUTE, static function () use ($bag, $kept): void {
            $current = $bag->peekAll();
            $restored = [];
            // The kept types come back first and in their order, each before what the render added to it.
            foreach ($kept as [$type, $messages]) {
                $added = $bag->peek($type);
                unset($current[$type]);
                // A type the render did not read still starts with the kept messages.
                if (\array_slice($added, 0, \count($messages)) === $messages) {
                    $added = \array_slice($added, \count($messages));
                }
                $restored[$type] = [...$messages, ...$added];
            }
            $bag->setAll($restored + $current);
        });
    }

    public function restore(ResponseEvent $event): void
    {
        /** @var mixed $restore */
        $restore = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if ($restore instanceof \Closure) {
            $restore();
        }
    }
}
