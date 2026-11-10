<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Draft\FormDraft;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use Janalis\Collaborate\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The draft of a form, whatever builds it: what the Drupal module shares entity forms with. */
#[CoversClass(FormDraft::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(Topics::class)]
#[UsesClass(Grant::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
#[UsesClass(RoleDraftAccessChecker::class)]
final class FormDraftTest extends TestCase
{
    private const array FIELDS = ['title' => ['type' => 'text', 'initial' => 'Saved', 'formName' => 'title']];

    /** @var list<string> */
    private array $logged = [];

    public function testScopesAnEditFormByItsRecord(): void
    {
        $request = new Request(server: ['REQUEST_TIME_FLOAT' => 1_700_000_000.5]);
        $scope = $this->draft()->scope($this->record(new: false), 'update', $request, self::FIELDS);

        self::assertSame(hash('sha256', 'node:article:5'), $scope['id']);
        self::assertSame('node:article', $scope['entity']);
        self::assertSame('update', $scope['permission']);
        self::assertFalse($scope['new']);
        self::assertNull($scope['draft']);
        self::assertSame(1_700_000_000.5, $scope['requestedAt'] ?? null);
        self::assertSame(self::FIELDS, $scope['fields']);
    }

    public function testANewRecordSharesTheDraftItsPageNamesOrOneDrawnOncePerRequest(): void
    {
        $named = str_repeat('ab', 16);
        $scope = $this->draft()->scope($this->record(draft: $named), 'create', new Request(), []);
        self::assertSame($named, $scope['draft']);
        self::assertSame(hash('sha256', 'node:article:new:' . $named), $scope['id']);

        $request = new Request();
        $drawn = $this->draft()->scope($this->record(draft: 'NOT-HEX'), 'create', $request, [])['draft'];
        self::assertIsString($drawn);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $drawn);
        self::assertSame($drawn, $this->draft()->scope($this->record(), 'create', $request, [])['draft']);
        self::assertIsFloat($this->draft()->scope($this->record(), 'create', $request, [])['requestedAt'] ?? null);
    }

    public function testConfiguresThePageOfTheForm(): void
    {
        $request = new Request(['tab' => 'main', 'draft' => 'old'], server: ['REQUEST_URI' => '/node/add/article']);
        $scope = $this->draft()->scope($this->record(draft: str_repeat('cd', 16)), 'create', $request, self::FIELDS);
        $configuration = $this->draft(['replay' => ['connected' => 20], 'label_length' => 80])->configuration(
            $scope,
            new TestUser(7),
            $request,
            '/node/__record__/edit',
        );

        self::assertNotNull($configuration);
        self::assertStringStartsWith('{"id":', (string) base64_decode(explode('.', $configuration['grant'])[0], true));
        self::assertSame('/admin/collaborate/__action__', $configuration['url']);
        self::assertSame('token:collaborate-draft', $configuration['csrf']);
        self::assertSame('collaborate:Save', $configuration['messages']['save']);
        self::assertSame(array_keys(FormDraft::MESSAGES), array_keys($configuration['messages']));
        self::assertTrue($configuration['isNew']);
        self::assertSame('/node/__record__/edit', $configuration['editUrl']);
        self::assertSame('/node/add/article?tab=main&draft=' . str_repeat('cd', 16), $configuration['invitation']);
        self::assertSame(
            ['replayConnected' => 20_000, 'replayDisconnected' => 5000, 'joinRetry' => 12_000, 'labelLength' => 80],
            $configuration['config'],
        );
        $edit = $this->draft()->scope($this->record(new: false), 'update', $request, self::FIELDS);
        self::assertNull($this->draft()->configuration($edit, new TestUser(7), $request, '')['invitation'] ?? null);
    }

    public function testAFormTooLargeToSendWithAnUpdateIsEditedWithoutADraft(): void
    {
        $request = new Request();
        $draft = $this->draft(['max_update_bytes' => 2_096_000]);
        $scope = $draft->scope($this->record(new: false), 'update', $request, self::FIELDS);

        self::assertNull($draft->configuration($scope, new TestUser(7), $request, ''));
        self::assertSame(
            ['The {entity} form is too large to share as a draft; it is edited without one.'],
            $this->logged,
        );
    }

    public function testANewRecordsFormPostsTheDraftItsGrantNames(): void
    {
        $draft = str_repeat('ef', 16);
        self::assertSame('/node/add?draft=' . $draft, FormDraft::action('/node/add', $draft));
        self::assertSame('/node/add?tab=1&draft=' . $draft, FormDraft::action('/node/add?tab=1&draft=x', $draft));
        self::assertNull(FormDraft::action('/node/add?draft=' . $draft, $draft));
    }

    public function testASubmissionWithoutAGrantTakesTheOrdinaryPath(): void
    {
        $save = $this->createMock(DraftSave::class);
        $save->expects(self::never())->method('begin');
        $request = Request::create('/node/5/edit', 'POST');
        $scope = $this->draft()->scope($this->record(new: false), 'update', $request, self::FIELDS);

        $this->draft(save: $save)->begin($request, new TestUser(7), $scope, static fn(): array => []);
        self::assertFalse($request->attributes->has('_collaborate_snapshot'));
    }

    public function testASignedSubmissionBeginsTheLockedSaveWithTheRecordsValues(): void
    {
        $request = Request::create('/node/5/edit', 'POST');
        $scope = $this->draft()->scope($this->record(new: false), 'update', $request, self::FIELDS);
        $grant = $this->draft()->configuration($scope, new TestUser(7), $request, '')['grant'] ?? '';
        $request->request->set('_collaborate_grant', $grant);
        $save = $this->createMock(DraftSave::class);
        $save
            ->expects(self::once())
            ->method('begin')
            ->willReturnCallback(static function (
                Request $request,
                array $authorized,
                array $current,
                string $actor,
            ) use ($scope): void {
                self::assertSame($scope['id'], $authorized['id']);
                self::assertSame(['title' => 'Saved'], $current);
                self::assertSame(new SecurityCollaboratorProvider()->label(new TestUser(7)), $actor);
                $request->attributes->set('_collaborate_save', true);
            });
        $snapshot = static fn(): array => ['title' => 'Saved again'];

        $this->draft(save: $save)->begin($request, new TestUser(7), $scope, $snapshot);
        self::assertSame($snapshot, $request->attributes->get('_collaborate_snapshot'));
    }

    public function testASaveTheJournalDidNotLockLeavesNoSnapshot(): void
    {
        $request = Request::create('/node/5/edit', 'POST');
        $scope = $this->draft()->scope($this->record(new: false), 'update', $request, self::FIELDS);
        $request->request->set(
            '_collaborate_grant',
            $this->draft()->configuration($scope, new TestUser(7), $request, '')['grant'] ?? '',
        );

        $this->draft()->begin($request, new TestUser(7), $scope, static fn(): array => []);
        self::assertFalse($request->attributes->has('_collaborate_snapshot'));
    }

    public function testAGrantOfAnotherFormIsRefused(): void
    {
        $request = Request::create('/node/5/edit', 'POST');
        $other = $this->draft()->scope($this->record(new: false, id: '6'), 'update', $request, self::FIELDS);
        $request->request->set(
            '_collaborate_grant',
            $this->draft()->configuration($other, new TestUser(7), $request, '')['grant'] ?? '',
        );
        $scope = $this->draft()->scope($this->record(new: false), 'update', $request, self::FIELDS);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Collaborate grant belongs to another form.');
        $this->draft()->begin($request, new TestUser(7), $scope, static fn(): array => []);
    }

    /** @return array{entity: object, class: string, new: bool, id: string|null, draft: string|null} */
    private function record(bool $new = true, ?string $draft = null, string $id = '5'): array
    {
        return [
            'entity' => new \stdClass(),
            'class' => 'node:article',
            'new' => $new,
            'id' => $new ? null : $id,
            'draft' => $draft,
        ];
    }

    /** @param array{max_update_bytes?: int, replay?: array{connected?: int}, label_length?: int} $options */
    private function draft(array $options = [], ?DraftSave $save = null): FormDraft
    {
        $collaborators = new SecurityCollaboratorProvider();
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/admin/collaborate/join');
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturnCallback(static fn(string $id): CsrfToken => new CsrfToken(
            $id,
            'token:' . $id,
        ));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(
                static fn(string $id, array $parameters, ?string $domain): string => ($domain ?? '') . ':' . $id,
            );
        $logged = &$this->logged;
        $logger = new class($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(
                private array &$logged,
            ) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = (string) $message;
            }
        };

        return new FormDraft(
            new DraftAccess('secret', true, new Topics(), $collaborators, new RoleDraftAccessChecker()),
            $save ?? $this->createStub(DraftSave::class),
            $collaborators,
            $urls,
            $csrf,
            $translator,
            $logger,
            $options,
        );
    }
}
