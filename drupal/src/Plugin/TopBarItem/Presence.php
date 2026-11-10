<?php

declare(strict_types=1);

namespace Drupal\collaborate\Plugin\TopBarItem;

use Drupal\collaborate\Plugin\Block\NotificationsBlock;
use Drupal\collaborate\Plugin\Block\PresenceBlock;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\navigation\Attribute\TopBarItem;
use Drupal\navigation\TopBarItemBase;
use Drupal\navigation\TopBarRegion;

/**
 * Who else is on the page, and the notification bell, in the top bar of Drupal's Navigation: where an editor looks
 * for the page's own context and actions. Without Navigation, the module's blocks take their place in the
 * administration theme's header.
 */
#[TopBarItem(id: 'collaborate_presence', region: TopBarRegion::Actions, label: new TranslatableMarkup('Collaborate'))]
final class Presence extends TopBarItemBase
{
    public function weight(): int
    {
        // Before the page's own actions.
        return -100;
    }

    public function build(): array
    {
        return [
            'presence' => ['#lazy_builder' => [PresenceBlock::class . '::presence', []], '#create_placeholder' => true],
            'bell' => ['#lazy_builder' => [NotificationsBlock::class . '::bell', []], '#create_placeholder' => true],
            '#prefix' => '<div class="collaborate-top-bar">',
            '#suffix' => '</div>',
        ];
    }
}
