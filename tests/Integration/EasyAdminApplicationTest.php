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
use Janalis\Collaborate\Tests\Application\EasyAdmin\EasyAdminKernel;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Article;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Category;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Tag;
use Janalis\Collaborate\Twig\CollaborateExtension;
use PHPUnit\Framework\Attributes\CoversClass;

/** The bundle in an EasyAdmin application: its adapter is the form context, and saved lists refresh. */
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
#[CoversClass(DraftRefreshSubscriber::class)]
#[CoversClass(EasyAdminFormContext::class)]
#[CoversClass(SaveSubscriber::class)]
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
final class EasyAdminApplicationTest extends CollaborationTestCase
{
    protected static function kernel(): string
    {
        return EasyAdminKernel::class;
    }

    protected function formName(): string
    {
        return 'Article';
    }

    protected function editUrl(int $id): string
    {
        return \sprintf('/admin/article/%d/edit', $id);
    }

    protected function newUrl(): string
    {
        return '/admin/article/new';
    }

    protected function saveButton(bool $new): string
    {
        return $new ? 'Create' : 'Save changes';
    }

    public function testTheEasyAdminAdapterIsWired(): void
    {
        $container = static::getContainer();
        self::assertInstanceOf(EasyAdminFormContext::class, $container->get(FormContextInterface::class));
        foreach ([
            DraftRefreshSubscriber::class,
            SaveSubscriber::class,
            ChangeCollector::class,
            UpdatesSubscriber::class,
        ] as $service) {
            self::assertTrue($container->has($service), $service);
        }
    }

    public function testASaveAnnouncesTheSavedViewsItChanged(): void
    {
        $id = $this->article('Listed');
        $this->signIn('alice');
        $page = $this->open($this->editUrl($id));
        $state = $this->draft(self::configuration($page), 'join');
        $this->save($page, ['Article[title]' => 'Listed and renamed'], $state);
        self::assertResponseRedirects();

        $updates = self::on($this->published(), 'urn:collaborate:updates');
        self::assertCount(1, $updates);
        self::assertSame('updates', $updates[0]['channel']);
        self::assertSame('saved', $updates[0]['kind']);
        // The saved class and the classes one association away, by EasyAdmin entity name.
        self::assertSame(['Article', 'Category', 'Tag'], $updates[0]['entities']);
        self::assertTrue(class_exists(Tag::class) && class_exists(Article::class) && class_exists(Category::class));

        // The index page lists the new title, for the refresh to show.
        self::assertSelectorTextContains(
            'body',
            'Listed and renamed',
            (string) $this->client->followRedirect()->html(),
        );
    }

    public function testAPageOutsideAnEditFormSharesNoDraft(): void
    {
        $this->signIn('alice');
        $page = $this->open('/admin/article');

        self::assertCount(1, $page->filter('[data-collaborate-layer][data-updates="true"]'));
        self::assertCount(0, $page->filter('form[data-collaborate]'));
        self::assertStringContainsString('ea-index-Article', (string) $page->filter('body')->attr('class'));
    }
}
