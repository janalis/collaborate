<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Controller;

use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Controller\CursorController;
use Janalis\Collaborate\Hub\HubPublisher;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/** Sends cursor events through {@see CursorController} and reads what it published. */
abstract class CursorTestCase extends HubTestCase
{
    protected LoggerInterface $logger;
    protected bool $enabled = true;

    /** @var array<string, string> */
    protected array $regions = [];

    /** @var array<string, array<array-key, mixed>> */
    protected array $popups = [];

    /** @var list<string> */
    protected array $caretRegions = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    /**
     * @param array<string, mixed>   $overrides
     * @param non-empty-list<string> $colors
     *
     * @return list<array<string, mixed>>
     */
    protected function publish(array $overrides = [], array $colors = CollaborateBundle::COLORS): array
    {
        $published = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            /** @var array<string, mixed> $data */
            $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
            $published[] = $data;

            return 'id';
        });
        $response = $this->handle($overrides, publisher: new HubPublisher($hub, $this->logger), colors: $colors);
        self::assertSame(204, $response->getStatusCode());

        return $published;
    }

    /**
     * @param array<string, mixed>   $overrides
     * @param non-empty-list<string> $colors
     */
    protected function handle(
        array $overrides = [],
        bool $terminate = true,
        ?HubPublisher $publisher = null,
        array $colors = CollaborateBundle::COLORS,
        int $maxEventBytes = 4096,
    ): Response {
        $publisher ??= new HubPublisher($this->hub, $this->logger);
        $controller = new CursorController(
            $this->guard(),
            $this->collaborators,
            $publisher,
            $this->enabled,
            $colors,
            $maxEventBytes,
            $this->regions,
            $this->popups,
            $this->caretRegions,
        );
        $response = $controller($this->request('/cursors/events', $overrides));
        self::assertTrue(
            $this->session?->isStarted(),
            'Security must persist its token before releasing the session lock.',
        );
        if ($terminate) {
            $publisher->publish();
        }

        return $response;
    }
}
