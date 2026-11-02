<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit;

use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;
use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Controller\CursorController;
use Janalis\Collaborate\Controller\HubController;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Doctrine\ChangeCollector;
use Janalis\Collaborate\EasyAdmin\EasyAdminFormContext;
use Janalis\Collaborate\EasyAdmin\UpdatesSubscriber;
use Janalis\Collaborate\Form\FormContextInterface;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\RenewSubscriber;
use Janalis\Collaborate\Identity\CollaboratorProviderInterface;
use Janalis\Collaborate\Tests\Fixtures\Arrays;
use Janalis\Collaborate\Twig\CollaborateExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
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
    }

    public function testUpdatesFollowAnEnvDrivenSharedDraftsFlagAtRuntime(): void
    {
        foreach (['1' => true, '0' => false] as $flag => $expected) {
            // As a kernel loads it: the env placeholder reaches the extension as a placeholder string.
            $container = self::container();
            // The bundle prepends the configuration of these extensions.
            foreach (['framework', 'twig', 'doctrine'] as $prepended) {
                $container->registerExtension(new class($prepended) extends Extension {
                    public function __construct(
                        private readonly string $name,
                    ) {}

                    public function getAlias(): string
                    {
                        return $this->name;
                    }

                    public function load(array $configs, ContainerBuilder $container): void {}
                });
            }
            $container->registerExtension($this->extension());
            $container->loadFromExtension('collaborate', ['drafts' => [
                'enabled' => '%env(bool:COLLABORATE_TEST_DRAFTS)%',
            ]]);
            $container->setParameter('env(COLLABORATE_TEST_DRAFTS)', (string) $flag);
            new MergeExtensionConfigurationPass()->process($container);
            $updates = $container->getParameter('collaborate.updates.enabled');
            self::assertSame($container->getParameter('collaborate.drafts.enabled'), $updates);
            self::assertSame($expected, $container->resolveEnvPlaceholders($updates, true));
        }
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
        // A branch without a limiter does not keep the next one from aliasing its own.
        $drafts = $this->load(['drafts' => ['rate_limiter' => 'limiter.draft_requests']]);
        self::assertFalse($drafts->hasAlias('janalis_collaborate.limiter.cursors'));
        self::assertSame('limiter.draft_requests', (string) $drafts->getAlias('janalis_collaborate.limiter.drafts'));

        self::assertEquals(
            new Reference('janalis_collaborate.limiter.cursors', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $container->getDefinition(HubGuard::class)->getArgument(5),
        );
    }

    public function testAliasesThePlaceProviderOnlyWhenConfigured(): void
    {
        $container = $this->load(['cursors' => ['places' => 'app.places']]);
        self::assertSame('app.places', (string) $container->getAlias('janalis_collaborate.places'));
        self::assertFalse($this->load(['cursors' => ['places' => '']])->hasAlias('janalis_collaborate.places'));
        self::assertFalse($this->load([])->hasAlias('janalis_collaborate.places'));
        $places = new Reference('janalis_collaborate.places', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        self::assertEquals($places, $container->getDefinition(HubGuard::class)->getArgument(6));
        self::assertEquals($places, $container->getDefinition(PageGrant::class)->getArgument(4));
        self::assertSame(
            CollaborateBundle::IGNORED_PARAMETERS,
            $container->getParameter('collaborate.cursors.ignored_parameters'),
        );
    }

    public function testASentenceKeepsItsPlaceholders(): void
    {
        // `%%name%%` in YAML: the configuration reaches the extension resolved.
        $container = $this->load(['cursors' => ['popups' => ['share' => ['where' => 'in the sharing of %name%']]]]);
        // As a compiled container holds them: resolved, then unescaped.
        $bag = $container->getParameterBag();
        self::assertSame(
            ['share' => ['where' => 'in the sharing of %name%', 'line' => 'Also open by %names%.']],
            $bag->unescapeValue($bag->resolveValue('%collaborate.cursors.popups%')),
        );
        self::assertSame(
            'in the sharing of %name%',
            $bag->unescapeValue($bag->resolveValue('%collaborate.cursors.popups.share.where%')),
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

    public function testImportsTheEasyAdminServicesOnlyWhenItsBundleIsRegistered(): void
    {
        $registered = $this->load([], ['EasyAdminBundle' => EasyAdminBundle::class]);
        foreach (['core.php', 'hub.php', 'cursors.php', 'drafts.php', 'easyadmin.php', 'gedmo.php'] as $file) {
            self::assertContains($file, $this->files($registered));
        }
        self::assertTrue($registered->hasDefinition(EasyAdminFormContext::class));
        // Installed (a dev dependency here) but not registered: no adapter, no form context.
        foreach ([$this->load([], ['FrameworkBundle' => FrameworkBundle::class]), $this->load([])] as $plain) {
            self::assertNotContains('easyadmin.php', $this->files($plain));
            self::assertFalse($plain->hasDefinition(EasyAdminFormContext::class));
            self::assertFalse($plain->hasAlias(FormContextInterface::class));
            self::assertContains('drafts.php', $this->files($plain));
            // A host's own save announces its draft's checkpoint: the change collection does not need EasyAdmin.
            self::assertTrue($plain->getDefinition(ChangeCollector::class)->hasTag('doctrine.event_listener'));
            self::assertTrue($plain->getDefinition(UpdatesSubscriber::class)->hasTag('kernel.event_subscriber'));
        }
    }

    /** A container as a kernel starts it; Symfony 7.4.0 reads the environment while loading a bundle extension. */
    private static function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        return $container;
    }

    /** @return list<string> the basenames of the files the container loaded */
    private function files(ContainerBuilder $container): array
    {
        return array_map(static fn(FileResource $resource): string => basename(
            $resource->getResource(),
        ), array_values(array_filter($container->getResources(), static fn(object $resource): bool => $resource instanceof FileResource)));
    }

    /**
     * @param array<string, mixed>        $config
     * @param array<string, class-string>|null $bundles `kernel.bundles`, unset when null
     */
    private function load(array $config, ?array $bundles = null): ContainerBuilder
    {
        $container = self::container();
        if (null !== $bundles) {
            $container->setParameter('kernel.bundles', $bundles);
        }
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
