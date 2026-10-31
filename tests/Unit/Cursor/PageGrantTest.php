<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Cursor;

use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Topics;
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
        $a = $pages->issue($first, 1, 100);
        $b = $pages->issue($second, 2, 101);
        self::assertSame($pages->topic($a, 1, 102), $pages->topic($b, 2, 102));
        self::assertNotSame(
            $pages->topic($a, 1, 102),
            $pages->topic($pages->issue($this->request('en', ['page' => '3']), 1, 100), 1, 102),
        );
    }

    public function testRecordAndRouteArePartOfTheRoom(): void
    {
        $pages = $this->pages();
        $first = $this->request('en', []);
        $changed = clone $first;
        $changed->attributes->set('_route_params', ['id' => 99]);
        self::assertNotSame($pages->issue($first, 1, 100), $pages->issue($changed, 1, 100));
        $changed = clone $first;
        $changed->attributes->set('_route', 'another');
        self::assertNotSame($pages->issue($first, 1, 100), $pages->issue($changed, 1, 100));
    }

    public function testTheRoomIsATopicUnderThePrefixAndTheGrantCarriesItsLifetime(): void
    {
        $pages = new PageGrant('secret', new Topics('urn:test:'), 60);
        $grant = $pages->issue($this->request('en', []), 1, 100);
        self::assertSame('160', explode('.', $grant)[1]);
        self::assertMatchesRegularExpression('/^urn:test:cursors:[a-f0-9]{64}$/', $pages->topic($grant, 1, 159));
        $this->expectException(AccessDeniedHttpException::class);
        $pages->topic($grant, 1, 160);
    }

    public function testTheIgnoredParametersAreConfigured(): void
    {
        $pages = new PageGrant('secret', new Topics(), ignoredParameters: ['tab']);
        self::assertSame(
            $pages->topic($pages->issue($this->request('en', ['tab' => 'a']), 1, 100), 1, 100),
            $pages->topic($pages->issue($this->request('en', ['tab' => 'b']), 1, 100), 1, 100),
        );
        self::assertNotSame(
            $pages->topic($pages->issue($this->request('en', []), 1, 100), 1, 100),
            $pages->topic($pages->issue($this->request('fr', []), 1, 100), 1, 100),
        );
    }

    public function testRejectsMalformedExpiredForeignAndForgedGrants(): void
    {
        $pages = $this->pages();
        $valid = $pages->issue($this->request('en', []), 1, 100);
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
        $grant = $pages->issue($this->request('en', []), 1, 100, ['ROLE_ADMIN', 'ROLE_USER']);
        self::assertSame(
            $pages->topic($pages->issue($this->request('en', []), 1, 100), 1, 102),
            $pages->topic($grant, 1, 102, ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_USER']),
        );
        $this->expectException(AccessDeniedHttpException::class);
        $pages->topic($grant, 1, 102, ['ROLE_USER']);
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
}
