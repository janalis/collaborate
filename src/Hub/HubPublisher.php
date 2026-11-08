<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub;

use Janalis\Collaborate\Hub\Transport\HubTransportInterface;
use Janalis\Collaborate\Hub\Transport\PublishFailedException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Publishes notices after the response (and after a console command or a Messenger message). Symfony saves the session on kernel.response, so a slow
 * hub does not hold the session lock of frequent requests. The notices of one request go to the transport
 * together: the WebSocket transport sends them in one request.
 *
 * A failed publish pauses this worker's publishing for `$pauseSeconds`: an answered request no longer
 * tells the client the hub is down, so a hanging hub would otherwise hold a worker per realtime request
 * for the client's whole timeout.
 */
final class HubPublisher implements EventSubscriberInterface, ResetInterface
{
    /** @var list<array{Notice, string}> */
    private array $pending = [];

    /** In microseconds. Describes the hub, not a request: {@see reset()} keeps it. */
    private int $pausedUntil = 0;

    public function __construct(
        private readonly HubTransportInterface $transport,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly int $pauseSeconds = 5,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // A console command and a Messenger worker terminate no kernel: what they deferred is sent once a
        // command or a message is done with. The worker's events are named by their class, which is no
        // dependency: without Messenger, nothing dispatches them.
        return [
            KernelEvents::TERMINATE => 'publish',
            ConsoleEvents::TERMINATE => 'publish',
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent' => 'publish',
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent' => 'publish',
        ];
    }

    /** Whether the last publish of this worker succeeded, or failed long enough ago to try again. */
    public function available(): bool
    {
        return $this->now() >= $this->pausedUntil;
    }

    /** @param string $failure the message logged when this update cannot be published */
    public function defer(Notice $notice, string $failure): void
    {
        $this->pending[] = [$notice, $failure];
    }

    public function publish(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        // Clients replay drafts and resend cursors on their own; a dropped notification only delays them.
        if ([] === $pending || !$this->available()) {
            return;
        }
        try {
            $this->transport->publish(array_map(static fn(array $entry): Notice => $entry[0], $pending));
        } catch (PublishFailedException $error) {
            // The notices after the one that failed are dropped with it: the hub is paused.
            $this->pausedUntil = $this->now() + ($this->pauseSeconds * 1_000_000);
            $this->logger->warning($pending[$error->index][1], ['exception' => $error->getPrevious()]);
        }
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    /** In microseconds. */
    private function now(): int
    {
        return (int) $this->clock->now()->format('Uu');
    }
}
