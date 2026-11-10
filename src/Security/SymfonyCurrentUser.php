<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/** The user Symfony's security helper names, and its access decisions. */
final readonly class SymfonyCurrentUser implements CurrentUserInterface
{
    public function __construct(
        private Security $security,
    ) {}

    public function getUser(): ?UserInterface
    {
        return $this->security->getUser();
    }

    public function isGranted(string $attribute): bool
    {
        return $this->security->isGranted($attribute);
    }
}
