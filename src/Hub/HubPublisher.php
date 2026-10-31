<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Publishes notifications after the response. Symfony saves the session on kernel.response, so a slow
 * hub does not hold the session lock of frequent requests.
 *
 * A failed publish pauses this worker's publishing for `$pauseSeconds`: an answered request no longer
 * tells the client the hub is down, so a hanging hub would otherwise hold a worker per realtime request
 * for the client's whole timeout.
 */
final class HubPublisher implements EventSubscriberInterface, ResetInterface
{
    /** @var list<array{Update, string}> */
    private array $pending = [];

    /** Describes the hub, not a request: {@see reset()} keeps it. */
    private float $pausedUntil = 0.0;

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly int $pauseSeconds = 5,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'publish'];
    }

    /** Whether the last publish of this worker succeeded, or failed long enough ago to try again. */
    public function available(): bool
    {
        return $this->now() >= $this->pausedUntil;
    }

    /** @param string $failure the message logged when this update cannot be published */
    public function defer(Update $update, string $failure): void
    {
        $this->pending[] = [$update, $failure];
    }

    public function publish(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as [$update, $failure]) {
            // Clients replay drafts and resend cursors on their own; a dropped notification only delays them.
            if (!$this->available()) {
                return;
            }
            try {
                $this->hub->publish($update);
            } catch (\Throwable $error) {
                $this->pausedUntil = $this->now() + $this->pauseSeconds;
                $this->logger->warning($failure, ['exception' => $error]);
            }
        }
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    private function now(): float
    {
        $now = $this->clock->now();

        return $now->getTimestamp() + ((int) $now->format('u') / 1_000_000);
    }
}
