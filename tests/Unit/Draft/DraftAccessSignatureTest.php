<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

#[CoversClass(DraftAccess::class)]
#[UsesClass(Grant::class)]
#[UsesClass(Topics::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
#[UsesClass(RoleDraftAccessChecker::class)]
final class DraftAccessSignatureTest extends TestCase
{
    use DraftAccessFactory;

    public function testAcceptsTheSignatureOfAVerifiedGrantAndRechecksTheAccount(): void
    {
        $scopes = new ArrayAdapter();
        $access = $this->access(scopes: $scopes);
        $user = $this->user();
        $grant = $access->issue(self::SCOPE, 7, 100, $user->getRoles());
        $signature = explode('.', $grant)[1];
        try {
            $access->verify($signature, $user, 101);
            self::fail('An unverified signature was accepted.');
        } catch (PreconditionFailedHttpException) {
            self::assertTrue(true);
        }

        $access->verify($grant, $user, 101);
        self::assertSame(self::SCOPE['fields'], $access->verify($signature, $user, 101)['fields']);
        self::assertSame(
            'First draft',
            $this->access(scopes: $scopes)->verify($signature, $user, 101)['fields']['title']['initial'] ?? null,
        );
        $cases = [
            [$this->access(false, $scopes), $user, 101],
            [$access, $user, 36_100],
            [$access, new TestUser(8, 'bob', ['ROLE_SUPER_ADMIN']), 101],
            [$access, new TestUser(7, 'alice', ['ROLE_ADMIN']), 101],
        ];
        foreach ($cases as [$subject, $account, $time]) {
            try {
                $subject->verify($signature, $account, $time);
                self::fail('Unauthorized signature was accepted.');
            } catch (AccessDeniedHttpException) {
                self::assertTrue(true);
            }
        }
        self::assertTrue($scopes->getItem('collaborate_grant.' . $signature)->isHit());
        self::assertFalse($scopes->hasItem('collaborate_grant.' . str_repeat('0', 64)));
    }

    public function testAGrantSentInFullAgainDoesNotRewriteItsCachedScope(): void
    {
        $scopes = new class extends ArrayAdapter {
            public int $saves = 0;

            #[\Override]
            public function save(CacheItemInterface $item): bool
            {
                ++$this->saves;

                return parent::save($item);
            }
        };
        $access = $this->access(scopes: $scopes);
        $user = $this->user();
        $grant = $access->issue(self::SCOPE, 7, 100, $user->getRoles());
        $access->verify($grant, $user, 101);
        $access->verify($grant, $user, 102);
        self::assertSame(1, $scopes->saves);
        self::assertSame(
            'First draft',
            $access->verify(explode('.', $grant)[1], $user, 103)['fields']['title']['initial'] ?? null,
        );
    }

    public function testAVerifiedSignatureIsKeptForAShortTimeOnly(): void
    {
        $clock = new MockClock();
        $access = $this->access(scopes: new ArrayAdapter(clock: $clock), signatureLifetime: 900);
        $user = $this->user();
        $grant = $access->issue(self::SCOPE, 7, 100, $user->getRoles());
        $signature = explode('.', $grant)[1];
        $access->verify($grant, $user, 101);

        $clock->sleep(899);
        self::assertSame('record', $access->verify($signature, $user, 101)['id']);
        // A cache item computes its expiry from the wall clock when saved, just after the mock clock started.
        $clock->sleep(2);
        $this->expectException(PreconditionFailedHttpException::class);
        $access->verify($signature, $user, 101);
    }

    public function testASignatureIsNotKeptPastItsGrant(): void
    {
        $clock = new MockClock();
        $access = $this->access(scopes: new ArrayAdapter(clock: $clock), lifetime: 60);
        $user = $this->user();
        $grant = $access->issue(self::SCOPE, 7, 100, $user->getRoles());
        $access->verify($grant, $user, 150);

        $clock->sleep(11);
        $this->expectException(PreconditionFailedHttpException::class);
        $access->verify(explode('.', $grant)[1], $user, 150);
    }

    /** Whether the scope of `$grant` is still cached `$seconds` after it was verified. */
    private static function kept(ArrayAdapter $scopes, MockClock $clock, string $grant, float $seconds): bool
    {
        $now = $clock->now();
        $clock->sleep($seconds);
        $hit = $scopes->getItem('collaborate_grant.' . explode('.', $grant)[1])->isHit();
        $clock->modify($now->format('Y-m-d H:i:s.u'));

        return $hit;
    }

    public function testByDefaultAGrantLastsTenHoursAndItsSignatureFifteenMinutes(): void
    {
        $clock = new MockClock();
        $scopes = new ArrayAdapter(clock: $clock);
        $access = new DraftAccess(
            'secret',
            true,
            new Topics(),
            new SecurityCollaboratorProvider(),
            new RoleDraftAccessChecker(),
            $scopes,
        );
        $user = $this->user();
        $grant = $access->issue(self::SCOPE, 7, 100, $user->getRoles());

        self::assertSame(36_100, $access->verify($grant, $user, 101)['expires']);

        self::assertTrue(self::kept($scopes, $clock, $grant, 899.5));
        self::assertFalse(self::kept($scopes, $clock, $grant, 900.5));
    }

    public function testASignatureIsKeptOneSecondAtLeast(): void
    {
        $clock = new MockClock();
        $scopes = new ArrayAdapter(clock: $clock);
        $access = $this->access(scopes: $scopes, lifetime: 60);
        $user = $this->user();
        $grant = $access->issue(self::SCOPE, 7, 100, $user->getRoles());

        // Verified as it expires: refused, its scope cached for one second.
        try {
            $access->verify($grant, $user, 160);
            self::fail('An expired grant was accepted.');
        } catch (AccessDeniedHttpException) {
            self::assertTrue(self::kept($scopes, $clock, $grant, 0.5));
            self::assertFalse(self::kept($scopes, $clock, $grant, 1.5));
        }
    }

    public function testOnlyAWholeSignatureIsLookedUpInTheCache(): void
    {
        $scopes = new ArrayAdapter();
        $access = $this->access(scopes: $scopes);
        $user = $this->user();
        $grant = $access->issue(self::SCOPE, 7, 100, $user->getRoles());
        $signature = explode('.', $grant)[1];
        $access->verify($grant, $user, 101);

        // A known signature with anything after it, a line break included, is a malformed full grant.
        foreach ([$signature . '0', $signature . "\n"] as $sent) {
            try {
                $access->verify($sent, $user, 101);
                self::fail('A malformed grant was accepted.');
            } catch (AccessDeniedHttpException $denied) {
                self::assertSame('Invalid Collaborate grant.', $denied->getMessage());
            }
        }
    }
}
