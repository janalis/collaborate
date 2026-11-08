<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub;

use Janalis\Collaborate\Cursor\CursorIdentity;

/**
 * What a page may listen to, as {@see HubSessions} admitted it: the topics, the user, the draft grants whose
 * signature the server no longer knows (`resend`) and those it refused (`denied`), by their index. A page in a
 * cursor room with cursors on also names its room and who it is there (`cursorRoom`, `cursor`): a transport
 * that carries cursor events itself stamps them with it.
 */
final readonly class HubSession
{
    /**
     * @param non-empty-list<string> $topics
     * @param list<int> $resend
     * @param list<int> $denied
     */
    public function __construct(
        public int $userId,
        public array $topics,
        public array $resend = [],
        public array $denied = [],
        public ?string $cursorRoom = null,
        public ?CursorIdentity $cursor = null,
    ) {}
}
