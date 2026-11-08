<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Transport;

/** Whether a page listens to a topic now. A hub that does not say reads as nobody listening. */
interface PresenceProbeInterface
{
    public function listening(string $topic): bool;
}
