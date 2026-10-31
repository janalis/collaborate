<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit;

use Janalis\Collaborate\Grant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Grant::class)]
final class GrantTest extends TestCase
{
    public function testHashesTheAuthorizationAsASortedSet(): void
    {
        self::assertSame(
            hash('sha256', '["ROLE_ADMIN","ROLE_USER"]'),
            Grant::authorizationHash(['ROLE_USER', 'ROLE_ADMIN']),
        );
        self::assertSame(
            Grant::authorizationHash(['ROLE_USER', 'ROLE_ADMIN']),
            Grant::authorizationHash(['b' => 'ROLE_ADMIN', 'a' => 'ROLE_USER', 'c' => 'ROLE_ADMIN']),
        );
        self::assertSame(hash('sha256', '[]'), Grant::authorizationHash([]));
        self::assertNotSame(Grant::authorizationHash(['ROLE_USER']), Grant::authorizationHash(['ROLE_ADMIN']));
    }
}
