<?php

declare(strict_types=1);

namespace Drupal\collaborate\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Janalis\Collaborate\Twig\CollaborateExtension;

/** The others on the page, as avatars or a menu: placed in the administration theme's header. */
#[Block(
    id: 'collaborate_presence',
    admin_label: new TranslatableMarkup('Collaborate: who else is here'),
    category: new TranslatableMarkup('Collaborate'),
)]
final class PresenceBlock extends BlockBase implements TrustedCallbackInterface
{
    public function build(): array
    {
        // The user's and the page's: rendered apart from any cached block.
        return ['#lazy_builder' => [static::class . '::presence', []], '#create_placeholder' => true];
    }

    /** @return array<array-key, mixed> */
    public static function presence(): array
    {
        $container = \Drupal::getContainer();
        $extension = $container->get(CollaborateExtension::class);
        \assert($extension instanceof CollaborateExtension);

        return [
            '#markup' => Markup::create($extension->presence($container->get('twig'))),
            '#cache' => ['max-age' => 0],
        ];
    }

    public static function trustedCallbacks(): array
    {
        return ['presence'];
    }
}
