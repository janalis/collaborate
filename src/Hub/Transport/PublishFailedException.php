<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Transport;

/** A publish that stopped at the notice `$index` of its list: that one and the ones after it were not published. */
final class PublishFailedException extends \RuntimeException
{
    public function __construct(
        public readonly int $index,
        \Throwable $previous,
    ) {
        parent::__construct('The hub refused a notice: ' . $previous->getMessage(), previous: $previous);
    }
}
