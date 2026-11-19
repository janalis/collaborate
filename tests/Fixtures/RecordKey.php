<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Fixtures;

/** A value-object identifier, as a UUID or ULID `Uid` is: Stringable, and no entity. */
final readonly class RecordKey implements \Stringable
{
    public function __construct(
        private string $value,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
