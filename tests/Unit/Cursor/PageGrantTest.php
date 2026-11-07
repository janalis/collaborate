<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Cursor;

use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Tests\Fixtures\TestPlaces;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[CoversClass(PageGrant::class)]
#[UsesClass(Grant::class)]
#[UsesClass(Topics::class)]
final class PageGrantTest extends TestCase
{
    public function testCanonicalViewsShareTopicsAcrossLocalesAndParameterOrder(): void
    {
        $pages = $this->pages();
        $ignored = 'x';
        $first = $this->request('en', [
            'page' => '2',
            'filters' => ['b' => ['value' => '1', 'comparison' => '='], 'a' => 'x'],
            '_token' => $ignored,
            '_switch_user' => $ignored,
        ]);
        $second = $this->request('fr', [
            'filters' => ['a' => 'x', 'b' => ['comparison' => '=', 'value' => '1']],
            'page' => '2',
        ]);
        $a = self::issued($pages->issue($first, 1, 100));
        $b = self::issued($pages->issue($second, 2, 101));
        self::assertSame($pages->topic($a, 1, 102), $pages->topic($b, 2, 102));
        self::assertNotSame(
            $pages->topic($a, 1, 102),
            $pages->topic(self::issued($pages->issue($this->request('en', ['page' => '3']), 1, 100)), 1, 102),
        );
    }

    public function testRecordAndRouteArePartOfTheRoom(): void
    {
        $pages = $this->pages();
        $first = $this->request('en', []);
        $changed = clone $first;
        $changed->attributes->set('_route_params', ['id' => 99]);
        self::assertNotSame(self::issued($pages->issue($first, 1, 100)), self::issued($pages->issue($changed, 1, 100)));
        $changed = clone $first;
        $changed->attributes->set('_route', 'another');
        self::assertNotSame(self::issued($pages->issue($first, 1, 100)), self::issued($pages->issue($changed, 1, 100)));
    }

    public function testTheRoomIsATopicUnderThePrefixAndTheGrantCarriesItsLifetime(): void
    {
        $pages = new PageGrant('secret', new Topics('urn:test:'), 60);
        $grant = self::issued($pages->issue($this->request('en', []), 1, 100));
        self::assertSame('160', explode('.', $grant)[1]);
        self::assertMatchesRegularExpression(
            '/^urn:test:cursors:[a-f0-9]{64}$/',
            (string) $pages->topic($grant, 1, 159),
        );
        $this->expectException(AccessDeniedHttpException::class);
        $pages->topic($grant, 1, 160);
    }

    public function testAGrantLastsTenHoursByDefault(): void
    {
        $pages = $this->pages();
        $grant = self::issued($pages->issue($this->request('en', []), 1, 100));
        self::assertSame('36100', explode('.', $grant)[1]);
        self::assertStringStartsWith('urn:collaborate:cursors:', (string) $pages->topic($grant, 1, 36_099));
    }

    public function testEveryParameterOfTheViewSeparatesRooms(): void
    {
        $pages = $this->pages();
        $room = static fn(Request $request): string => (string) $pages->topic(
            self::issued($pages->issue($request, 1, 100)),
            1,
            100,
        );
        self::assertNotSame(
            $room($this->request('en', ['a' => '1', 'b' => '2'])),
            $room($this->request('en', ['a' => '1', 'b' => '3'])),
        );
        self::assertNotSame(
            $room($this->request('en', ['filters' => ['a' => '1', 'b' => '2']])),
            $room($this->request('en', ['filters' => ['a' => '1', 'b' => '3']])),
        );
    }

    public function testTheIgnoredParametersAreConfigured(): void
    {
        $pages = new PageGrant('secret', new Topics(), ignoredParameters: ['tab']);
        self::assertSame(
            $pages->topic(self::issued($pages->issue($this->request('en', ['tab' => 'a']), 1, 100)), 1, 100),
            $pages->topic(self::issued($pages->issue($this->request('en', ['tab' => 'b']), 1, 100)), 1, 100),
        );
        self::assertNotSame(
            $pages->topic(self::issued($pages->issue($this->request('en', []), 1, 100)), 1, 100),
            $pages->topic(self::issued($pages->issue($this->request('fr', []), 1, 100)), 1, 100),
        );
    }

    public function testRejectsMalformedExpiredForeignAndForgedGrants(): void
    {
        $pages = $this->pages();
        $valid = self::issued($pages->issue($this->request('en', []), 1, 100));
        $hash = Grant::authorizationHash([]);
        foreach ([
            ['', 1, 100, []],
            [$valid, 2, 100, []],
            [$valid, 1, 36_100, []],
            [$valid . 'x', 1, 100, []],
            [$valid, 1, 100, ['ROLE_ADMIN']],
            ['1.no.' . $hash . '.' . str_repeat('a', 64) . '.x', 1, 100, []],
            ['1.999invalid.' . $hash . '.' . str_repeat('a', 64) . '.x', 1, 100, []],
            ['1.999.' . $hash . '.bad.x', 1, 100, []],
            [$this->forge('1.999.' . $hash . '.bad'), 1, 100, []],
            [$this->forge('1.999.' . $hash), 1, 100, []],
            // Signed, so only the format checks behind the signature's refuse them.
            [$this->forge('1.no.' . $hash . '.' . str_repeat('a', 64)), 1, 100, []],
            [$this->forge('1.999.' . $hash . '.x' . str_repeat('a', 64)), 1, 100, []],
            [$this->forge('1.999.' . $hash . '.' . str_repeat('a', 64) . 'x'), 1, 100, []],
            [$this->forge('1.999.' . $hash . '.' . str_repeat('a', 64) . "\n"), 1, 100, []],
            [$valid, 1, 100, [], 'another-secret'],
        ] as $case) {
            [$grant, $id, $now, $roles] = $case;
            $subject = \array_key_exists(4, $case) ? new PageGrant($case[4], new Topics()) : $pages;
            try {
                $subject->topic($grant, $id, $now, $roles);
                self::fail('Accepted an invalid grant.');
            } catch (AccessDeniedHttpException $error) {
                self::assertSame('Invalid cursor page grant.', $error->getMessage());
            }
        }
    }

