<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Bridge;

use Janalis\Collaborate\Bridge\Bridge;
use Janalis\Collaborate\CollaborateBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Bridge::class)]
final class BridgeTest extends TestCase
{
    private const string EASYADMIN = 'EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle';

    /** @return iterable<string, array{string, list<string>|null, Bridge}> */
    public static function resolutions(): iterable
    {
        yield 'auto with EasyAdmin registered' => ['auto', [self::EASYADMIN], Bridge::EasyAdmin];
        yield 'auto without it' => ['auto', ['App\Bundle'], Bridge::None];
        yield 'auto with no bundles known' => ['auto', null, Bridge::None];
        yield 'none with EasyAdmin registered' => ['none', [self::EASYADMIN], Bridge::None];
        yield 'easyadmin registered' => ['easyadmin', [self::EASYADMIN], Bridge::EasyAdmin];
        yield 'easyadmin with no bundles known' => ['easyadmin', null, Bridge::EasyAdmin];
        yield 'drupal' => ['drupal', [], Bridge::Drupal];
    }

    /** @param list<string>|null $bundles */
    #[DataProvider('resolutions')]
    public function testResolvesTheConfiguredBridge(string $configured, ?array $bundles, Bridge $expected): void
    {
        self::assertSame($expected, Bridge::resolve($configured, $bundles));
    }

    public function testTheEasyAdminBridgeNeedsItsBundle(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs easycorp/easyadmin-bundle');
        Bridge::resolve('easyadmin', ['App\Bundle']);
    }

    public function testAnUnknownBridgeIsRefused(): void
    {
        $this->expectException(\ValueError::class);
        Bridge::resolve('sonata', null);
    }

    public function testEachBridgeNamesItsRegionsFromTheWidestToTheBody(): void
    {
        foreach (Bridge::cases() as $bridge) {
            $regions = $bridge->regions();
            self::assertSame('body', $regions['body'], $bridge->value);
            self::assertSame('body', array_key_last($regions), $bridge->value);
            self::assertArrayHasKey('modal', $regions, $bridge->value);
            self::assertArrayHasKey('content', $regions, $bridge->value);
        }
    }

    public function testTheEasyAdminRegionsAreTheRegionsOfBefore(): void
    {
        // @mago-expect analysis:deprecated-class-constant the constant of before 0.5 holds the EasyAdmin bridge's
        self::assertSame(CollaborateBundle::REGIONS, Bridge::EasyAdmin->regions());
        self::assertSame('.ea-sidebar, .sidebar', Bridge::EasyAdmin->regions()['sidebar']);
        self::assertSame('.ui-dialog', Bridge::Drupal->regions()['modal']);
        self::assertSame('main, [role=main]', Bridge::None->regions()['content']);
    }
}
