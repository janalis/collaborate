<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared\Hub;

use Janalis\Collaborate\Hub\HubSession;
use Janalis\Collaborate\Hub\Notice;
use Janalis\Collaborate\Hub\Transport\HubTransportInterface;
use Janalis\Collaborate\Hub\Transport\PresenceProbeInterface;
use Janalis\Collaborate\Hub\Transport\Subscription;
use Symfony\Component\HttpFoundation\Request;

/**
 * The `test` environment's transport: it subscribes pages and tells who listens as the configured transport does,
 * and records what would be published instead of reaching a hub.
 */
final class RecordingTransport implements HubTransportInterface, PresenceProbeInterface
{
    /** @var list<Notice> */
    public private(set) array $published = [];

    public function __construct(
        private readonly HubTransportInterface&PresenceProbeInterface $transport,
    ) {}

    public function publish(array $notices): void
    {
        $this->published = [...$this->published, ...$notices];
    }

    public function subscribe(HubSession $session, Request $request): Subscription
    {
        return $this->transport->subscribe($session, $request);
    }

    public function listening(string $topic): bool
    {
        return $this->transport->listening($topic);
    }

    public function clear(): void
    {
        $this->published = [];
    }
}
