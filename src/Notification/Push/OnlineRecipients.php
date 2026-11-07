<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Push;

use Janalis\Collaborate\Hub\Topics;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\RemoteHubInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Whether a page of a user holds the stream of their notifications, as the subscriptions API of the hub
 * says (Mercure 1.0, `subscriptions` on in the hub): such a page was told through the hub, a push would
 * only be dropped by the service worker. A hub that does not answer reads as nobody online: the push is
 * sent, and the service worker still shows it in a page when one is open.
 */
final readonly class OnlineRecipients
{
    private const string API = '/subscriptions/';

    public function __construct(
        private HubInterface $hub,
        private Topics $topics,
        private LoggerInterface $logger,
        private ?HttpClientInterface $client = null,
        private bool $enabled = false,
    ) {}

    public function isOnline(int $recipient): bool
    {
        $factory = $this->hub->getFactory();
        if (!$this->enabled || null === $this->client || null === $factory) {
            return false;
        }
        $base = $this->hub instanceof RemoteHubInterface ? $this->hub->getUrl() : $this->hub->getPublicUrl();
        $url = rtrim($base, '/') . self::API . rawurlencode($this->topics->notifications($recipient));
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => [
                    'Authorization' =>
                        'Bearer '
                            . $factory->create([
                                new Grant([Grant::ACTION_SUBSCRIBE], [
                                    $url,
                                    '/.well-known/mercure/subscriptions{/topic}{/subscriber}',
                                ]),
                            ]),
                ],
                'timeout' => 2,
            ]);
            $data = $response->toArray();
        } catch (\Throwable $error) {
            $this->logger->info('The hub did not say who listens to their notifications.', ['exception' => $error]);
            $data = [];
        }

        return (
            \is_array($data['subscriptions'] ?? null)
            && [] !== array_filter(
                $data['subscriptions'],
                static fn(mixed $subscription): bool => (
                    !\is_array($subscription)
                    || false !== ($subscription['active'] ?? true)
                ),
            )
        );
    }
}
