<?php

declare(strict_types=1);

namespace Drupal\collaborate\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Janalis\Collaborate\Twig\NotificationRuntime;

/** The notification bell, with notifications on: placed in the administration theme's header. */
#[Block(
    id: 'collaborate_notifications',
    admin_label: new TranslatableMarkup('Collaborate: notifications'),
    category: new TranslatableMarkup('Collaborate'),
)]
final class NotificationsBlock extends BlockBase implements TrustedCallbackInterface
{
    public function build(): array
    {
        return ['#lazy_builder' => [static::class . '::bell', []], '#create_placeholder' => true];
    }

    /** @return array<array-key, mixed> */
    public static function bell(): array
    {
        $container = \Drupal::getContainer();
        if (!$container->has(NotificationRuntime::class)) {
            return [];
        }
        $runtime = $container->get(NotificationRuntime::class);
        \assert($runtime instanceof NotificationRuntime);

        return [
            '#markup' => Markup::create(
                '<div data-collaborate-notifications>' . $runtime->bell($container->get('twig')) . '</div>',
            ),
            '#cache' => ['max-age' => 0],
        ];
    }

    public static function trustedCallbacks(): array
    {
        return ['bell'];
    }
}
