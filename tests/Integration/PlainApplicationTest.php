<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Integration;

use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\CollaborateBundle;
use Janalis\Collaborate\Command\PurgeDraftsCommand;
use Janalis\Collaborate\Controller\CursorController;
use Janalis\Collaborate\Controller\DraftController;
use Janalis\Collaborate\Controller\HubController;
use Janalis\Collaborate\Cursor\CursorEvent;
use Janalis\Collaborate\Cursor\CursorPosition;
use Janalis\Collaborate\Cursor\PageGrant;
use Janalis\Collaborate\Doctrine\ChangeCollector;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Draft\DraftText;
use Janalis\Collaborate\Draft\FieldKeyResolver;
use Janalis\Collaborate\EasyAdmin\DraftRefreshSubscriber;
use Janalis\Collaborate\EasyAdmin\EasyAdminFormContext;
use Janalis\Collaborate\EasyAdmin\SaveSubscriber;
use Janalis\Collaborate\EasyAdmin\UpdatesSubscriber;
use Janalis\Collaborate\Entity\Draft;
use Janalis\Collaborate\Entity\DraftEvent;
use Janalis\Collaborate\Entity\DraftField;
use Janalis\Collaborate\Form\CollaborateFormExtension;
use Janalis\Collaborate\Form\FormContextInterface;
use Janalis\Collaborate\Gedmo\TranslatableFieldKeyResolver;
use Janalis\Collaborate\Gedmo\TranslationMapper;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Admission;
use Janalis\Collaborate\Hub\HubGuard;
use Janalis\Collaborate\Hub\HubPublisher;
use Janalis\Collaborate\Hub\RenewSubscriber;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Application\Plain\PlainFormContext;
use Janalis\Collaborate\Tests\Application\Plain\PlainKernel;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Article;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Tag;
use Janalis\Collaborate\Tests\Application\Shared\Form\ArticleType;
use Janalis\Collaborate\Tests\Application\Shared\Form\TagType;
use Janalis\Collaborate\Tests\Fixtures\TestUserProvider;
use Janalis\Collaborate\Twig\CollaborateExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

/** The bundle in an application that installs EasyAdmin without registering it, with its own admin pages. */
#[CoversClass(RoleDraftAccessChecker::class)]
#[CoversClass(CollaborateBundle::class)]
#[CoversClass(PurgeDraftsCommand::class)]
#[CoversClass(CursorController::class)]
#[CoversClass(DraftController::class)]
#[CoversClass(HubController::class)]
#[CoversClass(CursorEvent::class)]
#[CoversClass(CursorPosition::class)]
#[CoversClass(PageGrant::class)]
#[CoversClass(ChangeCollector::class)]
#[CoversClass(RecordIdentifier::class)]
#[CoversClass(DraftAccess::class)]
#[CoversClass(DraftJournal::class)]
#[CoversClass(DraftSave::class)]
#[CoversClass(DraftText::class)]
#[CoversClass(FieldKeyResolver::class)]
#[CoversClass(UpdatesSubscriber::class)]
#[CoversClass(Draft::class)]
#[CoversClass(DraftEvent::class)]
#[CoversClass(DraftField::class)]
#[CoversClass(CollaborateFormExtension::class)]
#[CoversClass(TranslatableFieldKeyResolver::class)]
#[CoversClass(TranslationMapper::class)]
#[CoversClass(Grant::class)]
#[CoversClass(Admission::class)]
#[CoversClass(HubGuard::class)]
#[CoversClass(HubPublisher::class)]
#[CoversClass(RenewSubscriber::class)]
#[CoversClass(Topics::class)]
#[CoversClass(SecurityCollaboratorProvider::class)]
#[CoversClass(CollaborateExtension::class)]
final class PlainApplicationTest extends CollaborationTestCase
{
    protected static function kernel(): string
    {
        return PlainKernel::class;
    }

    protected function formName(): string
    {
        return 'article';
    }

    protected function editUrl(int $id): string
    {
        return \sprintf('/admin/articles/%d/edit', $id);
    }

    protected function newUrl(): string
    {
        return '/admin/articles/new';
    }

    protected function saveButton(bool $new): string
    {
        return 'Save';
    }

    public function testTheBundleRunsWithoutEasyAdminOnTheHostsFormContext(): void
    {
        $container = static::getContainer();
        $bundles = $container->getParameter('kernel.bundles');
        self::assertIsArray($bundles);
        self::assertArrayNotHasKey('EasyAdminBundle', $bundles);
        self::assertTrue(class_exists(EasyAdminFormContext::class));

        self::assertInstanceOf(PlainFormContext::class, $container->get(FormContextInterface::class));
        foreach ([EasyAdminFormContext::class, DraftRefreshSubscriber::class, SaveSubscriber::class] as $service) {
            self::assertFalse($container->has($service), $service);
        }
        // A save announces its draft's checkpoint without EasyAdmin.
        self::assertInstanceOf(ChangeCollector::class, $container->get(ChangeCollector::class));
        self::assertInstanceOf(UpdatesSubscriber::class, $container->get(UpdatesSubscriber::class));
    }

    public function testADisabledCollectionKeepsItsRowsUnmarkedOnASharedPage(): void
    {
        $article = new Article('Locked tags');
        $article->addTag(new Tag('first'));
        $article->addTag(new Tag('second', 1));
        $em = $this->em();
        $em->persist($article);
        $em->flush();
        self::assertNotNull($article->id);
        $container = static::getContainer();
        $request = Request::create('/admin/articles/' . $article->id . '/edit');
        $request->attributes->set(PlainFormContext::RECORD, [$article, false]);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requests = $container->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);
        $user = new TestUserProvider()->loadUserByIdentifier('alice');
        $tokens = $container->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $forms = $container->get(FormFactoryInterface::class);
        self::assertInstanceOf(FormFactoryInterface::class, $forms);
        $twig = $container->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        // The host locks the tags of this article: they render, read-only.
        $form = $forms
            ->createNamedBuilder('article', ArticleType::class, $article)
            ->add('tags', CollectionType::class, [
                'entry_type' => TagType::class,
                'disabled' => true,
            ])
            ->getForm();

        $page = new Crawler($twig->createTemplate('{{ form(form) }}')->render(['form' => $form->createView()]));

        self::assertCount(1, $page->filter('form[data-collaborate]'));
        self::assertCount(0, $page->filter('[data-collaborate-collection]'));
        self::assertSame(
            ['first', 'second'],
            $page->filter(
                'input[name$="[name]"][disabled]',
            )->each(static fn(Crawler $input): string => (string) $input->attr('value')),
        );
        $configuration = json_decode(
            (string) $page->filter('form')->attr('data-collaborate'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($configuration);
        self::assertIsString($configuration['grant'] ?? null);
        $schema = self::schema($configuration['grant']);
        self::assertArrayHasKey('article[title]', $schema);
        self::assertSame([], array_filter(array_keys($schema), static fn(string $key): bool => str_contains(
            $key,
            '[tags]',
        )));
    }
}
