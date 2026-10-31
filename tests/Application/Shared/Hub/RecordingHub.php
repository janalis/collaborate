<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared\Hub;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * The `test` environment's hub: it signs subscriber tokens as the configured hub does and records what
 * would be published instead of reaching a hub.
 */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    public private(set) array $published = [];

    public function __construct(
        private readonly HubInterface $hub,
    ) {}

    public function getPublicUrl(): string
    {
        return $this->hub->getPublicUrl();
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->hub->getFactory();
    }

    public function publish(Update $update): string
    {
        $this->published[] = $update;

        return 'urn:uuid:' . \count($this->published);
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->hub->getProtocolVersion();
    }

    public function getCookieName(): string
    {
        return $this->hub->getCookieName();
    }

    public function clear(): void
    {
        $this->published = [];
    }
}
