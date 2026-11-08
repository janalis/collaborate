<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Cursor;

/** Who a cursor event is from, as the server knows them: what peers are shown in place of anything the client claims. */
final readonly class CursorIdentity
{
    public function __construct(
        public int $userId,
        public string $label,
        public string $color,
        public ?string $avatar = null,
    ) {}
}
