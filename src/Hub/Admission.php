<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A hub request {@see HubGuard} admitted: its user, the cursor room its page grant proves (none for a bare
 * grant, which admits a page to the notifications of its user alone) and its body.
 *
 * @internal
 */
final readonly class Admission
{
    /** @param array<array-key, mixed> $data */
    public function __construct(
        public UserInterface $user,
        public int $userId,
        public ?string $topic,
        public array $data,
    ) {}
}
