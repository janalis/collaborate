<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Push;

use Janalis\Collaborate\Notification\Entity\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Pushes through minishlink/web-push, which encrypts the payload (RFC 8291) and signs the request (VAPID,
 * RFC 8292), over Symfony's HTTP client (PSR-18). Its client is made on the first push and forgotten between two
 * requests of a worker.
 */
final class WebPushSender implements PushSenderInterface, ResetInterface
{
    private ?WebPush $client = null;

    public function __construct(
        private readonly string $publicKey,
        #[\SensitiveParameter]
        private readonly string $privateKey,
        private readonly string $subject,
        private readonly LoggerInterface $logger,
        private readonly int $ttl = 86_400,
        private readonly string $urgency = 'normal',
        private readonly ?HttpClientInterface $http = null,
    ) {}

    public function send(PushSubscription $subscription, ?string $payload): PushResult
    {
        try {
            $report = $this->client()->sendOneNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->p256dh,
                'authToken' => $subscription->auth,
                'contentEncoding' => $subscription->encoding,
            ]), $payload);
        } catch (\Throwable $error) {
            $this->logger->warning('Collaborate could not push a notification.', ['exception' => $error]);

            return PushResult::Failed;
        }
        if ($report->isSuccess()) {
            return PushResult::Sent;
        }
        if ($report->isSubscriptionExpired()) {
            return PushResult::Expired;
        }
        $this->logger->warning('A push service refused a notification.', ['reason' => $report->getReason()]);

        return PushResult::Failed;
    }

    public function reset(): void
    {
        $this->client = null;
    }

    private function client(): WebPush
    {
        // A push service answers; it never sends the request elsewhere, and a slow one holds no worker for long.
        $http = new Psr18Client(($this->http ?? HttpClient::create())->withOptions([
            'timeout' => 10,
            'max_redirects' => 0,
        ]));

        return $this->client ??= new WebPush(
            ['VAPID' => [
                'subject' => $this->subject,
                'publicKey' => $this->publicKey,
                'privateKey' => $this->privateKey,
            ]],
            ['TTL' => $this->ttl, 'urgency' => $this->urgency],
            $http,
            $http,
            $http,
        );
    }
}
