<?php

declare(strict_types=1);

namespace Drupal\collaborate\Security;

use Drupal\Core\Access\CsrfTokenGenerator;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** The bundle's CSRF tokens (`collaborate-hub`, `collaborate-draft`…), drawn and checked by Drupal's generator. */
final readonly class DrupalCsrfTokenManager implements CsrfTokenManagerInterface
{
    public function __construct(
        private CsrfTokenGenerator $generator,
    ) {}

    public function getToken(string $tokenId): CsrfToken
    {
        return new CsrfToken($tokenId, $this->generator->get($tokenId));
    }

    /** Drupal's tokens are derived from the session: refreshing one is drawing it again. */
    public function refreshToken(string $tokenId): CsrfToken
    {
        return $this->getToken($tokenId);
    }

    /** Drupal's tokens live as long as the session; none is removed alone. */
    public function removeToken(string $tokenId): ?string
    {
        return null;
    }

    public function isTokenValid(CsrfToken $token): bool
    {
        return $this->generator->validate((string) $token->getValue(), $token->getId());
    }
}
