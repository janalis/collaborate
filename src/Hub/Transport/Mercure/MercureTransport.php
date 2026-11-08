<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Transport\Mercure;

use Janalis\Collaborate\Hub\HubSession;
use Janalis\Collaborate\Hub\Transport\HubTransportInterface;
use Janalis\Collaborate\Hub\Transport\PresenceProbeInterface;
use Janalis\Collaborate\Hub\Transport\PublishFailedException;
use Janalis\Collaborate\Hub\Transport\Subscription;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\RemoteHubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A Mercure hub (`symfony/mercure-bundle`): private updates, a subscriber JWT minted by the hub's own token factory,
 * and the subscriptions API of Mercure 1.0 (`subscriptions` on in the hub) to tell who listens.
 */
final readonly class MercureTransport implements HubTransportInterface, PresenceProbeInterface
{
    private const string SUBSCRIPTIONS = '/subscriptions/';

    public function __construct(
        private HubInterface $hub,
        private ?LoggerInterface $logger = null,
        private ?HttpClientInterface $client = null,
        private int $subscriptionTtl = 300,
    ) {}

    public function publish(array $notices): void
    {
        foreach ($notices as $index => $notice) {
            try {
                $this->hub->publish(new Update($notice->topics, $notice->data, true));
            } catch (\Throwable $error) {
                throw new PublishFailedException($index, $error);
            }
        }
    }

    public function subscribe(HubSession $session, Request $request): Subscription
    {
        $factory = $this->hub->getFactory();
        \assert(null !== $factory, 'A hub configured with a JWT secret or provider has a token factory.');

        return new Subscription(
            Subscription::MERCURE,
            $this->hub->getPublicUrl(),
            $factory->create([new Grant([Grant::ACTION_SUBSCRIBE], $session->topics)], [
                'exp' => new \DateTimeImmutable()->add(new \DateInterval('PT' . $this->subscriptionTtl . 'S')),
            ]),
            // Mercure carries no message from the page: it posts its cursor events.
            false,
        );
    }

    public function listening(string $topic): bool
    {
        $factory = $this->hub->getFactory();
        if (null === $this->client || null === $factory) {
            return false;
        }
        $base = $this->hub instanceof RemoteHubInterface ? $this->hub->getUrl() : $this->hub->getPublicUrl();
        $url = rtrim($base, '/') . self::SUBSCRIPTIONS . rawurlencode($topic);
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
            $this->logger?->info('The hub did not say who listens to a topic.', ['exception' => $error]);
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
