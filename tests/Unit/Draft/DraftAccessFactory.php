<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\TestUser;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Security\Core\User\UserInterface;

/** A draft scope, its user and the access that signs it. */
trait DraftAccessFactory
{
    public const array SCOPE = [
        'id' => 'record',
        'entity' => \stdClass::class,
        'permission' => 'update',
        'new' => false,
        'draft' => null,
        'fields' => ['title' => ['type' => 'text', 'initial' => 'First draft']],
    ];

    private function user(): TestUser
    {
        return new TestUser(7, 'alice', ['ROLE_SUPER_ADMIN']);
    }

    private function refusing(): DraftAccessCheckerInterface
    {
        return new class implements DraftAccessCheckerInterface {
            public function canEdit(UserInterface $user, string $permission, string $entityClass): bool
            {
                return false;
            }
        };
    }

    private function access(
        bool $enabled = true,
        ?CacheItemPoolInterface $scopes = null,
        ?DraftAccessCheckerInterface $checker = null,
        int $lifetime = 36_000,
        int $signatureLifetime = 900,
    ): DraftAccess {
        return new DraftAccess(
            'secret',
            $enabled,
            new Topics(),
            new SecurityCollaboratorProvider(),
            $checker ?? new RoleDraftAccessChecker(),
            $scopes ?? new ArrayAdapter(),
            $lifetime,
            $signatureLifetime,
        );
    }
}
