<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit;

use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Controller\CursorController;
use Janalis\Collaborate\Controller\HubController;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\RenewSubscriber;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Tests\Fixtures\Arrays;
use Janalis\Collaborate\Twig\CollaborateExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(CollaborateBundle::class)]
final class CollaborateBundleTest extends TestCase
{
    public function testEveryOptionBecomesAParameter(): void
    {
        $container = $this->load([
            'colors' => ['red', 'green'],
            'hub' => ['name' => 'collaboration', 'max_drafts' => 4],
            'drafts' => ['compact' => ['idle' => 30]],
        ]);
        self::assertSame(['red', 'green'], $container->getParameter('collaborate.colors'));
        self::assertSame('collaboration', $container->getParameter('collaborate.hub.name'));
        self::assertSame(4, $container->getParameter('collaborate.hub.max_drafts'));
        self::assertSame(30, $container->getParameter('collaborate.drafts.compact.idle'));
        self::assertSame(1_048_576, $container->getParameter('collaborate.drafts.compact.bytes'));
        // Lists stay whole, branches are parameters too.
        self::assertSame([], $container->getParameter('collaborate.drafts.rich_text.block_prefixes'));
        self::assertFalse($container->hasParameter('collaborate.colors.0'));
        $hub = Arrays::at($container->getParameter('collaborate.hub'));
        self::assertSame('collaboration', $hub['name']);
        $root = Arrays::at($container->getParameter('collaborate'));
        self::assertSame('ROLE_ADMIN', $root['role']);
        self::assertNull($container->getParameter('collaborate.drafts.rich_text.schema_option'));
    }

    public function testUpdatesFollowSharedDraftsUnlessSet(): void
    {
        self::assertFalse($this->load([])->getParameter('collaborate.updates.enabled'));
        self::assertTrue($this->load(['drafts' => true])->getParameter('collaborate.updates.enabled'));
        self::assertFalse($this->load(['drafts' => true, 'updates' => ['enabled' => false]])->getParameter(
            'collaborate.updates.enabled',
        ));
        self::assertTrue($this->load(['updates' => ['enabled' => true]])->getParameter('collaborate.updates.enabled'));
        // Changes are collected for saved views or open drafts, whichever is on.
        self::assertFalse($this->load([])->getParameter('collaborate.updates.collect'));
        self::assertTrue($this->load(['drafts' => true, 'updates' => ['enabled' => false]])->getParameter(
            'collaborate.updates.collect',
        ));
        self::assertTrue($this->load(['updates' => ['enabled' => true]])->getParameter('collaborate.updates.collect'));
    }

    public function testAliasesTheConfiguredServices(): void
    {
        $container = $this->load([
            'identity' => 'app.collaborators',
            'access' => 'app.draft_access',
            'hub' => ['name' => 'collaboration'],
            'drafts' => ['grant_cache' => 'cache.grants'],
        ]);
        self::assertSame('app.collaborators', (string) $container->getAlias(CollaboratorProviderInterface::class));
        self::assertSame('app.draft_access', (string) $container->getAlias(DraftAccessCheckerInterface::class));
        self::assertSame('cache.grants', (string) $container->getAlias('janalis_collaborate.grant_cache'));
        self::assertSame('mercure.hub.collaboration', (string) $container->getAlias('janalis_collaborate.hub'));
        self::assertFalse($container->hasAlias('janalis_collaborate.limiter.cursors'));
        self::assertFalse($container->hasAlias('janalis_collaborate.limiter.drafts'));
    }

    public function testAliasesTheRateLimitersOnlyWhenConfigured(): void
    {
        $container = $this->load([
            'cursors' => ['rate_limiter' => 'limiter.cursor_events'],
            'drafts' => ['rate_limiter' => 'limiter.draft_requests'],
        ]);
        self::assertSame('limiter.cursor_events', (string) $container->getAlias('janalis_collaborate.limiter.cursors'));
        self::assertSame('limiter.draft_requests', (string) $container->getAlias('janalis_collaborate.limiter.drafts'));
        self::assertFalse($this->load(['cursors' => ['rate_limiter' => '']])->hasAlias(
            'janalis_collaborate.limiter.cursors',
        ));

        self::assertEquals(
            new Reference('janalis_collaborate.limiter.cursors', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $container->getDefinition(HubGuard::class)->getArgument(5),
        );
    }

    public function testRegistersTheHubServicesAndTheCursorControllerEvenWithCursorsDisabled(): void
    {
        $container = $this->load(['cursors' => false]);
        self::assertFalse($container->getParameter('collaborate.cursors.enabled'));
        foreach ([HubController::class, CursorController::class] as $controller) {
            self::assertTrue($container->getDefinition($controller)->hasTag('controller.service_arguments'));
        }
        self::assertTrue($container->getDefinition(RenewSubscriber::class)->hasTag('kernel.event_subscriber'));
        self::assertTrue($container->getDefinition(CollaborateExtension::class)->hasTag('twig.extension'));
        self::assertSame(
            '%collaborate.cursors.enabled%',
            $container->getDefinition(CursorController::class)->getArgument(3),
        );
    }

    public function testImportsTheAdapterServicesOfTheInstalledLibraries(): void
    {
        $files = array_map(static fn(FileResource $resource): string => basename(
            $resource->getResource(),
        ), array_values(array_filter($this->load([])->getResources(), static fn(object $resource): bool => $resource instanceof FileResource)));
        foreach (['core.php', 'hub.php', 'cursors.php', 'drafts.php', 'easyadmin.php', 'gedmo.php'] as $file) {
            self::assertContains($file, $files);
        }
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $this->extension()->load([$config], $container);

        return $container;
    }

    private function extension(): ExtensionInterface
    {
        $extension = new CollaborateBundle()->getContainerExtension();
        self::assertNotNull($extension);

        return $extension;
    }
}
