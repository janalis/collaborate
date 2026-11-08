<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Transport\WebSocket;

use Janalis\Collaborate\Cursor\CursorIdentity;

/**
 * What a verified subscriber token grants: its topics, until `exp`, to a page of `origin` of user `sub`; and, to a
 * page in a cursor room, sending cursor events to that room (`cursorRoom`) stamped as `cursor`.
 */
final readonly class TokenClaims
{
    /** @param non-empty-list<string> $topics */
    public function __construct(
        public int $sub,
        public string $origin,
        public array $topics,
        public int $exp,
        public ?string $cursorRoom = null,
        public ?CursorIdentity $cursor = null,
    ) {}
}
