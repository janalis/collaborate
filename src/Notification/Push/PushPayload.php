<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Push;

use Janalis\Collaborate\Notification\Entity\Notification;
use Janalis\Collaborate\Notification\NotificationCatalog;
use Janalis\Collaborate\Notification\RecipientLocaleProviderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a push says: the sentence of the notification in the language of its reader, its title, and where it
 * leads. It reaches the browser encrypted, through the push service of its vendor; `payload: none` sends
 * nothing at all, and the service worker says a sentence of its own.
 */
final readonly class PushPayload
{
    public function __construct(
        private NotificationCatalog $catalog,
        private string $defaultLocale = 'en',
        private string $title = 'New notification',
        private ?string $icon = null,
        private string $payload = 'full',
        private ?TranslatorInterface $translator = null,
        private ?RecipientLocaleProviderInterface $locales = null,
    ) {}

    /** The JSON a push carries, or null for an empty push. */
    public function of(Notification $notification): ?string
    {
        if ('full' !== $this->payload) {
            return null;
        }
        $locale = $this->locales?->locale($notification->recipient) ?? $this->defaultLocale;
        $type = $this->catalog->types[$notification->type] ?? null;
        $body = null === $type
            ? ''
            : $this->trans($type['sentence'], $notification->parameters, $type['domain'], $locale);

        return json_encode(
            [
                'id' => $notification->id,
                'title' => $this->trans($this->title, [], 'collaborate', $locale),
                'body' => $body,
                'url' => $notification->url,
                'icon' => $this->icon,
                // A notification is pushed once it is saved, with its id.
                // @mago-expect analysis:possibly-null-operand
                'tag' => 'collaborate-notification-' . $notification->id,
            ],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }

    /** @param array<string, string> $parameters */
    private function trans(string $message, array $parameters, string $domain, string $locale): string
    {
        return null === $this->translator
            ? strtr($message, $parameters)
            : $this->translator->trans($message, $parameters, $domain, $locale);
    }
}
