<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Transport;

use Janalis\Collaborate\Hub\HubSession;
use Janalis\Collaborate\Hub\Notice;
use Symfony\Component\HttpFoundation\Request;

/** How notices reach the pages: Mercure, or the bundle's own WebSocket server. */
interface HubTransportInterface
{
    /**
     * Publishes the notices in order.
     *
     * @param non-empty-list<Notice> $notices
     *
     * @throws PublishFailedException naming the first notice that was not published
     */
    public function publish(array $notices): void;

    /** The credentials a page subscribes to the topics of its session with. */
    public function subscribe(HubSession $session, Request $request): Subscription;
}
