<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * User-bound field grants, issued from authorized, server-built forms. A verified grant's scope is cached
 * under its signature, so later requests send the signature alone instead of every field. A scope's entity is the
 * record's class, or the kind of record its bridge names (Drupal's `<entity type>:<bundle>`).
 *
 * @phpstan-import-type SchemaField from DraftJournal
 *
 * @phpstan-type Scope array{id: string, entity: string, permission: DraftAccessCheckerInterface::CREATE|DraftAccessCheckerInterface::UPDATE, new: bool, draft: string|null, fields: array<string, SchemaField>, requestedAt?: float}
 * @phpstan-type SignedScope array{id: string, entity: string, permission: DraftAccessCheckerInterface::CREATE|DraftAccessCheckerInterface::UPDATE, new: bool, draft: string|null, fields: array<string, SchemaField>, userId: int, expires: int, rolesHash: string, requestedAt?: float}
 */
final readonly class DraftAccess
{
    private const string CACHE_PREFIX = 'collaborate_grant.';

    public function __construct(
        #[\SensitiveParameter]
        private string $secret,
        public bool $enabled,
        private Topics $topics,
        private CollaboratorProviderInterface $collaborators,
        private DraftAccessCheckerInterface $checker,
        private CacheItemPoolInterface $scopes = new ArrayAdapter(),
        private int $lifetime = 36_000,
        /** A page asks at least every few seconds; a signature unknown after a longer pause costs one full resend (HTTP 412). */
        private int $signatureLifetime = 900,
        /** The largest draft request body: a full grant plus one update must fit. */
        public int $maxRequestBytes = 2_097_152,
    ) {}

    /**
     * @param Scope                    $scope
     * @param array<array-key, string> $authorization
     */
    public function issue(array $scope, int $userId, int $now, array $authorization = []): string
    {
        $payload = base64_encode(json_encode(
            $scope
            + [
                'userId' => $userId,
                'expires' => $now + $this->lifetime,
                'rolesHash' => Grant::authorizationHash($authorization),
            ],
            \JSON_THROW_ON_ERROR,
        ));

        return $payload . '.' . hash_hmac('sha256', $payload, $this->secret);
    }

    /**
     * Accepts a full grant, or the signature of one this server has already verified.
     *
     * @return SignedScope
     */
    public function verify(string $grant, UserInterface $user, int $now): array
    {
        if (!$this->enabled) {
            throw new AccessDeniedHttpException('Invalid Collaborate grant.');
        }
        if (1 === preg_match('/^[0-9a-f]{64}$/D', $grant)) {
            $item = $this->scopes->getItem(self::CACHE_PREFIX . $grant);
            if (!$item->isHit()) {
                throw new PreconditionFailedHttpException('Collaborate grant must be sent in full.');
            }
            /** @var SignedScope $scope */
            $scope = $item->get();
        } else {
            $scope = $this->decode($grant, $now);
        }
        if (
            $scope['userId'] !== $this->collaborators->id($user)
            || $scope['expires'] <= $now
            || $scope['rolesHash'] !== Grant::authorizationHash($this->collaborators->authorization($user))
            || !$this->checker->canEdit($user, $scope['permission'], $scope['entity'])
        ) {
            throw new AccessDeniedHttpException('Collaborate permission expired.');
        }

        return $scope;
    }

    /** The draft of one record (`$identity` is its id), or of one New page (`new:<draft>`). */
    public function draftId(string $entityClass, string $identity): string
    {
        return hash('sha256', $entityClass . ':' . $identity);
    }

    public function topic(string $id): string
    {
        return $this->topics->draft($id);
    }

    /** @return SignedScope */
    private function decode(string $grant, int $now): array
    {
        $parts = explode('.', $grant);
        if (2 !== \count($parts) || !hash_equals(hash_hmac('sha256', $parts[0], $this->secret), $parts[1])) {
            throw new AccessDeniedHttpException('Invalid Collaborate grant.');
        }
        $decoded = base64_decode($parts[0], true);
        if (false === $decoded) {
            throw new AccessDeniedHttpException('Invalid Collaborate grant encoding.');
        }
        /** @var SignedScope $scope */
        $scope = json_decode($decoded, true, flags: \JSON_THROW_ON_ERROR);
        $item = $this->scopes->getItem(self::CACHE_PREFIX . $parts[1]);
        // Each rendered form mints a grant: a short lifetime keeps the cached scopes few. A grant sent
        // in full again (each Save renews it) is already cached: a large form's scope is not rewritten.
        if (!$item->isHit()) {
            $this->scopes->save($item->set($scope)->expiresAfter(max(1, min(
                $this->signatureLifetime,
                $scope['expires'] - $now,
            ))));
        }

        return $scope;
    }
}
