<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Host;

use Janalis\Collaborate\Notification\RecipientLocaleProviderInterface;

/** The languages of the test accounts: the reader reads French, the others the default language. */
final class HostLocales implements RecipientLocaleProviderInterface
{
    public function locale(int $recipient): ?string
    {
        return 17 === $recipient ? 'fr' : null;
    }
}
