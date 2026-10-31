<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

/** A collection entry whose identity spans several columns: its id is no form-safe key. */
final class Membership
{
    public string $id;

    public function __construct(
        public int $accountId = 0,
        public int $teamId = 0,
        public string $role = '',
    ) {
        $this->id = $accountId . '/' . $teamId . '/' . $role;
    }
}
