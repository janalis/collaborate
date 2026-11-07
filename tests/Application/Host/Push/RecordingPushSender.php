<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Host\Push;

use Janalis\Collaborate\Notification\Entity\PushSubscription;
use Janalis\Collaborate\Notification\Push\PushResult;
use Janalis\Collaborate\Notification\Push\PushSenderInterface;

/**
 * The `test` environment's push sender: it records what would be pushed, and answers what a test makes a
 * push service answer an endpoint.
 */
final class RecordingPushSender implements PushSenderInterface
{
    /** @var list<array{endpoint: string, payload: string|null}> */
    public array $sent = [];

    /** @var array<string, PushResult> endpoint => what its push service answers */
    public array $answers = [];

    public function send(PushSubscription $subscription, ?string $payload): PushResult
    {
        $this->sent[] = ['endpoint' => $subscription->endpoint, 'payload' => $payload];

        return $this->answers[$subscription->endpoint] ?? PushResult::Sent;
    }
}
