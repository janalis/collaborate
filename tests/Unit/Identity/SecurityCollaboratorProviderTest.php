<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Identity;

use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(SecurityCollaboratorProvider::class)]
final class SecurityCollaboratorProviderTest extends TestCase
{
    /** @return iterable<string, array{UserInterface, int|null}> */
    public static function users(): iterable
    {
        yield 'public id' => [new TestUser(7), 7];
        yield 'unsaved' => [new TestUser(null), null];
        yield 'zero' => [new TestUser(0), null];
        yield 'negative' => [new TestUser(-3), null];
        yield 'getter' => [self::withGetter(12), 12];
        yield 'numeric string getter' => [self::withGetter('0042'), 42];
        yield 'zero string getter' => [self::withGetter('000'), null];
        yield 'non-numeric getter' => [self::withGetter('a1'), null];
        yield 'uuid getter' => [self::withGetter(new \stdClass()), null];
        yield 'no id' => [new InMemoryUser('alice', null), null];
    }

    #[DataProvider('users')]
    public function testReadsAPositiveIntegerId(UserInterface $user, ?int $expected): void
    {
        self::assertSame($expected, new SecurityCollaboratorProvider()->id($user));
    }

    public function testLabelsByTheStringFormOrTheIdentifier(): void
    {
        $provider = new SecurityCollaboratorProvider();
        self::assertSame('Alice', $provider->label(new TestUser(1, 'alice', [], 'Alice')));
        self::assertSame('bob', $provider->label(new InMemoryUser('bob', null)));
        self::assertNull($provider->avatarUrl(new TestUser(1)));
    }

    public function testAdmitsByTheDistinctRoles(): void
    {
        self::assertSame(
            ['ROLE_ADMIN', 'ROLE_USER'],
            new SecurityCollaboratorProvider()->authorization(
                new TestUser(1, 'alice', ['ROLE_ADMIN', 'ROLE_USER', 'ROLE_ADMIN']),
            ),
        );
    }

    private static function withGetter(mixed $id): UserInterface
    {
        return new class($id) implements UserInterface {
            public function __construct(
                private readonly mixed $id,
            ) {}

            public function getId(): mixed
            {
                return $this->id;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void {}

            public function getUserIdentifier(): string
            {
                return 'getter';
            }
        };
    }
}
