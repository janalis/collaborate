<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification;

/**
 * The language a user reads: what a system notification is written in, the user being on no page to say it.
 * Without one, every push is written in the default locale.
 */
interface RecipientLocaleProviderInterface
{
    /** A locale (e.g. `fr`), or null for the default one. */
    public function locale(int $recipient): ?string;
}
