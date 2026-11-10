<?php

declare(strict_types=1);

namespace Drupal\collaborate\Hub;

use Drupal\Core\Site\Settings;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\DefaultClaimsTokenFactory;
use Symfony\Component\Mercure\Jwt\FactoryTokenProvider;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\Jwt\WebTokenFactory;

/**
 * The Mercure hub of the mercure transport under Drupal, as MercureBundle would build it from its configuration:
 * from settings.php's `$settings['collaborate_mercure']`, `url` (where the application publishes), `public_url`
 * (where browsers subscribe; `url` when absent), `jwt_secret` (the hub's publisher and subscriber key) and the
 * `claims` its tokens carry.
 */
final class MercureHubs
{
    /** Whether settings.php names the hub and its key. */
    public static function configured(): bool
    {
        $settings = Settings::get('collaborate_mercure', []);

        return (
            \is_array($settings)
            && \is_string($settings['url'] ?? null)
            && '' !== $settings['url']
            && \is_string($settings['jwt_secret'] ?? null)
            && '' !== $settings['jwt_secret']
        );
    }

    public static function hub(): HubInterface
    {
        $settings = Settings::get('collaborate_mercure', []);
        $settings = \is_array($settings) ? $settings : [];
        $url = $settings['url'] ?? null;
        $secret = $settings['jwt_secret'] ?? null;
        if (!self::configured()) {
            // Until settings.php names the hub, nothing reaches the pages: publications fail and are logged, the
            // status report says why, and every page still renders.
            return new Hub('http://127.0.0.1:9/.well-known/mercure', new StaticTokenProvider('unconfigured'));
        }
        \assert(\is_string($url) && \is_string($secret) && '' !== $secret);
        $factory = WebTokenFactory::fromSecret($secret);
        // The claims a hub of the Mercure protocol 1.0 asks every token for (`iss`, `aud`…), as MercureBundle's
        // `jwt.claims`.
        $claims = $settings['claims'] ?? [];
        if (\is_array($claims) && [] !== $claims) {
            $factory = new DefaultClaimsTokenFactory($factory, $claims);
        }
        $public = $settings['public_url'] ?? null;

        return new Hub(
            $url,
            new FactoryTokenProvider($factory, [new Grant([Grant::ACTION_PUBLISH], ['*'])]),
            $factory,
            \is_string($public) && '' !== $public ? $public : $url,
            HttpClient::create(),
        );
    }
}
