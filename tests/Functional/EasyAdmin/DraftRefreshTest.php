<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Functional\EasyAdmin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Command\PurgeDraftsCommand;
use Janalis\Collaborate\Controller\DraftController;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Doctrine\ChangeCollector;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Doctrine\TranslationMapperInterface;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Draft\FieldKeyResolverInterface;
use Janalis\Collaborate\EasyAdmin\DraftRefreshSubscriber;
use Janalis\Collaborate\EasyAdmin\EasyAdminFormContext;
use Janalis\Collaborate\EasyAdmin\SaveSubscriber;
use Janalis\Collaborate\EasyAdmin\UpdatesSubscriber;
use Janalis\Collaborate\Form\CollaborateFormExtension;
use Janalis\Collaborate\Form\FormContextInterface;
use Janalis\Collaborate\Gedmo\TranslatableFieldKeyResolver;
use Janalis\Collaborate\Gedmo\TranslationMapper;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\RenewSubscriber;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\Draft\Article;
use Janalis\Collaborate\Tests\Fixtures\Draft\DraftKernel;
use Janalis\Collaborate\Tests\Fixtures\TestUserProvider;
use Janalis\Collaborate\Twig\CollaborateExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\LazyCommand;

/** An open edit page refreshes its grant and CSRF tokens through the edit action itself, without the page. */
#[CoversClass(DraftRefreshSubscriber::class)]
#[CoversClass(EasyAdminFormContext::class)]
#[UsesClass(RoleDraftAccessChecker::class)]
#[UsesClass(PurgeDraftsCommand::class)]
#[UsesClass(DraftController::class)]
#[UsesClass(PageGrant::class)]
#[UsesClass(ChangeCollector::class)]
#[UsesClass(RecordIdentifier::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(DraftJournal::class)]
#[UsesClass(DraftSave::class)]
#[UsesClass(SaveSubscriber::class)]
#[UsesClass(UpdatesSubscriber::class)]
#[UsesClass(CollaborateFormExtension::class)]
#[UsesClass(TranslatableFieldKeyResolver::class)]
#[UsesClass(Grant::class)]
#[UsesClass(HubPublisher::class)]
#[UsesClass(RenewSubscriber::class)]
#[UsesClass(Topics::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
#[UsesClass(CollaborateExtension::class)]
final class DraftRefreshTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return DraftKernel::class;
    }

    public function testTheEditActionAnswersTheFormsRefreshWithoutRenderingThePage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        if (!$em->getConnection()->createSchemaManager()->tablesExist(['draft_fixture_publication'])) {
            new SchemaTool(
                $em,
            )->createSchema(array_values(array_filter($em->getMetadataFactory()->getAllMetadata(), static fn(ClassMetadata $metadata): bool => str_starts_with(
                $metadata->getTableName(),
                'draft_fixture_',
            ))));
        }
        $article = new Article();
        $article->title = 'Draft';
        $em->persist($article);
        $em->flush();
        $em->clear();
        $client->loginUser(new TestUserProvider()->loadUserByIdentifier('alice'));
        $url = \sprintf('/admin/article/%d/edit', $article->id);

        try {
            $client->request('GET', $url, server: ['HTTP_X_COLLABORATE_REFRESH' => '1']);

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/json');
            $response = $client->getResponse();
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            /** @var array{configuration: array<string, mixed>|null, tokens: array<string, string>} $refresh */
            $refresh = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame(['Article[_token]'], array_keys($refresh['tokens']));
            self::assertNotSame('', $refresh['tokens']['Article[_token]']);
            self::assertIsArray($refresh['configuration']);
            self::assertIsString($refresh['configuration']['grant'] ?? null);
            self::assertSame('/admin/collaborate/__action__', $refresh['configuration']['url'] ?? null);
            self::assertFalse($refresh['configuration']['isNew'] ?? null);

            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $shared = $crawler->filter('form[data-collaborate]');
            self::assertCount(1, $shared);
            /** @var array<string, mixed> $page */
            $page = json_decode((string) $shared->attr('data-collaborate'), true, flags: \JSON_THROW_ON_ERROR);
            // The grant is signed with the time of its request, and the CSRF token masked anew;
            // the rest is the page's own configuration.
            unset($page['grant'], $page['csrf'], $refresh['configuration']['grant'], $refresh['configuration']['csrf']);
            self::assertSame($page, $refresh['configuration']);
            self::assertStringContainsString('/admin/article/__record__/edit', (string) ($page['editUrl'] ?? ''));
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM draft_fixture_publication WHERE id = ?', [
                $article->id,
            ]);
        }
    }

    public function testTheDraftServicesAreWiredForEasyAdminAndGedmo(): void
    {
        $kernel = static::bootKernel();
        $container = static::getContainer();

        self::assertInstanceOf(DraftController::class, $container->get(DraftController::class));
        self::assertInstanceOf(EasyAdminFormContext::class, $container->get(FormContextInterface::class));
        self::assertInstanceOf(TranslatableFieldKeyResolver::class, $container->get(FieldKeyResolverInterface::class));
        self::assertInstanceOf(TranslationMapper::class, $container->get(TranslationMapperInterface::class));
        self::assertInstanceOf(UpdatesSubscriber::class, $container->get(UpdatesSubscriber::class));
        self::assertInstanceOf(ChangeCollector::class, $container->get(ChangeCollector::class));
        self::assertInstanceOf(SaveSubscriber::class, $container->get(SaveSubscriber::class));
        $application = new Application($kernel);
        $command = $application->find('collaborate:purge');
        self::assertInstanceOf(LazyCommand::class, $command);
        self::assertInstanceOf(PurgeDraftsCommand::class, $command->getCommand());
    }
}
