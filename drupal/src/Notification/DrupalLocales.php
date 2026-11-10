<?php

declare(strict_types=1);

namespace Drupal\collaborate\Notification;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;
use Janalis\Collaborate\Notification\RecipientLocaleProviderInterface;

/** The language a user reads their web pushes in: their preferred one. */
final readonly class DrupalLocales implements RecipientLocaleProviderInterface
{
    public function __construct(
        private EntityTypeManagerInterface $entities,
    ) {}

    public function locale(int $recipient): ?string
    {
        $account = $this->entities->getStorage('user')->load($recipient);

        return $account instanceof UserInterface ? $account->getPreferredLangcode() : null;
    }
}
