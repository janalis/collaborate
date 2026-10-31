<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures\Draft;

/** A record with a plain list and credentials, none of which a draft may carry. */
final class Account
{
    public ?int $id = null;

    public string $name = '';

    public string $password = '';

    public string $apiKey = '';

    /** @param list<string>|array<string, string> $roles */
    public function __construct(
        public array $roles = [],
    ) {}
}
