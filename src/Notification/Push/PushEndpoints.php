<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Push;

/**
 * The push services a browser may name as its endpoint: the server posts to every endpoint its users saved, so
 * one on another host would make it request whatever address a user wrote (an internal service, the metadata of
 * a cloud instance). An endpoint is an https URL on the default port, without credentials, on one of the hosts
 * or under one of their domains.
 */
final readonly class PushEndpoints
{
    /** The push services of Chrome and its kin (FCM), Firefox, Safari and the legacy Edge (WNS). */
    public const array HOSTS = [
        'fcm.googleapis.com',
        'push.services.mozilla.com',
        'push.apple.com',
        'notify.windows.com',
    ];

    /** @param list<string> $hosts hosts an endpoint may be on, or under */
    public function __construct(
        private array $hosts = self::HOSTS,
    ) {}

    public function allows(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        if (
            !\is_array($url)
            || 'https' !== ($url['scheme'] ?? null)
            || isset($url['user'])
            || isset($url['pass'])
            || isset($url['port']) && 443 !== $url['port']
        ) {
            return false;
        }
        $host = strtolower($url['host'] ?? '');
        foreach ($this->hosts as $allowed) {
            $allowed = strtolower($allowed);
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }
}
