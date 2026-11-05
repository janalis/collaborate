<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Form;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata as PersistenceClassMetadata;
use Doctrine\Persistence\ObjectManager;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\CrudAutocompleteType;
use Janalis\Collaborate\Access\DraftAccessCheckerInterface;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Form\CollaborateFormExtension;
use Janalis\Collaborate\Gedmo\TranslatableFieldKeyResolver;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\Draft\Account;
use Janalis\Collaborate\Tests\Fixtures\Draft\Article;
use Janalis\Collaborate\Tests\Fixtures\Draft\Membership;
use Janalis\Collaborate\Tests\Fixtures\Draft\PageContext;
use Janalis\Collaborate\Tests\Fixtures\Draft\PositionedEntryType;
use Janalis\Collaborate\Tests\Fixtures\Draft\RichTextType;
use Janalis\Collaborate\Tests\Fixtures\Draft\Tag;
use Janalis\Collaborate\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * @phpstan-import-type SignedScope from DraftAccess
 * @phpstan-import-type Options from CollaborateFormExtension
 */
#[CoversClass(CollaborateFormExtension::class)]
#[UsesClass(RecordIdentifier::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(TranslatableFieldKeyResolver::class)]
#[UsesClass(Grant::class)]
#[UsesClass(Topics::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
#[AllowMockObjectsWithoutExpectations]
final class CollaborateFormExtensionTest extends TypeTestCase
{
    private CollaborateFormExtension $extension;
    private Article $article;
    private Request $request;
    private PageContext $page;
    private TestUser $user;
    private bool $granted = true;
    private bool $canEdit = true;
    /** @var array<string, list<string>> what the edited record's draft saved for its plain lists */
    private array $listKeys = [];
    /** @var array<string, array<array-key, string>> the keys the edited record's draft gave its collections' entries, by record id */
    private array $aliases = [];
    private DraftSave&MockObject $save;
    /** @var \Closure(bool, ?ImportMapConfigReader=, ?LoggerInterface=, Options=, bool=, RecordIdentifier=, ?DraftAccess=, bool=, bool=): CollaborateFormExtension */
    private \Closure $extensionFactory;
    private ImportMapConfigReader $importMap;
    /** @var list<string> */
    private array $importMapFiles = [];