    public function testAGrantFollowsItsAuthorizationInAnyOrderAndIsRefusedOnceItChanges(): void
    {
        $pages = $this->pages();
        $grant = self::issued($pages->issue($this->request('en', []), 1, 100, ['ROLE_ADMIN', 'ROLE_USER']));
        self::assertSame(
            $pages->topic(self::issued($pages->issue($this->request('en', []), 1, 100)), 1, 102),
            $pages->topic($grant, 1, 102, ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_USER']),
        );
        $this->expectException(AccessDeniedHttpException::class);
        $pages->topic($grant, 1, 102, ['ROLE_USER']);
    }

    public function testAPlaceNamesTheRoomWhateverTheAddressAndTheGrantCarriesIt(): void
    {
        $places = new TestPlaces();
        $pages = new PageGrant('secret', new Topics(), places: $places);
        $place = ['folder', 'a/b', ['q' => 'x', 'p' => 'é']];
        $first = $this->place($place);
        $second = $this->place(['folder', 'a/b', ['p' => 'é', 'q' => 'x']]);
        $second->attributes->set('_route', 'another');
        $grant = self::issued($pages->issue($first, 1, 100));
        self::assertIsString($grant);
        self::assertCount(6, explode('.', $grant));
        // Base64url without its padding: the place's JSON is no multiple of three bytes long.
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', explode('.', $grant)[4]);
        $verified = $pages->verify($grant, 1, 101);
        // The canonical place: keys sorted at every depth, a list kept in its order.
        self::assertSame(['folder', 'a/b', ['p' => 'é', 'q' => 'x']], $verified['place']);
        self::assertSame($verified['topic'], $pages->topic((string) $pages->issue($second, 2, 100), 2, 101));
        self::assertNotSame($verified['topic'], $pages->topic(
            (string) $pages->issue($this->place(['folder', 'a']), 1, 100),
            1,
            101,
        ));
        // The place is no secret, but the grant cannot carry another one.
        $parts = explode('.', $grant);
        $parts[4] = rtrim(strtr(base64_encode('["folder","c"]'), '+/', '-_'), '=');
        $this->expectException(AccessDeniedHttpException::class);
        $pages->verify(implode('.', $parts), 1, 101);
    }

    public function testAPageInNoPlaceHasNoGrant(): void
    {
        $pages = new PageGrant('secret', new Topics(), places: new TestPlaces());
        self::assertNull($pages->issue($this->request('en', []), 1, 100));
    }

    public function testAGrantOfTheOtherKindIsRefused(): void
    {
        $routes = $this->pages();
        $places = new PageGrant('secret', new Topics(), places: new TestPlaces());
        $byRoute = (string) $routes->issue($this->place(['a']), 1, 100);
        $byPlace = (string) $places->issue($this->place(['a']), 1, 100);
        self::assertNull($routes->verify($byRoute, 1, 101)['place']);
        foreach ([[$routes, $byPlace], [$places, $byRoute]] as [$pages, $grant]) {
            try {
                $pages->verify($grant, 1, 101);
                self::fail('Accepted a grant of the other kind.');
            } catch (AccessDeniedHttpException $error) {
                self::assertSame('Invalid cursor page grant.', $error->getMessage());
            }
        }
    }

    public function testAPlaceGrantIsCheckedLikeTheOthers(): void
    {
        $pages = new PageGrant('secret', new Topics(), places: new TestPlaces());
        $grant = (string) $pages->issue($this->place(['a']), 1, 100, ['ROLE_ADMIN']);
        foreach ([[$grant, 2, 101, ['ROLE_ADMIN']], [$grant, 1, 36_100, ['ROLE_ADMIN']], [$grant, 1, 101, []]] as [
            $value,
            $id,
            $now,
            $roles,
        ]) {
            try {
                $pages->verify($value, $id, $now, $roles);
                self::fail('Accepted an invalid place grant.');
            } catch (AccessDeniedHttpException) {
            }
        }
        self::assertSame(['a'], $pages->verify($grant, 1, 36_099, ['ROLE_ADMIN'])['place']);
    }

    /** @param array<array-key, mixed> $place */
    private function place(array $place): Request
    {
        return new Request(attributes: ['_route' => 'folder', '_route_params' => [], '_place' => $place]);
    }

    private function pages(): PageGrant
    {
        return new PageGrant('secret', new Topics());
    }

    /** A payload signed with the right secret, to reach the checks behind the signature's. */
    private function forge(string $payload): string
    {
        return $payload . '.' . hash_hmac('sha256', $payload, 'secret');
    }

    /** @param array<string, mixed> $query */
    private function request(string $locale, array $query): Request
    {
        return new Request($query, attributes: [
            '_route' => 'article_edit',
            '_route_params' => ['id' => '42', '_locale' => $locale],
        ]);
    }

    /** A grant the page grant issued: every page of these tests is in a place, or none is asked. */
    private static function issued(?string $grant): string
    {
        self::assertNotNull($grant);

        return $grant;
    }
}
