<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host-controlled text sessions. Hosts provide the authorized stored source on every open;
 * grants carry only the field schema, never the source. HTTP authentication, CSRF, rate limits,
 * transport notifications and request-size checks remain the host endpoint's responsibility.
 *
 * @phpstan-import-type SignedScope from DraftAccess
 * @phpstan-import-type View from DraftJournal
 * @phpstan-import-type Batch from DraftJournal
 */
final readonly class HeadlessDraft
{
    public const int MAX_SOURCE_BYTES = DraftSource::MAX_BYTES;
    public const string FIELD = 'source';

    public function __construct(
        private DraftAccess $access,
        private DraftJournal $journal,
        private CollaboratorProviderInterface $collaborators,
        private ClockInterface $clock = new NativeClock(),
    ) {}

    /**
     * Open from host-resolved persisted content, not a client-supplied initial value.
     * A journal surviving a reload keeps its unsaved content; a purged journal starts from storage.
     *
     * @return array{grant: string, state: View}
     */
    public function open(
        UserInterface $user,
        string $entity,
        string $record,
        string $source,
        ?float $sourceReadStartedAt = null,
    ): array {
        if ('' === $record || '' === $entity) {
            throw new \InvalidArgumentException('A headless draft requires a resource identity.');
        }
        self::validateSource($source);
        $scope = [
            'id' => $this->access->draftId($entity, $record),
            'entity' => $entity,
            'record' => $record,
            'permission' => 'update',
            'new' => false,
            'draft' => null,
            'headless' => true,
            'fields' => [self::FIELD => ['type' => 'source', 'initial' => '']],
        ];
        $grant = $this->access->issue(
            $scope,
            (int) $this->collaborators->id($user),
            $this->clock->now()->getTimestamp(),
            $this->collaborators->authorization($user),
        );
        $this->scope($grant, $user);
        $state = $this->journal->join(
            $scope['id'],
            [self::FIELD => ['type' => 'source', 'initial' => $source]],
            $sourceReadStartedAt ?? microtime(true),
        );

        return ['grant' => $grant, 'state' => $state];
    }

    /** @return View */
    public function replay(string $grant, UserInterface $user, int $since, ?string $digest = null): array
    {
        $scope = $this->scope($grant, $user);

        return $this->journal->replay($scope['id'], $scope['fields'], $since, 0.0, $digest);
    }

    /**
     * @param array<string, mixed> $event
     * @return Batch
     */
    public function append(string $grant, UserInterface $user, array $event): array
    {
        $scope = $this->scope($grant, $user);

        return $this->journal->append(
            $scope['id'],
            $scope['fields'],
            $event,
            (int) $this->collaborators->id($user),
            $this->collaborators->label($user),
            self::MAX_SOURCE_BYTES,
        );
    }

    /**
     * Resolve a stored-source conflict against host-read persisted content, never a browser initial value.
     * Capture the timestamp before reading storage, so a later checkpoint supersedes that older read.
     * Replacing the draft drops source provenance: clients must reopen from the returned snapshot.
     *
     * @return View
     */
    public function reconcile(
        string $grant,
        UserInterface $user,
        int $generation,
        int $revision,
        string $persistedSource,
        bool $keep,
        ?float $sourceReadStartedAt = null,
    ): array {
        $scope = $this->scope($grant, $user);
        self::validateSource($persistedSource);

        return $this->journal->reconcile(
            $scope['id'],
            $generation,
            $revision,
            [self::FIELD => ['type' => 'source', 'initial' => $persistedSource]],
            $keep,
            $sourceReadStartedAt ?? microtime(true),
            (int) $this->collaborators->id($user),
            $this->collaborators->label($user),
        );
    }

    private static function validateSource(string $source): void
    {
        if (\strlen($source) > self::MAX_SOURCE_BYTES || !mb_check_encoding($source, 'UTF-8')) {
            throw new DraftTooLargeException('Headless source must be UTF-8 text of at most 2 MiB.');
        }
    }

    /**
     * Mark only the exact persisted snapshot clean. Later acknowledged edits remain unsaved and
     * cannot be erased by idle compaction. The host calls this after its storage write succeeds.
     */
    public function checkpointIfCurrent(
        string $grant,
        UserInterface $user,
        int $generation,
        int $revision,
        string $source,
    ): bool {
        $scope = $this->scope($grant, $user);
        $this->journal->lock($scope['id']);
        try {
            $state = $this->journal->replay($scope['id'], $scope['fields'], 0);
            if (
                $state['generation'] !== $generation
                || $state['revision'] !== $revision
                || ($state['fields'][self::FIELD]['value'] ?? null) !== $source
            ) {
                return false;
            }
            $this->journal->saved(
                $scope['id'],
                [self::FIELD => $source],
                [
                    'userId' => (int) $this->collaborators->id($user),
                    'label' => $this->collaborators->label($user),
                ],
            );

            return true;
        } finally {
            $this->journal->unlock($scope['id']);
        }
    }

    /** @return SignedScope */
    private function scope(string $grant, UserInterface $user): array
    {
        $scope = $this->access->verify($grant, $user, $this->clock->now()->getTimestamp());
        if (true !== ($scope['headless'] ?? false)) {
            throw new AccessDeniedHttpException('A headless session grant is required.');
        }

        return $scope;
    }
}
