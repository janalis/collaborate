<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Cursor;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Turns what a page sends of its pointer, caret and presence into the notice its room hears: validated by
 * {@see CursorEvent}, stamped with the sender's identity and the server's time. One shape whichever way the
 * event came: `POST <prefix>/cursors/events`, or the page's socket to the WebSocket hub.
 */
final readonly class CursorEvents
{
    /** @var list<string> */
    private array $regions;

    /** @var list<string> */
    private array $popups;

    /**
     * @param array<string, string> $regions region name => root selector; empty for {@see CursorPosition::REGIONS}
     * @param array<string, mixed> $popups popup kind => its messages
     * @param list<string> $caretRegions
     */
    public function __construct(
        array $regions = [],
        array $popups = [],
        private array $caretRegions = [],
    ) {
        $this->regions = [] === $regions ? CursorPosition::REGIONS : array_keys($regions);
        $this->popups = array_keys($popups);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws BadRequestHttpException
     */
    public function notice(array $data, CursorIdentity $sender, int $timestamp): string
    {
        $event = CursorEvent::fromArray($data, $this->regions, $this->popups, $this->caretRegions);
        $event['userId'] = $sender->userId;
        $event['label'] = $sender->label;
        $event['color'] = $sender->color;
        $event['avatar'] = $sender->avatar;
        $event['timestamp'] = $timestamp;
        $event['channel'] = 'cursors';

        return json_encode($event, \JSON_THROW_ON_ERROR);
    }
}
