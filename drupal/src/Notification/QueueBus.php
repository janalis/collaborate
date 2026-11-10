<?php

declare(strict_types=1);

namespace Drupal\collaborate\Notification;

use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The bus the bundle sends its notifications and web pushes through (`notifications.bus`), as Drupal queues them:
 * the `collaborate` queue, which cron, or `drush queue:run collaborate`, works through
 * ({@see \Drupal\collaborate\Plugin\QueueWorker\MessageWorker}).
 */
final readonly class QueueBus implements MessageBusInterface
{
    public const string QUEUE = 'collaborate';

    public function __construct(
        private QueueFactory $queues,
    ) {}

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message, $stamps);
        $this->queues->get(self::QUEUE, true)->createItem($envelope->getMessage());

        return $envelope;
    }
}
