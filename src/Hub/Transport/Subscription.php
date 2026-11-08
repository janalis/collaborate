<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Transport;

/**
 * Where a page subscribes (`url`), how (`transport`, which picks the client of `hub.js`), its credential, and
 * whether the page sends its cursor events over its subscription rather than to the application.
 */
final readonly class Subscription
{
    public const string MERCURE = 'mercure';
    public const string WEBSOCKET = 'websocket';

    public function __construct(
        public string $transport,
        public string $url,
        #[\SensitiveParameter]
        public string $token,
        public bool $cursorEvents,
    ) {}
}
