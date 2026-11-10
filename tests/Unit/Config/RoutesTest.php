<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Loader\PhpFileLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;

/** The routes file, read by the application's router: no class of src/ is involved. */
#[CoversNothing]
final class RoutesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function draftActions(): iterable
    {
        foreach (['join', 'replay', 'append', 'discard', 'reconcile', 'report'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('draftActions')]
    public function testRoutesEveryDraftActionTheClientSends(string $action): void
    {
        $match = $this->matcher()->match('/collaborate/' . $action);

        self::assertSame('collaborate_draft', $match['_route']);
        self::assertSame($action, $match['action']);
    }

    public function testRoutesNoOtherDraftAction(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $this->matcher()->match('/collaborate/purge');
    }

    private function matcher(): UrlMatcher
    {
        $routes = new PhpFileLoader(new FileLocator(\dirname(__DIR__, 3) . '/config'))->load('routes.php');

        return new UrlMatcher($routes, new RequestContext(method: 'POST'));
    }
}
