<?php

declare(strict_types=1);

namespace Drupal\collaborate\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Janalis\Collaborate\Notification\Messenger\SendNotification;
use Janalis\Collaborate\Notification\Messenger\SendNotificationHandler;
use Janalis\Collaborate\Notification\Messenger\SendWebPush;
use Janalis\Collaborate\Notification\Messenger\SendWebPushHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Writes the notifications and sends the web pushes the bundle queued, with the bundle's own handlers. */
#[QueueWorker(id: 'collaborate', title: new TranslatableMarkup('Collaborate notifications and web pushes'), cron: [
    'time' => 30,
])]
final class MessageWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        array $configuration,
        string $plugin_id,
        mixed $plugin_definition,
        private readonly ContainerInterface $container,
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
    }

    /** @param array<string, mixed> $configuration */
    public static function create(
        ContainerInterface $container,
        array $configuration,
        $plugin_id,
        $plugin_definition,
    ): self {
        return new self($configuration, $plugin_id, $plugin_definition, $container);
    }

    public function processItem($data): void
    {
        // The handlers are registered with notifications, and web push: a message of what is now off is dropped.
        if ($data instanceof SendNotification && $this->container->has(SendNotificationHandler::class)) {
            $handler = $this->container->get(SendNotificationHandler::class);
            \assert($handler instanceof SendNotificationHandler);
            $handler($data);
        } elseif ($data instanceof SendWebPush && $this->container->has(SendWebPushHandler::class)) {
            $handler = $this->container->get(SendWebPushHandler::class);
            \assert($handler instanceof SendWebPushHandler);
            $handler($data);
        }
    }
}