    protected function setUp(): void
    {
        $this->article = new Article();
        $this->article->title = 'Title';
        $this->request = Request::create('/admin/article/new');
        $this->request->setLocale('en');
        $requests = new RequestStack();
        $requests->push($this->request);
        $this->page = new PageContext($requests, $this->article);
        $this->user = new TestUser(7, 'alice', ['ROLE_ADMIN'], 'Alice');
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturnCallback(fn(): UserInterface => $this->user);
        $security
            ->method('isGranted')
            ->willReturnCallback(fn(mixed $role): bool => $this->granted && 'ROLE_EDITOR' === $role);
        $checker = $this->createStub(DraftAccessCheckerInterface::class);
        $checker->method('canEdit')->willReturnCallback(fn(): bool => $this->canEdit);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn(
            string $route,
            array $parameters,
        ): string => 'collaborate_draft' === $route && \is_string($parameters['action'] ?? null)
            ? '/admin/collaborate/' . $parameters['action']
            : '/elsewhere');
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturnCallback(static fn(string $id): CsrfToken => new CsrfToken(
            $id,
            $id . '-token',
        ));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn(
            string $id,
            array $parameters = [],
            ?string $domain = null,
        ): string => 'collaborate' === $domain ? '[' . $id . ']' : 'not in the collaborate domain');
        $journal = $this->createStub(DraftJournal::class);
        $journal->method('aliases')->willReturnCallback(fn(): array => $this->aliases);
        $journal->method('listKeys')->willReturnCallback(fn(): array => $this->listKeys);
        $this->save = $this->createMock(DraftSave::class);
        $this->importMap = $this->importMap(['ckeditor5' => ['version' => '49.1.0']]);
        $this->extensionFactory = /** @param Options $options */ fn(
            bool $enabled,
            ?ImportMapConfigReader $importMap = null,
            ?LoggerInterface $logger = null,
            array $options = [],
            bool $context = true,
            RecordIdentifier $identifier = new RecordIdentifier(),
            ?DraftAccess $access = null,
            bool $importMapped = true,
            bool $defaults = true,
        ): CollaborateFormExtension => new CollaborateFormExtension(
            $access ?? $this->access($enabled),
            $journal,
            $this->save,
            $context ? $this->page : null,
            $requests,
            $security,
            new SecurityCollaboratorProvider(),
            $checker,
            $urls,
            $csrf,
            $translator,
            $identifier,
            new TranslatableFieldKeyResolver($requests, 'en'),
            $importMapped ? $importMap ?? $this->importMap : null,
            'ROLE_EDITOR',
            $options
            + (
                $defaults
                    ? [
                        'rich_text' => ['block_prefixes' => ['rich_text'], 'schema_option' => 'compact'],
                        'replay' => ['connected' => 20, 'disconnected' => 4, 'join_retry' => 10],
                        'label_length' => 120,
                    ] : []
            ),
            $logger ?? new NullLogger(),
        );
        $this->extension = ($this->extensionFactory)(true);
        parent::setUp();
    }

    private function access(bool $enabled = true, int $maxRequestBytes = 2_097_152): DraftAccess
    {
        $checker = $this->createStub(DraftAccessCheckerInterface::class);
        $checker->method('canEdit')->willReturn(true);

        return new DraftAccess(
            'secret',
            $enabled,
            new Topics(),
            new SecurityCollaboratorProvider(),
            $checker,
            maxRequestBytes: $maxRequestBytes,
        );
    }

    /** Rebuilds the form factory around the current extension. */
    private function rebuild(): void
    {
        $this->factory = Forms::createFormFactoryBuilder()
            ->addExtensions($this->getExtensions())
            ->addTypeExtensions($this->getTypeExtensions())
            ->addTypes($this->getTypes())
            ->getFormFactory();
    }

    protected function getTypeExtensions(): array
    {
        return [$this->extension];
    }

    protected function getTypes(): array
    {
        return [new PositionedEntryType(), new RichTextType()];
    }

    /** @return array{grant: string, url: string, csrf: string, invitation: string, editUrl: string, isNew: bool, messages: array<string, string>, config: array<string, int>} */
    private function configuration(FormView $view): array
    {
        $attributes = self::vars($view, 'attr');

        /** @var array{grant: string, url: string, csrf: string, invitation: string, editUrl: string, isNew: bool, messages: array<string, string>, config: array<string, int>} $configuration */
        $configuration = json_decode((string) $attributes['data-collaborate'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($configuration);

        return $configuration;
    }

    /** @return array<array-key, mixed> A view's attributes: Symfony defaults them to an array. */
    private static function vars(FormView $view, string $name): array
    {
        self::assertIsArray($view->vars[$name]);

        return $view->vars[$name];
    }

    /** @return SignedScope */
    private function scope(FormView $view): array
    {
        return $this->access()->verify($this->configuration($view)['grant'], $this->user, time());
    }

    /** @param array<string, mixed> $bodyOptions */
    private function form(array $bodyOptions = []): FormInterface
    {
        return $this->factory
            ->createNamedBuilder('Article', FormType::class, $this->article, ['data_class' => Article::class])
            ->add('title', TextType::class)
            ->add('body', RichTextType::class, $bodyOptions)
            ->add('privateToken', HiddenType::class, ['mapped' => false])
            ->add('password', PasswordType::class, ['mapped' => false])
            ->getForm();
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->importMapFiles);
        parent::tearDown();
    }

    /** @param array<string, array<string, string>> $entries */
    private function importMap(array $entries): ImportMapConfigReader
    {
        $path = tempnam(sys_get_temp_dir(), 'importmap');
        self::assertIsString($path);
        $this->importMapFiles[] = $path;
        file_put_contents($path, '<?php return ' . var_export($entries, true) . ';');

        return new ImportMapConfigReader($path, new RemotePackageStorage(sys_get_temp_dir()));
    }

    public function testEditorSchemaRequiresAPinnedCkeditor(): void
    {
        $this->extension = ($this->extensionFactory)(true, $this->importMap([]));
        $this->rebuild();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Set collaborate.drafts.rich_text.ckeditor_version, or pin a ckeditor5 version in importmap.php.',
        );
        $this->form()->createView();
    }

    public function testAConfiguredCkeditorVersionNeedsNoImportMap(): void
    {
        $extension = new \ReflectionProperty(CollaborateFormExtension::class, 'editorVersion');
        $this->extension = ($this->extensionFactory)(true, $this->importMap([]), null, [
            'rich_text' => ['block_prefixes' => ['rich_text'], 'ckeditor_version' => '48.0.0'],
        ]);
        self::assertSame('48.0.0', $extension->getValue($this->extension));
        $this->rebuild();

        $fields = $this->scope($this->form(['compact' => true])->createView())['fields'];

        // Without a schema option every editor is the full build.
        self::assertSame('ckeditor5-48.0.0:collaborate-2:rich:full', $fields['en:Article[body]']['schema'] ?? null);
        // The configured version prevails over the import map's, and needs no import map at all.
        foreach ([null, false] as $importMapped) {
            $this->extension = ($this->extensionFactory)(
                true,
                null,
                null,
                [
                    'rich_text' => ['block_prefixes' => ['rich_text'], 'ckeditor_version' => '48.0.0'],
                ],
                true,
                new RecordIdentifier(),
                null,
                null === $importMapped,
            );
            $this->rebuild();
            self::assertSame(
                'ckeditor5-48.0.0:collaborate-2:text:full',
                $this->scope($this->form()->createView())['fields']['en:Article[title]']['schema'] ?? null,
            );
        }
    }

    public function testWithoutAnImportMapTheCkeditorVersionMustBeConfigured(): void
    {
        $this->extension = ($this->extensionFactory)(true, null, null, [], true, new RecordIdentifier(), null, false);
        $this->rebuild();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Set collaborate.drafts.rich_text.ckeditor_version');
        $this->form()->createView();
    }

    public function testCapabilityContainsOnlyEditableFieldsAndKeepsTheExistingEditor(): void
    {
        $view = $this->form()->createView();
        $configuration = $this->configuration($view);
        $scope = $this->scope($view);
        self::assertSame(Article::class, $scope['entity']);
        self::assertSame(DraftAccessCheckerInterface::CREATE, $scope['permission']);
        self::assertSame('rich', $scope['fields']['en:Article[body]']['type']);
        self::assertSame(
            'ckeditor5-49.1.0:collaborate-2:rich:full',
            $scope['fields']['en:Article[body]']['schema'] ?? null,
        );
        self::assertSame(
            'ckeditor5-49.1.0:collaborate-2:text:full',
            $scope['fields']['en:Article[title]']['schema'] ?? null,
        );
        self::assertSame('Article[body]', $scope['fields']['en:Article[body]']['formName'] ?? null);
        self::assertSame(
            ['type' => 'value', 'initial' => 'wysiwyg', 'formName' => 'Article[body][__mode__]'],
            $scope['fields']['en:Article[body][__mode__]'],
        );
        self::assertArrayNotHasKey('Article[privateToken]', $scope['fields']);
        self::assertArrayNotHasKey('Article[password]', $scope['fields']);
        self::assertSame('/admin/collaborate/__action__', $configuration['url']);
        self::assertSame('collaborate-draft-token', $configuration['csrf']);
        self::assertTrue($configuration['isNew']);
        self::assertSame('/admin/article/__record__/edit', $configuration['editUrl']);
        self::assertStringContainsString('draft=', (string) $view->vars['action']);
        self::assertStringContainsString('draft=', $configuration['invitation']);
        self::assertSame(
            ['replayConnected' => 20_000, 'replayDisconnected' => 4000, 'joinRetry' => 10_000, 'labelLength' => 120],
            $configuration['config'],
        );
        $messages = $configuration['messages'];
        // The bar's states, its dialogs and the notices name who did what with a %name% placeholder.
        self::assertSame(array_keys(CollaborateFormExtension::MESSAGES), array_keys($messages));
        // Each in the page's language, from the collaborate domain.
        self::assertSame('[All changes saved by %name%.]', $messages['savedBy']);
        self::assertSame('[Discard unsaved changes?]', $messages['confirmDiscardTitle']);
        self::assertSame('[Use the saved record?]', $messages['confirmSavedTitle']);
        self::assertSame('[Copy invitation link]', $messages['copyInvitation']);
        self::assertArrayNotHasKey('confirmDiscard', $messages);
    }

    public function testTheSchemaOptionSelectsTheReducedEditor(): void
    {
        $fields = $this->scope($this->form(['compact' => true])->createView())['fields'];

        self::assertSame('ckeditor5-49.1.0:collaborate-2:rich:basic', $fields['en:Article[body]']['schema'] ?? null);
        // A plain text control has no such option.
        self::assertSame('ckeditor5-49.1.0:collaborate-2:text:full', $fields['en:Article[title]']['schema'] ?? null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedDraftKeys(): iterable
    {
        yield 'not hex' => ['manualcheck', '&page=2'];
        yield 'too short' => ['abc123', ''];
        yield 'prefixed' => ['x' . str_repeat('ab', 16), ''];
        yield 'suffixed' => [str_repeat('ab', 16) . 'x', ''];
        yield 'with a trailing line feed' => [str_repeat('ab', 16) . "\n", ''];
    }

    #[DataProvider('malformedDraftKeys')]
    public function testANewFormPostsTheDraftItsGrantWasIssuedFor(string $key, string $rest): void
    {
        // The page's own key is drawn again on every request: the form posts the one it was shown with.
        $this->request->server->set('REQUEST_URI', '/admin/article/new?draft=' . rawurlencode($key) . $rest);
        $this->request->query->set('draft', $key);
        if ('' !== $rest) {
            $this->request->query->set('page', '2');
        }

        $view = $this->form()->createView();

        $configuration = $this->configuration($view);
        $scope = $this->scope($view);
        $action = [];
        $invitation = [];
        parse_str((string) parse_url((string) $view->vars['action'], \PHP_URL_QUERY), $action);
        parse_str((string) parse_url($configuration['invitation'], \PHP_URL_QUERY), $invitation);
        self::assertIsString($action['draft'] ?? null);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $action['draft']);
        self::assertSame($action['draft'], $invitation['draft'] ?? null);
        self::assertNotSame($key, $action['draft']);
        self::assertSame('/admin/article/new', parse_url((string) $view->vars['action'], \PHP_URL_PATH));
        self::assertSame($this->access()->draftId(Article::class, 'new:' . $action['draft']), $scope['id']);
        if ('' !== $rest) {
            self::assertSame('2', $action['page'] ?? null);
            // The invitation opens the same page, with the draft's key.
            self::assertSame('2', $invitation['page'] ?? null);
        }
        // The same request renders the same key again.
        self::assertSame($scope['id'], $this->scope($this->form()->createView())['id']);
    }

    public function testANewFormKeepsAWellFormedDraftKeyInItsAction(): void
    {
        $key = str_repeat('ab', 16);
        $this->request->server->set('REQUEST_URI', '/admin/article/new?draft=' . $key);
        $this->request->query->set('draft', $key);

        $view = $this->form()->createView();

        // An empty action posts to the page's own URL, which carries the key.
        self::assertSame('', $view->vars['action']);
        self::assertSame($this->access()->draftId(Article::class, 'new:' . $key), $this->scope($view)['id']);
    }

    public function testAFormTooLargeToShareIsEditedWithoutADraftAndLogged(): void
    {
        // Its grant, sent with one update, would exceed the collaboration request limit.
        $this->article->body = str_repeat('x', 1_600_000);
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                'The {entity} form is too large to share as a draft; it is edited without one.',
                self::callback(
                    static fn(array $context): bool => (
                        Article::class === $context['entity']
                        && \is_int($context['grant_bytes'] ?? null)
                        && $context['grant_bytes'] > (2_097_152 - 262_144)
                    ),
                ),
            );
        $this->extension = ($this->extensionFactory)(true, null, $logger);
        $this->rebuild();

        $view = $this->form()->createView();

        self::assertArrayNotHasKey('data-collaborate', self::vars($view, 'attr'));
        self::assertStringNotContainsString('draft=', (string) $view->vars['action']);
    }

    public function testTextValuesUseTheLineBreaksOfTheBrowserControl(): void
    {
        // A textarea's value reads LF; a CRLF baseline would make the first keystroke rewrite every line.
        $this->article->title = "a\r\nb\rc";
        $this->article->body = "<p>a</p>\r\n<p>b</p>";
        $scope = $this->scope($this->form()->createView());
        self::assertSame("a\nb\nc", $scope['fields']['en:Article[title]']['initial']);
        self::assertSame("<p>a</p>\n<p>b</p>", $scope['fields']['en:Article[body]']['initial']);
    }

    public function testSameRecordUsesOneRoomButSeparatesTranslatedValuesByLocale(): void
    {
        $this->page->page = PageContext::EDIT;
        $english = $this->scope($this->form()->createView());
        $this->request->setLocale('fr');
        $translated = $this->scope($this->form()->createView());
        self::assertSame($this->access()->draftId(Article::class, '7'), $english['id']);
        self::assertSame(DraftAccessCheckerInterface::UPDATE, $english['permission']);
        self::assertSame($english['id'], $translated['id']);
        self::assertArrayHasKey('fr:Article[title]', $translated['fields']);
        self::assertArrayNotHasKey('en:Article[title]', $translated['fields']);
        self::assertNull($this->configuration($this->form()->createView())['invitation']);
    }

    public function testCollectionsUseStableEntityKeysAndExposeAuthorizedMembershipPrototypes(): void
    {
        $child = new Article();
        $child->id = 42;
        $child->title = 'Child';
        $this->article->children->add($child);
        $form = $this->factory
            ->createNamedBuilder('Article', FormType::class, $this->article, ['data_class' => Article::class])
            ->add('children', CollectionType::class, [
                'entry_type' => FormType::class,
                'entry_options' => ['data_class' => Article::class],
                'allow_add' => true,
                'allow_delete' => true,
            ])
            ->getForm();
        self::assertTrue($form->get('children')->has('i42'));
        $view = $form->createView();
        self::assertSame(
            'Article[children]',
            self::vars($view->children['children'], 'row_attr')['data-collaborate-collection'],
        );
        $scope = $this->scope($view);
        self::assertSame(
            [
                'type' => 'member',
                'initial' => ['alive' => true, 'order' => 1, 'recordId' => '42'],
                'unordered' => true,
            ],
            $scope['fields']['Article[children][@i42]'],
        );
        // An entry the page adds is not alive until it is.
        self::assertSame(
            [
                'type' => 'member',
                'initial' => ['alive' => false, 'order' => 0, 'recordId' => null],
                'unordered' => true,
            ],
            $scope['fields']['Article[children][__member__]'],
        );
        self::assertSame(
            ['type' => 'collection', 'initial' => ['i42'], 'unordered' => true],
            $scope['fields']['Article[children][__collection__]'],
        );
        // Without a `position` child the submitted order is not stored.
        foreach (['[@i42]', '[__member__]', '[__collection__]'] as $suffix) {
            self::assertTrue($scope['fields']['Article[children]' . $suffix]['unordered'] ?? false);
        }
    }

    public function testCollectionsWhoseEntriesStoreAPositionKeepTheirOrder(): void
    {
        $child = new Article();
        $child->id = 42;
        $this->article->children->add($child);
        foreach ([true, false] as $prototype) {
            $form = $this->factory->createNamedBuilder('Article', FormType::class, $this->article, [
                'data_class' => Article::class,
            ])->add('children', CollectionType::class, [
                'entry_type' => PositionedEntryType::class,
                'allow_add' => $prototype,
            ])->getForm();
            $scope = $this->scope($form->createView());
            self::assertArrayNotHasKey('unordered', $scope['fields']['Article[children][@i42]']);
            self::assertArrayNotHasKey('unordered', $scope['fields']['Article[children][__collection__]']);
        }
        $this->article->children->clear();
        $form = $this->factory->createNamedBuilder('Article', FormType::class, $this->article, [
            'data_class' => Article::class,
        ])->add('children', CollectionType::class, [
            'entry_type' => PositionedEntryType::class,
            'allow_add' => true,
        ])->getForm();
        $scope = $this->scope($form->createView());
        self::assertArrayNotHasKey('unordered', $scope['fields']['Article[children][__member__]']);
    }

    public function testACollectionRowIsMarkedOnlyOnAPageThatSharesItsForm(): void
    {
        $build = fn(): FormView => $this->factory->createNamedBuilder('Article', FormType::class, $this->article, [
            'data_class' => Article::class,
        ])->add('children', CollectionType::class, ['entry_type' => FormType::class])->getForm()->createView();
        self::assertSame(
            'Article[children]',
            self::vars($build()->children['children'], 'row_attr')['data-collaborate-collection'] ?? null,
        );
        // No draft, no collection controller: its rows stay as rendered.
        $this->page->page = null;
        self::assertArrayNotHasKey('data-collaborate-collection', self::vars(
            $build()->children['children'],
            'row_attr',
        ));
        $this->page->page = PageContext::NEW;
        $this->granted = false;
        self::assertArrayNotHasKey('data-collaborate-collection', self::vars(
            $build()->children['children'],
            'row_attr',
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function formThemes(): iterable
    {
        foreach ([
            'form_div_layout',
            'form_table_layout',
            'bootstrap_3_layout',
            'bootstrap_3_horizontal_layout',
            'bootstrap_4_layout',
            'bootstrap_4_horizontal_layout',
            'bootstrap_5_layout',
            'bootstrap_5_horizontal_layout',
            'foundation_5_layout',
            'foundation_6_layout',
            'tailwind_2_layout',
            'daisyui_5_layout',
        ] as $theme) {
            // A theme the installed Symfony does not ship yet (daisyUI's came with 7.4) is left out.
            if (!is_file(self::formThemeDirectory() . '/' . $theme . '.html.twig')) {
                continue;
            }
            yield $theme => [$theme . '.html.twig'];
        }
    }

    private static function formThemeDirectory(): string
    {
        $file = new \ReflectionClass(FormExtension::class)->getFileName();
        self::assertIsString($file);

        return \dirname($file, 2) . '/Resources/views/Form';
    }

    /**
     * Every stock theme renders the rows, the collection widget and the entries the extension marks, so the
     * scripts find a field's row and a collection's entries whatever the theme.
     */
    #[DataProvider('formThemes')]
    public function testEveryStockFormThemeRendersTheMarkedRowsAndEntries(string $theme): void
    {
        $child = new Article();
        $child->id = 42;
        $child->title = 'Child';
        $this->article->children->add($child);
        $form = $this->factory
            ->createNamedBuilder('Article', FormType::class, $this->article, ['data_class' => Article::class])
            ->add('title', TextType::class, ['label' => 'Title label'])
            ->add('body', RichTextType::class, ['label' => 'Body label'])
            ->add('status', ChoiceType::class, [
                'label' => 'Status label',
                'mapped' => false,
                'expanded' => true,
                'choices' => ['Draft' => 'draft', 'Published' => 'published'],
            ])
            ->add('featured', CheckboxType::class, [
                'label' => 'Featured label',
                'mapped' => false,
                'required' => false,
            ])
            ->add('children', CollectionType::class, [
                'label' => 'Children label',
                'entry_type' => PositionedEntryType::class,
                'entry_options' => ['label' => 'Entry label'],
                'allow_add' => true,
                'prototype_name' => '__child__',
            ])
            ->getForm();
        $twig = new Environment(new FilesystemLoader([self::formThemeDirectory()]), ['strict_variables' => true]);
        $engine = new TwigRendererEngine([$theme], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn(): FormRenderer => new FormRenderer($engine),
        ]));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TranslationExtension());
        $html = $twig->createTemplate('{{ form(form) }}')->render(['form' => $form->createView()]);
        $page = new Crawler('<!DOCTYPE html><html><body>' . $html . '</body></html>');

        $rowOf = static function (string $control) use ($page, $theme): Crawler {
            $row = $page->filter($control)->first()->closest('[data-collaborate-row]');
            self::assertNotNull($row, $control . ' has a row in ' . $theme);

            return $row;
        };
        foreach ([
            'input[name="Article[title]"]' => 'Title label',
            'textarea[name="Article[body]"]' => 'Body label',
            'input[name="Article[status]"]' => 'Status label',
            'input[name="Article[featured]"]' => 'Featured label',
            'input[name="Article[children][i42][title]"]' => 'Title',
        ] as $control => $label) {
            self::assertStringContainsString($label, $rowOf($control)->text(), $control . ' in ' . $theme);
        }

        $collection = $page->filter('[data-collaborate-collection="Article[children]"]');
        self::assertCount(1, $collection, $theme);
        self::assertStringContainsString('Children label', $collection->text(), $theme);
        $widget = $collection->filter('[data-collaborate-entries="Article[children]"]');
        self::assertCount(1, $widget, $theme);
        self::assertSame('__child__', $widget->attr('data-collaborate-placeholder'));
        $entry = $page->filter('input[name="Article[children][i42][title]"]')->closest('[data-collaborate-entry]');
        self::assertNotNull($entry, $theme);
        self::assertCount(1, $widget->filter('[data-collaborate-entry]'), $theme);
        self::assertStringContainsString('Entry label', $entry->text(), $theme);
        $prototype = (string) $widget->attr('data-prototype');
        self::assertMatchesRegularExpression('/^\s*<\w+[^>]*\sdata-collaborate-entry[\s=>]/', $prototype, $theme);
        self::assertStringContainsString('Article[children][__child__][title]', $prototype, $theme);
        self::assertStringContainsString('data-collaborate-row', $prototype, $theme);
    }

    public function testMarkingRowsKeepsTheirOwnAttributes(): void
    {
        $view = $this->factory
            ->createNamedBuilder('Article', FormType::class, $this->article, ['data_class' => Article::class])
            ->add('title', TextType::class, ['row_attr' => ['class' => 'mb-3', 'data-collaborate-row' => 'kept']])
            ->getForm()
            ->createView();
        self::assertSame(
            ['class' => 'mb-3', 'data-collaborate-row' => 'kept'],
            $view->children['title']->vars['row_attr'],
        );
        // The root form has no row of its own to mark.
        self::assertArrayNotHasKey('data-collaborate-row', self::rowAttributes($view));
    }

    /** @return array<array-key, mixed> */
    private static function rowAttributes(FormView $view): array
    {
        self::assertIsArray($view->vars['row_attr']);

        return $view->vars['row_attr'];
    }

    public function testRowsAreMarkedOnlyOnAPageThatSharesItsForm(): void
    {
        $this->page->page = null;
        self::assertArrayNotHasKey(
            'data-collaborate-row',
            self::rowAttributes($this->form()->createView()->children['title']),
        );
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function collectionsOutsideTheSchema(): iterable
    {
        yield 'disabled' => [['disabled' => true], []];
        yield 'unmapped' => [['mapped' => false], []];
        yield 'under a disabled form' => [[], ['disabled' => true]];
        yield 'under an unmapped form' => [[], ['mapped' => false]];
    }

    /**
     * @param array<string, mixed> $options      the collection's
     * @param array<string, mixed> $groupOptions its parent's, a form between it and the root form
     */
    #[DataProvider('collectionsOutsideTheSchema')]
    public function testACollectionLeftOutOfTheSchemaIsNotMarked(array $options, array $groupOptions): void
    {
        $child = new Article();
        $child->id = 42;
        $this->article->children->add($child);
        if (false === ($options['mapped'] ?? true)) {
            // An unmapped collection shows the entries it is given.
            $options['data'] = [$child];
        }
        $builder = $this->factory->createNamedBuilder('Article', FormType::class, $this->article, [
            'data_class' => Article::class,
        ]);
        $builder->add($builder->create('group', FormType::class, ['inherit_data' => true]
        + $groupOptions)->add('children', CollectionType::class, [
            'entry_type' => PositionedEntryType::class,
            'allow_add' => true,
        ] + $options));
        $builder->add('categories', CollectionType::class, ['entry_type' => FormType::class]);
        $view = $builder->getForm()->createView();

        $collection = $view->children['group']->children['children'];
        self::assertArrayNotHasKey('data-collaborate-collection', self::vars($collection, 'row_attr'));
        // Nor is a collection inside its entries or its prototype.
        $fields = $this->scope($view)['fields'];
        self::assertSame([], array_filter(array_keys($fields), static fn(string $key): bool => str_contains(
            $key,
            '[children]',
        )));
        // Its rows render, and the collections of the schema are still marked.
        self::assertCount(1, $collection->children);
        self::assertSame(
            'Article[categories]',
            self::vars($view->children['categories'], 'row_attr')['data-collaborate-collection'] ?? null,
        );
    }

    public function testACollectionWithASensitiveNameIsNotMarked(): void
    {
        $account = new Account(['ROLE_USER']);
        $this->page->entity = $account;
        $view = $this->factory->createNamedBuilder('Account', FormType::class, $account, [
            'data_class' => Account::class,
        ])->add('api_key', CollectionType::class, [
            'entry_type' => TextType::class,
            'property_path' => 'roles',
        ])->getForm()->createView();

        self::assertArrayNotHasKey('data-collaborate-collection', self::vars($view->children['api_key'], 'row_attr'));
        self::assertSame([], $this->scope($view)['fields']);
    }

    public function testANestedCollectionIsMarkedWithItsPath(): void
    {
        $child = new Article();
        $child->id = 42;
        $this->article->children->add($child);
        $view = $this->factory
            ->createNamedBuilder('Article', FormType::class, $this->article, [
                'data_class' => Article::class,
            ])
            ->add('children', CollectionType::class, [
                'entry_type' => FormType::class,
                'entry_options' => ['data_class' => Article::class],
            ])
            ->getForm();
        $view->get('children')->get('i42')->add('children', CollectionType::class, ['entry_type' => FormType::class]);

        self::assertSame(
            'Article[children][i42][children]',
            self::vars(
                $view->createView()->children['children']->children['i42']->children['children'],
                'row_attr',
            )['data-collaborate-collection'] ?? null,
        );
    }

    public function testOrdinarySubmissionsAndNonEditPagesRemainUsable(): void
    {
        $form = $this->form();
        $form->submit(['title' => 'Submitted', 'body' => 'Body']);
        self::assertSame('Submitted', $this->article->title);
        $this->page->page = null;
        $view = $this->form()->createView();
        self::assertArrayNotHasKey('data-collaborate', self::vars($view, 'attr'));
        $this->page->page = PageContext::EDIT;
        $this->canEdit = false;
        self::assertArrayNotHasKey('data-collaborate', self::vars($this->form()->createView(), 'attr'));
        $this->canEdit = true;
        $this->granted = false;
        self::assertArrayNotHasKey('data-collaborate', self::vars($this->form()->createView(), 'attr'));
        $this->granted = true;
        // A user the collaborator provider cannot name shares no draft either.
        $this->user = new TestUser();
        self::assertArrayNotHasKey('data-collaborate', self::vars($this->form()->createView(), 'attr'));
        self::assertContains(FormType::class, iterator_to_array(CollaborateFormExtension::getExtendedTypes()));
    }

    public function testWithoutAFormContextTheExtensionIsInert(): void
    {
        $this->extension = ($this->extensionFactory)(true, null, null, [], false);
        $this->rebuild();
        $child = new Article();
        $child->id = 42;
        $this->article->children->add($child);

        $form = $this->factory
            ->createNamedBuilder('Article', FormType::class, $this->article, ['data_class' => Article::class])
            ->add('title', TextType::class)
            ->add('children', CollectionType::class, [
                'entry_type' => FormType::class,
                'entry_options' => [
                    'data_class' => Article::class,
                ],
            ])
            ->getForm();
        $view = $form->createView();

        self::assertTrue($form->get('children')->has('0'));
        self::assertArrayNotHasKey('data-collaborate', self::vars($view, 'attr'));
        self::assertArrayNotHasKey('data-collaborate-collection', self::vars($view->children['children'], 'row_attr'));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function submittedPages(): iterable
    {
        foreach ([PageContext::NEW, PageContext::EDIT] as $page) {
            foreach (['en', 'fr'] as $locale) {
                yield $page . ' ' . $locale => [$page, $locale, true];
                yield $page . ' ' . $locale . ' without JavaScript' => [$page, $locale, false];
            }
        }
    }

    #[DataProvider('submittedPages')]
    public function testInvalidSubmissionKeepsTheSavedBaselineInTheRenderedGrant(
        string $page,
        string $locale,
        bool $collaborating,
    ): void {
        $this->page->page = $page;
        $this->request->setLocale($locale);
        $form = $this->form();
        $configuration = $this->configuration($form->createView());
        $before = $this->scope($form->createView());
        if ($collaborating) {
            $this->request->request->set('_collaborate_grant', $configuration['grant']);
            // The saving editor is named to the others.
            $this->save
                ->expects($this->once())
                ->method('begin')
                ->with(self::anything(), self::anything(), self::anything(), 'Alice');
        } else {
            $this->save->expects($this->never())->method('begin');
        }
        $form->submit(['title' => 'Unsaved title', 'body' => 'Unsaved body']);
        $form->addError(new FormError('Another field is invalid.'));
        self::assertFalse($form->isValid());
        $after = $this->scope($form->createView());
        self::assertSame($before['id'], $after['id']);
        self::assertSame($before['fields'], $after['fields']);
        self::assertSame('Unsaved title', $form->get('title')->getViewData());
        self::assertSame('Unsaved body', $form->get('body')->getViewData());
    }

    public function testCompositeCollectionIdentifiersHaveStableLegalKeysAndKeepTheirRecordIdentity(): void
    {
        $first = new Membership(2, 1180, 'editor');
        $second = new Membership(2, 1180, 'reviewer');
        $record = (object) ['items' => [$first, $second]];
        $this->page->entity = $record;
        $build = fn(): FormInterface => $this->factory
            ->createNamedBuilder('Record', FormType::class, $record)
            ->add('items', CollectionType::class, [
                'entry_type' => FormType::class,
                'entry_options' => ['data_class' => Membership::class],
                'allow_add' => true,
                'allow_delete' => true,
            ])
            ->getForm();
        $form = $build();
        $keys = array_keys($form->get('items')->all());
        self::assertCount(2, array_unique($keys));
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,80}$/D', (string) $key);
        }
        $scope = $this->scope($form->createView());
        $membership = $scope['fields']['Record[items][@' . $keys[0] . ']'];
        self::assertIsArray($membership['initial']);
        self::assertSame($first->id, $membership['initial']['recordId']);
        $record->items = [$second, $first];
        self::assertSame(array_reverse($keys), array_keys($build()->get('items')->all()));
        $form->submit(['items' => [$keys[0] => [], $keys[1] => []]]);
        self::assertSame($first, $form->get('items')->get((string) $keys[0])->getData());
    }

    public function testAnEntryOfAMappedEntityIsKeyedByItsDoctrineIdentifier(): void
    {
        $tag = new Tag();
        $tag->id = 5;
        $record = (object) ['tags' => [
            $tag,
            'plain',
            new class implements \Stringable {
                public \Stringable $id;

                public function __construct()
                {
                    $this->id = new class implements \Stringable {
                        public function __toString(): string
                        {
                            return 'uuid-1';
                        }
                    };
                }

                public function __toString(): string
                {
                    return 'entry';
                }
            },
        ]];
        $this->page->entity = $record;
        $metadata = $this->createStub(PersistenceClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 'mapped-5']);
        $manager = $this->createStub(ObjectManager::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry
            ->method('getManagerForClass')
            ->willReturnCallback(static fn(string $class): ?ObjectManager => Tag::class === $class ? $manager : null);
        $this->extension = ($this->extensionFactory)(true, null, null, [], true, new RecordIdentifier($registry));
        $this->rebuild();

        $form = $this->factory
            ->createNamedBuilder('Record', FormType::class, $record)
            ->add('tags', CollectionType::class, [
                'entry_type' => FormType::class,
                'entry_options' => [
                    'compound' => false,
                ],
            ])
            ->getForm();

        self::assertSame(['imapped-5', 'p1', 'iuuid-1'], array_keys($form->get('tags')->all()));
    }

    public function testSavedAutocompleteSnapshotUsesItsIdentityMapperAndCurrentEntityIdentifier(): void
    {
        $tag = new Tag();
        $tag->id = 42;
        $record = (object) ['tag' => $tag];
        $this->page->entity = $record;
        $builder = $this->factory->createNamedBuilder('Record', FormType::class, $record);
        $builder->add('tag', FormType::class, ['data_class' => Tag::class]);
        $association = $builder->get('tag');
        $association->setDataMapper(new CrudAutocompleteType($this->createStub(Environment::class)));
        $association->add('autocomplete', TextType::class, ['property_path' => '[autocomplete]']);
        $association->get('autocomplete')->addModelTransformer(
            new CallbackTransformer(
                static fn(Tag $value): string => (string) $value->id,
                static fn(string $value): Tag => $tag,
            ),
        );
        $form = $builder->getForm();
        $configuration = $this->configuration($form->createView());
        $this->request->request->set('_collaborate_grant', $configuration['grant']);
        $this->save
            ->expects($this->once())
            ->method('begin')
            ->willReturnCallback(static function (Request $request): void {
                $request->attributes->set('_collaborate_save', true);
            });
        $form->submit(['tag' => ['autocomplete' => '42']]);
        $tag->id = 84;
        /** @var callable(): array<string, mixed> $snapshot */
        $snapshot = $this->request->attributes->get('_collaborate_snapshot');
        self::assertSame('84', $snapshot()['Record[tag][autocomplete]']);
    }

    public function testSavedCompoundDateSnapshotReadsTransformedChildren(): void
    {
        $record = (object) ['when' => new \DateTime('2026-10-16 12:30:00')];
        $this->page->entity = $record;
        $builder = $this->factory->createNamedBuilder('Record', FormType::class, $record);
        $builder->add('when', DateTimeType::class, ['widget' => 'choice']);
        $form = $builder->getForm();
        $configuration = $this->configuration($form->createView());
        $this->request->request->set('_collaborate_grant', $configuration['grant']);
        $this->save
            ->method('begin')
            ->willReturnCallback(static function (Request $request): void {
                $request->attributes->set('_collaborate_save', true);
            });
        $form->submit([
            'when' => [
                'date' => ['year' => '2026', 'month' => '10', 'day' => '16'],
                'time' => ['hour' => '12', 'minute' => '30'],
            ],
        ]);
        /** @var callable(): array<string, mixed> $snapshot */
        $snapshot = $this->request->attributes->get('_collaborate_snapshot');
        self::assertSame('2026', $snapshot()['Record[when][date][year]']);
        self::assertSame('30', $snapshot()['Record[when][time][minute]']);
    }

    public function testSignedSubmissionsAcquireTheGuardAndCaptureValidatedValues(): void
    {
        $form = $this->form();
        $configuration = $this->configuration($form->createView());
        $this->request->request->set('_collaborate_grant', $configuration['grant']);
        $this->save
            ->expects($this->once())
            ->method('begin')
            ->willReturnCallback(static function (Request $request): void {
                $request->attributes->set('_collaborate_save', true);
            });
        $form->submit(['title' => 'Changed', 'body' => 'Body']);
        // The values are read once the record is persisted, never at submission.
        self::assertFalse($this->request->attributes->has('_collaborate_values'));
        /** @var callable(): array<string, mixed> $snapshot */
        $snapshot = $this->request->attributes->get('_collaborate_snapshot');
        self::assertSame('Changed', $snapshot()['en:Article[title]']);
        self::assertSame('Body', $snapshot()['en:Article[body]']);
    }

    public function testRejectsCapabilitiesIssuedForAnotherRecord(): void
    {
        $grant = $this->access()->issue(
            [
                'id' => 'other',
                'entity' => Article::class,
                'permission' => 'create',
                'new' => true,
                'draft' => 'draft',
                'fields' => [],
            ],
            7,
            time(),
            $this->user->getRoles(),
        );
        $this->request->request->set('_collaborate_grant', $grant);
        $this->expectException(AccessDeniedHttpException::class);
        $this->form()->submit(['title' => 'Changed']);
    }

    public function testScalarCollectionsUseStableKeysAndKeepSubmittedOrder(): void
    {
        $account = new Account(['ROLE_USER', 'ROLE_ADMIN']);
        $this->page->entity = $account;
        $this->page->page = PageContext::EDIT;
        $build = /** @param array<string, mixed> $options */ fn(array $options = [
            'allow_add' => true,
            'allow_delete' => true,
        ]): FormInterface => $this->factory
            ->createNamedBuilder('Account', FormType::class, $account, ['data_class' => Account::class])
            ->add('roles', CollectionType::class, ['entry_type' => TextType::class] + $options)
            ->getForm();
        $form = $build();
        self::assertTrue($form->get('roles')->has('p0'));
        $scope = $this->scope($form->createView());
        self::assertArrayHasKey('Account[roles][__name__]', $scope['fields']);
        $form->submit(['roles' => ['p1' => 'ROLE_ADMIN', 'p0' => 'ROLE_USER']]);
        self::assertSame(['p1', 'p0'], array_keys($form->get('roles')->all()));
        self::assertSame('ROLE_USER', $form->get('roles')->get('p0')->getData());
        // The stable keys stay in the form: the record stores a list, in the submitted order.
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $account->roles);
        $build()->submit(['roles' => ['p1' => 'ROLE_USER', 'new' => 'ROLE_EDITOR']]);
        self::assertSame(['ROLE_USER', 'ROLE_EDITOR'], $account->roles);
        // A keyed array keeps its own keys.
        $account->roles = ['main' => 'ROLE_USER'];
        $build([])->submit(['roles' => ['main' => 'ROLE_ADMIN']]);
        self::assertSame(['main' => 'ROLE_ADMIN'], $account->roles);
    }

    public function testSavedScalarCollectionSnapshotReadsTheStoredListByStableKey(): void
    {
        $account = new Account(['ROLE_USER', 'ROLE_ADMIN']);
        $this->page->entity = $account;
        $this->page->page = PageContext::EDIT;
        $form = $this->factory->createNamedBuilder('Account', FormType::class, $account, [
            'data_class' => Account::class,
        ])->add('roles', CollectionType::class, [
            'entry_type' => TextType::class,
            'allow_add' => true,
            'allow_delete' => true,
        ])->getForm();
        $configuration = $this->configuration($form->createView());
        $this->request->request->set('_collaborate_grant', $configuration['grant']);
        $this->save
            ->method('begin')
            ->willReturnCallback(static function (Request $request): void {
                $request->attributes->set('_collaborate_save', true);
            });
        $form->submit(['roles' => ['p1' => 'ROLE_ADMIN', 'p0' => 'ROLE_EDITOR']]);
        /** @var callable(): array<string, mixed> $snapshot */
        $snapshot = $this->request->attributes->get('_collaborate_snapshot');
        $values = $snapshot();
        self::assertSame(['ROLE_ADMIN', 'ROLE_EDITOR'], $account->roles);
        self::assertSame('ROLE_EDITOR', $values['Account[roles][p0]']);
        self::assertSame('ROLE_ADMIN', $values['Account[roles][p1]']);
    }

    public function testSavedSnapshotReadsANumericPropertyAsTheStringItsTextControlShows(): void
    {
        $record = new class {
            public int $year = 0 {
                set(int|string $value) => (int) $value;
            }
            public float $ratio = 0.0 {
                set(float|string $value) => is_numeric($value) ? (float) $value : 0.0;
            }
        };
        $this->page->entity = $record;
        $this->page->page = PageContext::EDIT;
        $form = $this->factory->createNamedBuilder('Record', FormType::class, $record, [
            'data_class' => $record::class,
        ])->add('year', TextType::class)->add('ratio', TextType::class)->getForm();
        $configuration = $this->configuration($form->createView());
        $this->request->request->set('_collaborate_grant', $configuration['grant']);
        $this->save
            ->method('begin')
            ->willReturnCallback(static function (Request $request): void {
                $request->attributes->set('_collaborate_save', true);
            });
        $form->submit(['year' => '2026', 'ratio' => '1.5']);
        /** @var callable(): array<string, mixed> $snapshot */
        $snapshot = $this->request->attributes->get('_collaborate_snapshot');
        $values = $snapshot();
        self::assertSame('2026', $values['Record[year]']);
        self::assertSame('1.5', $values['Record[ratio]']);
    }

    public function testAReopenedPlainListTakesTheKeysItsLastSaveRecorded(): void
    {
        $account = new Account(['ROLE_B', 'ROLE_C']);
        $this->page->entity = $account;
        $this->page->page = PageContext::EDIT;
        $build = fn(): FormInterface => $this->factory->createNamedBuilder('Account', FormType::class, $account, [
            'data_class' => Account::class,
        ])->add('roles', CollectionType::class, ['entry_type' => TextType::class])->getForm();
        $this->listKeys = ['Account[roles]' => ['p1', 'nadded']];
        $form = $build();
        self::assertSame(['p1', 'nadded'], array_keys($form->get('roles')->all()));
        self::assertSame('ROLE_C', $form->get('roles')->get('nadded')->getData());
        // A list changed elsewhere since that save holds other entries: they keep their positions.
        $this->listKeys = ['Account[roles]' => ['p1']];
        $this->request->attributes->remove('_collaborate_list_keys');
        self::assertSame(['p0', 'p1'], array_keys($build()->get('roles')->all()));
    }

    public function testMultipleChoicesAndPlaintextCredentialFieldsAreHandledSafely(): void
    {
        $account = new Account();
        $this->page->entity = $account;
        $form = $this->factory
            ->createNamedBuilder('Account', FormType::class, $account, ['data_class' => Account::class])
            ->add('roles', ChoiceType::class, [
                'multiple' => true,
                'choices' => ['User' => 'ROLE_USER', 'Administrator' => 'ROLE_ADMIN'],
            ])
            ->add('password', TextType::class)
            ->add('apiKey', TextType::class)
            ->add('name', PasswordType::class)
            ->getForm();
        $scope = $this->scope($form->createView());
        self::assertArrayHasKey('Account[roles][]', $scope['fields']);
        self::assertArrayNotHasKey('Account[password]', $scope['fields']);
        self::assertArrayNotHasKey('Account[apiKey]', $scope['fields']);
        self::assertArrayNotHasKey('Account[name]', $scope['fields']);
    }

    public function testTheSensitiveFieldsAreConfigurable(): void
    {
        $this->extension = ($this->extensionFactory)(true, null, null, ['sensitive_fields' => ['Api_Key']]);
        $this->rebuild();
        $account = new Account();
        $this->page->entity = $account;
        $form = $this->factory->createNamedBuilder('Account', FormType::class, $account, [
            'data_class' => Account::class,
        ])->add('password', TextType::class)->add('apiKey', TextType::class)->getForm();

        $fields = $this->scope($form->createView())['fields'];

        self::assertArrayHasKey('Account[password]', $fields);
        self::assertArrayNotHasKey('Account[apiKey]', $fields);
    }

    public function testCollectionRekeyingIsLimitedToTheMatchingEditorialForm(): void
    {
        $account = new Account(['ROLE_USER']);
        $this->page->entity = $account;
        $this->page->page = null;
        $form = $this->factory->createNamedBuilder('Account', FormType::class, $account, [
            'data_class' => Account::class,
        ])->add('roles', CollectionType::class, ['entry_type' => TextType::class])->getForm();
        self::assertTrue($form->get('roles')->has('0'));
        $this->page->page = PageContext::EDIT;
        $other = new Account(['ROLE_ADMIN']);
        $form = $this->factory->createNamedBuilder('Account', FormType::class, $other, [
            'data_class' => Account::class,
        ])->add('roles', CollectionType::class, ['entry_type' => TextType::class])->getForm();
        self::assertTrue($form->get('roles')->has('0'));
        // The page shares the form of its own record only.
        self::assertArrayNotHasKey('data-collaborate', self::vars($form->createView(), 'attr'));
        $record = new \stdClass();
        $record->items = null;
        $this->page->entity = $record;
        $form = $this->factory
            ->createNamedBuilder('Record', FormType::class, $record)
            ->add('items', CollectionType::class, ['entry_type' => HiddenType::class, 'allow_add' => true])
            ->getForm();
        self::assertCount(0, $form->get('items'));
        $form->createView();
        // Entries held by neither an array nor a Doctrine collection keep the keys Symfony gives them.
        $record->items = new \ArrayObject(['a']);
        $form = $this->factory
            ->createNamedBuilder('Record', FormType::class, $record)
            ->add('items', CollectionType::class, ['entry_type' => HiddenType::class, 'allow_add' => true])
            ->getForm();
        self::assertSame(['0'], array_map(\strval(...), array_keys($form->get('items')->all())));
        $record->items = [];
        $form = $this->factory
            ->createNamedBuilder('Record', FormType::class, $record)
            ->add('items', CollectionType::class, ['entry_type' => HiddenType::class])
            ->getForm();
        $form->submit(['items' => ['unknown' => 'ignored']]);
        self::assertFalse($form->get('items')->has('unknown'));
    }

    public function testSavedSnapshotReadsPersistedPropertiesThroughModelTransformers(): void
    {
        $builder = $this->factory->createNamedBuilder('Article', FormType::class, $this->article, [
            'data_class' => Article::class,
        ]);
        $builder->add('title', TextType::class);
        $builder->get('title')->addModelTransformer(
            new CallbackTransformer(
                static fn($value) => strtoupper((string) $value),
                static fn($value) => strtolower((string) $value),
            ),
        );
        $form = $builder->getForm();
        $configuration = $this->configuration($form->createView());
        $this->request->request->set('_collaborate_grant', $configuration['grant']);
        $this->save
            ->expects($this->once())
            ->method('begin')
            ->willReturnCallback(static function (Request $request): void {
                $request->attributes->set('_collaborate_save', true);
            });
        $form->submit(['title' => 'EDITOR']);
        $this->article->title = 'persisted';
        /** @var callable(): array<string, mixed> $snapshot */
        $snapshot = $this->request->attributes->get('_collaborate_snapshot');
        self::assertSame('PERSISTED', $snapshot()['en:Article[title]']);
    }

    public function testSavedFrenchSnapshotKeepsTheTranslatedValueWhenGedmoRestoresTheEntity(): void
    {
        $this->page->page = PageContext::EDIT;
        $this->request->setLocale('fr');
        $form = $this->form();
        $configuration = $this->configuration($form->createView());
        $this->request->request->set('_collaborate_grant', $configuration['grant']);
        $this->save
            ->method('begin')
            ->willReturnCallback(static function (Request $request): void {
                $request->attributes->set('_collaborate_save', true);
            });
        $form->submit(['title' => 'Titre traduit']);
        // Gedmo restores the pre-edit entity property when flushing a translation.
        $this->article->title = 'Title';
        /** @var callable(): array<string, mixed> $snapshot */
        $snapshot = $this->request->attributes->get('_collaborate_snapshot');
        self::assertSame('Titre traduit', $snapshot()['fr:Article[title]']);
    }

    public function testNonSerializableMappedValuesAreExcludedFromTheGrant(): void
    {
        $record = new \stdClass();
        $record->when = new \DateTimeImmutable();
        $this->page->entity = $record;
        $form = $this->factory->createNamedBuilder('Record', FormType::class, $record)->add('when', FormType::class, [
            'compound' => false,
        ])->getForm();
        self::assertArrayNotHasKey('Record[when]', $this->scope($form->createView())['fields']);
    }

    public function testDisabledFeatureRegistersNoAdditionalFormHandlers(): void
    {
        $builder = $this->factory->createNamedBuilder('Article', FormType::class, $this->article, [
            'data_class' => Article::class,
        ]);
        $before = \count($builder->getEventDispatcher()->getListeners(FormEvents::PRE_SUBMIT));
        ($this->extensionFactory)(false)->buildForm($builder, []);
        ($this->extensionFactory)(true, null, null, [], false)->buildForm($builder, []);
        self::assertCount($before, $builder->getEventDispatcher()->getListeners(FormEvents::PRE_SUBMIT));
    }

    public function testTheClientConfigurationDefaults(): void
    {
        $this->extension = ($this->extensionFactory)(
            true,
            null,
            null,
            ['rich_text' => ['block_prefixes' => ['rich_text']]],
            true,
            new RecordIdentifier(),
            null,
            true,
            false,
        );
        $this->rebuild();

        self::assertSame(
            ['replayConnected' => 30_000, 'replayDisconnected' => 5000, 'joinRetry' => 12_000, 'labelLength' => 255],
            $this->configuration($this->form()->createView())['config'],
        );
    }

    public function testTheConfiguredPositionFieldOrdersTheEntries(): void
    {
        $child = new Article();
        $child->id = 42;
        $this->article->children->add($child);
        foreach (['title' => false, 'rank' => true] as $field => $unordered) {
            $this->extension = ($this->extensionFactory)(true, null, null, ['position_field' => $field]);
            $this->rebuild();
            $form = $this->factory->createNamedBuilder('Article', FormType::class, $this->article, [
                'data_class' => Article::class,
            ])->add('children', CollectionType::class, ['entry_type' => PositionedEntryType::class])->getForm();

            $fields = $this->scope($form->createView())['fields'];

            self::assertSame($unordered, $fields['Article[children][@i42]']['unordered'] ?? false);
        }
    }

    /** @param Options $options */
    private function sharedWithin(int $maxRequestBytes, array $options = []): bool
    {
        $this->extension = ($this->extensionFactory)(
            true,
            null,
            null,
            $options,
            true,
            new RecordIdentifier(),
            $this->access(true, $maxRequestBytes),
        );
        $this->rebuild();

        return \array_key_exists('data-collaborate', self::vars($this->form()->createView(), 'attr'));
    }

    public function testAFormIsSharedWhileItsGrantFitsARequestBesideAnUpdateAndAnEnvelope(): void
    {
        $this->page->page = PageContext::EDIT;
        $this->request->server->set('REQUEST_TIME_FLOAT', 1_761_900_000.5);
        $grant = \strlen($this->configuration($this->form()->createView())['grant']);

        // An update of 256 KiB by default, and 1 KiB for the rest of the request.
        self::assertTrue($this->sharedWithin($grant + 262_144 + 1024));
        self::assertFalse($this->sharedWithin($grant + 262_144 + 1023));
        self::assertTrue($this->sharedWithin($grant + 1000 + 1024, ['max_update_bytes' => 1000]));
        self::assertFalse($this->sharedWithin($grant + 1000 + 1023, ['max_update_bytes' => 1000]));
    }

    public function testAGrantRecordsWhenItsPageWasRequested(): void
    {
        $this->request->server->set('REQUEST_TIME_FLOAT', '1761900000.5');
        self::assertSame(1_761_900_000.5, $this->scope($this->form()->createView())['requestedAt'] ?? null);

        $this->request->server->remove('REQUEST_TIME_FLOAT');
        $before = microtime(true);
        $requestedAt = $this->scope($this->form()->createView())['requestedAt'] ?? null;
        self::assertIsFloat($requestedAt);
        self::assertGreaterThanOrEqual($before, $requestedAt);
        self::assertLessThanOrEqual(microtime(true), $requestedAt);
    }

    public function testAnEntryKeepsTheKeyItsDraftGaveIt(): void
    {
        $child = new Article();
        $child->id = 42;
        $this->article->children->add($child);
        $this->page->page = PageContext::EDIT;
        $this->aliases = ['Article[children]' => ['42' => 'nadded']];

        $form = $this->factory
            ->createNamedBuilder('Article', FormType::class, $this->article, [
                'data_class' => Article::class,
            ])
            ->add('children', CollectionType::class, [
                'entry_type' => FormType::class,
                'entry_options' => ['data_class' => Article::class],
            ])
            ->getForm();

        self::assertSame(['nadded'], array_keys($form->get('children')->all()));
    }

    public function testAListThatIsNoCollectionKeepsItsKeys(): void
    {
        $record = (object) ['pair' => ['first', 'second']];
        $this->page->entity = $record;
        $builder = $this->factory->createNamedBuilder('Record', FormType::class, $record);
        $builder->add($builder->create('pair', FormType::class)->add('0', TextType::class)->add('1', TextType::class));

        $form = $builder->getForm();

        self::assertSame('first', $form->get('pair')->get('0')->getData());
        self::assertSame('second', $form->get('pair')->get('1')->getData());
    }

    public function testASubmittedListFollowsTheSubmittedOrderPastUnknownEntries(): void
    {
        $account = new Account(['ROLE_USER', 'ROLE_ADMIN']);
        $this->page->entity = $account;
        $this->page->page = PageContext::EDIT;
        $form = $this->factory->createNamedBuilder('Account', FormType::class, $account, [
            'data_class' => Account::class,
        ])->add('roles', CollectionType::class, ['entry_type' => TextType::class])->getForm();

        $form->submit(['roles' => ['unknown' => 'ROLE_EDITOR', 'p1' => 'ROLE_ADMIN', 'p0' => 'ROLE_USER']]);

        self::assertSame(['p1', 'p0'], array_keys($form->get('roles')->all()));
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $account->roles);
    }

    public function testADisabledEntryAddedToAListDoesNotEnterIt(): void
    {
        $account = new Account(['ROLE_USER']);
        $this->page->entity = $account;
        $this->page->page = PageContext::EDIT;
        $form = $this->factory
            ->createNamedBuilder('Account', FormType::class, $account, [
                'data_class' => Account::class,
            ])
            ->add('roles', CollectionType::class, [
                'entry_type' => TextType::class,
                'entry_options' => ['disabled' => true],
                'allow_add' => true,
            ])
            ->getForm();

        $form->submit(['roles' => ['p0' => 'ROLE_ADMIN', 'added' => 'ROLE_EDITOR']]);

        self::assertSame(['ROLE_USER'], $account->roles);
    }

    public function testItsListenersRunAroundTheFormComponentsOwn(): void
    {
        $dispatcher = new EventDispatcher();
        $this->extension->buildForm(new FormBuilder('Article', null, $dispatcher, $this->factory), []);
        $priorities = [];
        foreach ($dispatcher->getListeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                $priorities[$event][] = $dispatcher->getListenerPriority($event, $listener);
            }
        }

        // Collections are rekeyed before ResizeFormListener (0) and the host's listeners build their entries,
        // and reordered after it resizes them (PRE_SUBMIT 0, SUBMIT 50); a signed submission takes the save
        // lock before any other listener reads it.
        self::assertSame(
            [
                FormEvents::PRE_SET_DATA => [512],
                FormEvents::PRE_SUBMIT => [2048, -1024],
                FormEvents::SUBMIT => [-1024],
            ],
            $priorities,
        );
    }

    public function testAControlShowingOtherDataThanItsPropertyIsDescribedAsShown(): void
    {
        $form = $this->factory->createNamedBuilder('Article', FormType::class, $this->article, [
            'data_class' => Article::class,
        ])->add('title', TextType::class, ['data' => 'Shown'])->getForm();

        self::assertSame('Shown', $this->scope($form->createView())['fields']['en:Article[title]']['initial']);
    }

    public function testUnmappedAndDisabledControlsStayOutOfTheSchema(): void
    {
        $form = $this->factory
            ->createNamedBuilder('Article', FormType::class, $this->article, ['data_class' => Article::class])
            ->add('token', HiddenType::class, ['mapped' => false])
            ->add('title', TextType::class)
            ->add('body', TextType::class, ['disabled' => true])
            ->add('note', TextType::class, ['mapped' => false])
            ->getForm();

        self::assertSame(['en:Article[title]'], array_keys($this->scope($form->createView())['fields']));
    }

    public function testAPrototypeDescribesTheFieldsOfTheEntriesAPageAdds(): void
    {
        $form = $this->factory->createNamedBuilder('Article', FormType::class, $this->article, [
            'data_class' => Article::class,
        ])->add('children', CollectionType::class, [
            'entry_type' => PositionedEntryType::class,
            'allow_add' => true,
        ])->getForm();

        $fields = $this->scope($form->createView())['fields'];

        self::assertSame(
            'Article[children][__name__][title]',
            $fields['en:Article[children][__name__][title]']['formName'] ?? null,
        );
        $record = (object) ['items' => []];
        $this->page->entity = $record;
        $form = $this->factory
            ->createNamedBuilder('Record', FormType::class, $record)
            ->add('items', CollectionType::class, ['entry_type' => HiddenType::class, 'allow_add' => true])
            ->getForm();

        // A hidden entry has no value of its own in the draft: only its membership.
        self::assertSame(
            ['Record[items][__collection__]', 'Record[items][__member__]'],
            array_keys($this->scope($form->createView())['fields']),
        );
    }

    /** @param callable(string): void $submit */
    private function snapshot(FormInterface $form, callable $submit): array
    {
        $configuration = $this->configuration($form->createView());
        $this->request->request->set('_collaborate_grant', $configuration['grant']);
        $this->save
            ->method('begin')
            ->willReturnCallback(static function (Request $request): void {
                $request->attributes->set('_collaborate_save', true);
            });
        $submit($configuration['grant']);
        /** @var callable(): array<string, mixed> $snapshot */
        $snapshot = $this->request->attributes->get('_collaborate_snapshot');

        return $snapshot();
    }

    public function testASavedSnapshotReadsAnArrayPropertyAndKeepsTheShownValueOfAnEmptyObject(): void
    {
        $record = (object) ['meta' => ['note' => 'saved'], 'tag' => null];
        $this->page->entity = $record;
        $this->page->page = PageContext::EDIT;
        $builder = $this->factory->createNamedBuilder('Record', FormType::class, $record);
        $builder->add($builder->create('meta', FormType::class)->add('note', TextType::class));
        $builder
            ->get('meta')
            ->get('note')
            ->addModelTransformer(
                new CallbackTransformer(
                    static fn(mixed $value): mixed => $value,
                    static fn(mixed $value): string => strtolower((string) $value),
                ),
            );
        $builder->add($builder->create('tag', FormType::class, [
            'data_class' => Tag::class,
            'empty_data' => null,
            'required' => false,
        ])->add('name', TextType::class));
        $form = $builder->getForm();

        $values = $this->snapshot($form, static function () use ($form): void {
            $form->submit(['meta' => ['note' => 'EDITED'], 'tag' => ['name' => '']]);
        });

        // The saved array holds what the model transformer stored.
        self::assertSame('edited', $values['Record[meta][note]']);
        self::assertNull($record->tag);
        self::assertSame('', $values['Record[tag][name]']);
    }

    public function testASavedSnapshotReadsAListPropertyAsItsFormTransformsIt(): void
    {
        $record = (object) ['pair' => ['x']];
        $this->page->entity = $record;
        $this->page->page = PageContext::EDIT;
        $builder = $this->factory->createNamedBuilder('Record', FormType::class, $record);
        $builder->add($builder->create('pair', FormType::class)->add('0', TextType::class));
        // The record stores its entries in capitals.
        $builder->get('pair')->addViewTransformer(
            new CallbackTransformer(
                static fn(mixed $value): mixed => $value,
                static fn(array $value): array => array_map(strtoupper(...), $value),
            ),
        );
        $form = $builder->getForm();

        $values = $this->snapshot($form, static function () use ($form): void {
            $form->submit(['pair' => ['0' => 'a']]);
        });

        self::assertSame(['A'], $record->pair);
        self::assertSame('A', $values['Record[pair][0]']);
    }

    public function testASavedSnapshotReadsThePropertyThroughViewTransformers(): void
    {
        $builder = $this->factory->createNamedBuilder('Article', FormType::class, $this->article, [
            'data_class' => Article::class,
        ]);
        $builder->add('title', TextType::class);
        $builder->get('title')->addViewTransformer(
            new CallbackTransformer(
                static fn(mixed $value): string => strtoupper((string) $value),
                static fn(mixed $value): string => strtolower((string) $value),
            ),
        );
        $form = $builder->getForm();

        $values = $this->snapshot($form, function () use ($form): void {
            $form->submit(['title' => 'EDITOR']);
            $this->article->title = 'persisted';
        });

        self::assertSame('PERSISTED', $values['en:Article[title]']);
    }

    public function testOnlyTextControlsAreTextAndKeepTheirValuesType(): void
    {
        $record = new class {
            /** @var list<string> */
            public array $roles = ['ROLE_USER'];
            public int $count = 3;
            public bool $flag = true;
        };
        $this->page->entity = $record;
        $form = $this->factory
            ->createNamedBuilder('Record', FormType::class, $record, [
                'data_class' => $record::class,
            ])
            ->add('roles', ChoiceType::class, ['multiple' => true, 'choices' => ['User' => 'ROLE_USER']])
            ->add('count', FormType::class, ['compound' => false])
            ->add('flag', TextType::class)
            ->getForm();

        $fields = $this->scope($form->createView())['fields'];

        self::assertSame(
            ['type' => 'value', 'initial' => ['ROLE_USER'], 'formName' => 'Record[roles][]'],
            $fields['Record[roles][]'],
        );
        self::assertSame('text', $fields['Record[flag]']['type']);
        // The saved record is read from its properties: only a text control shows a number as a string, and
        // keeps any other value as it is.
        $this->page->page = PageContext::EDIT;
        $values = $this->snapshot($form, static function () use ($form): void {
            $form->submit(['roles' => ['ROLE_USER'], 'count' => '4', 'flag' => '1']);
        });
        self::assertSame(4, $values['Record[count]']);
        self::assertTrue($values['Record[flag]']);
    }

    public function testAnEntryWhoseIdIsNoFormKeyIsKeyedByItsPositionOrItsHash(): void
    {
        $record = (object) ['items' => [(object) ['id' => 1.5], (object) ['id' => "abc\n"]]];
        $this->page->entity = $record;

        $form = $this->factory
            ->createNamedBuilder('Record', FormType::class, $record)
            ->add('items', CollectionType::class, [
                'entry_type' => FormType::class,
                'entry_options' => ['compound' => false],
            ])
            ->getForm();

        self::assertSame(['p0', 'h' . hash('sha256', "abc\n")], array_keys($form->get('items')->all()));
    }

    public function testANewPageReadsNoSavedListKeys(): void
    {
        $account = new Account(['ROLE_B', 'ROLE_C']);
        $this->page->entity = $account;
        $this->listKeys = ['Account[roles]' => ['p1', 'nadded']];

        $form = $this->factory->createNamedBuilder('Account', FormType::class, $account, [
            'data_class' => Account::class,
        ])->add('roles', CollectionType::class, ['entry_type' => TextType::class])->getForm();

        self::assertSame(['p0', 'p1'], array_keys($form->get('roles')->all()));
    }

    public function testOnlyTheRootFormCarriesTheDraft(): void
    {
        $builder = $this->factory->createNamedBuilder('Article', FormType::class, $this->article, [
            'data_class' => Article::class,
        ]);
        $builder->add($builder->create('details', FormType::class, [
            'inherit_data' => true,
        ])->add('title', TextType::class));

        $view = $builder->getForm()->createView();

        self::assertArrayHasKey('data-collaborate', self::vars($view, 'attr'));
        self::assertArrayNotHasKey('data-collaborate', self::vars($view->children['details'], 'attr'));
    }

    public function testANewFormKeepsTheQueryOfItsAction(): void
    {
        $view = $this->factory->createNamedBuilder('Article', FormType::class, $this->article, [
            'data_class' => Article::class,
            'action' => '/admin/article/new?next=/a?b',
        ])->add('title', TextType::class)->getForm()->createView();

        $query = [];
        parse_str((string) parse_url((string) $view->vars['action'], \PHP_URL_QUERY), $query);
        self::assertSame('/a?b', $query['next'] ?? null);
        self::assertSame($this->scope($view)['draft'], $query['draft'] ?? null);
    }
}
