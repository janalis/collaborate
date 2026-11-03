<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Functional\Draft;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Janalis\Collaborate\Doctrine\RecordIdentifier;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Draft\DraftValues;
use Janalis\Collaborate\Draft\EventValidator;
use Janalis\Collaborate\Draft\FieldDefinitions;
use Janalis\Collaborate\Tests\Fixtures\Draft\Article;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * The save guard around an edit page's Save, on the journal of {@see DraftJournalTest}'s database.
 *
 * @phpstan-import-type SignedScope from DraftAccess
 */
#[CoversClass(DraftSave::class)]
#[UsesClass(DraftJournal::class)]
#[UsesClass(DraftValues::class)]
#[UsesClass(EventValidator::class)]
#[UsesClass(FieldDefinitions::class)]
#[UsesClass(RecordIdentifier::class)]
final class DraftSaveTest extends TestCase
{
    /** @var array<string, array{type: string, initial: mixed}> */
    private const array FIELDS = ['choice' => ['type' => 'value', 'initial' => 'a']];

    private Connection $db;
    private DraftJournal $journal;
    private Request $request;
    private RequestStack $requests;

    protected function setUp(): void
    {
        $this->db = DraftJournalTest::connection();
        self::assertStringContainsString('test', (string) $this->db->fetchOne('SELECT DATABASE()'));
        foreach (DraftJournalTest::schema() as $sql) {
            $this->db->executeStatement(str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $sql));
            $this->db->executeStatement(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $sql));
        }
        $this->journal = new DraftJournal($this->db, firstGeneration: 1);
        $this->journal->join('record', self::FIELDS);
        $this->request = new Request(request: ['_collaborate_generation' => '1', '_collaborate_revision' => '0']);
        $this->requests = new RequestStack();
        $this->requests->push($this->request);
    }

    protected function tearDown(): void
    {
        $this->journal->reset();
        $this->db->close();
    }

    /** @return SignedScope */
    private function scope(bool $new = false): array
    {
        return [
            'id' => 'record',
            'entity' => Article::class,
            'permission' => $new ? 'create' : 'update',
            'new' => $new,
            'draft' => $new ? 'key' : null,
            'fields' => self::FIELDS,
            'userId' => 7,
            'expires' => time() + 100,
            'rolesHash' => 'hash',
        ];
    }

    private function guard(?DraftJournal $journal = null, ?AbstractLogger $logger = null): DraftSave
    {
        return new DraftSave(
            $journal ?? $this->journal,
            $this->requests,
            new RecordIdentifier($this->createStub(ManagerRegistry::class)),
            $logger ?? new NullLogger(),
            new IdentityTranslator(),
        );
    }

    /** Begins a Save of the record, which the admin then persists. */
    private function persisted(DraftSave $guard): void
    {
        $guard->begin($this->request, $this->scope(), ['choice' => 'a'], 'Alice');
        $this->request->attributes->set('_collaborate_snapshot', static fn(): array => ['choice' => 'b']);
        $guard->completed(new Article());
    }

    private function respond(DraftSave $guard, Response $response): void
    {
        $guard->finish(
            new ResponseEvent(
                $this->createStub(KernelInterface::class),
                $this->request,
                HttpKernelInterface::MAIN_REQUEST,
                $response,
            ),
        );
    }

    private function locked(): bool
    {
        $name = 'collaborate:' . substr(hash('xxh128', (string) $this->db->getDatabase()), 0, 16) . ':' . md5('record');

        return null !== $this->db->fetchOne('SELECT IS_USED_LOCK(?)', [$name]);
    }

    public function testTheGuardCheckpointsAfterEveryOtherResponseListenerAndHandlesExceptionsFirst(): void
    {
        self::assertSame(
            [KernelEvents::RESPONSE => ['finish', -2048], KernelEvents::EXCEPTION => ['failed', 2048]],
            DraftSave::getSubscribedEvents(),
        );
    }

    public function testAnErrorResponseLeavesTheDraftUnsaved(): void
    {
        $guard = $this->guard();
        $this->persisted($guard);

        $this->respond($guard, new Response('', Response::HTTP_BAD_REQUEST));

        self::assertFalse($this->locked());
        self::assertFalse($this->request->attributes->has('_collaborate_committed'));
        self::assertSame('a', $this->journal->replay('record', self::FIELDS, 0)['fields']['choice']['saved']);
    }

    public function testTheLockIsReleasedEvenWhenLoggingAFailedCheckpointFails(): void
    {
        $journal = new class($this->db) extends DraftJournal {
            #[\Override]
            public function saved(string $id, array $values, ?array $actor = null): void
            {
                throw new \RuntimeException('checkpoint failed');
            }
        };
        $logger = new class extends AbstractLogger {
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                throw new \LogicException('The log is unavailable.');
            }
        };
        $guard = $this->guard($journal, $logger);
        $this->persisted($guard);

        try {
            $this->respond($guard, new Response());
            self::fail('The logger failure was swallowed.');
        } catch (\LogicException $failure) {
            self::assertSame('The log is unavailable.', $failure->getMessage());
        }

        self::assertFalse($this->locked());
    }

    public function testTheRedirectAfterSavingAnExistingRecordKeepsItsQuery(): void
    {
        $guard = $this->guard();
        $this->persisted($guard);
        $redirect = new Response(status: Response::HTTP_FOUND, headers: [
            'Location' => '/article/1/edit?draft=key&tab=2',
        ]);

        $this->respond($guard, $redirect);

        self::assertSame('/article/1/edit?draft=key&tab=2', $redirect->headers->get('Location'));
        self::assertSame('b', $this->journal->replay('record', self::FIELDS, 0)['fields']['choice']['saved']);
    }

    public function testASubRequestFailingDuringASaveLeavesTheSaveInProgress(): void
    {
        $guard = $this->guard();
        $guard->begin($this->request, $this->scope(), ['choice' => 'a'], 'Alice');

        $guard->failed(
            new ExceptionEvent(
                $this->createStub(KernelInterface::class),
                $this->request,
                HttpKernelInterface::SUB_REQUEST,
                new \RuntimeException('A fragment failed.'),
            ),
        );

        self::assertTrue($this->locked());
        self::assertTrue($this->request->attributes->has('_collaborate_save'));
    }

    public function testAFailedMainRequestEndsTheSave(): void
    {
        $guard = $this->guard();
        $guard->begin($this->request, $this->scope(), ['choice' => 'a'], 'Alice');

        $guard->failed(
            new ExceptionEvent(
                $this->createStub(KernelInterface::class),
                $this->request,
                HttpKernelInterface::MAIN_REQUEST,
                new \RuntimeException('The page failed.'),
            ),
        );

        self::assertFalse($this->locked());
        self::assertFalse($this->request->attributes->has('_collaborate_save'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unsynchronized(): iterable
    {
        yield 'generation that is no string' => [['_collaborate_generation' => 1, '_collaborate_revision' => '0']];
        yield 'no revision' => [['_collaborate_generation' => '1']];
        yield 'revision that is no number' => [['_collaborate_generation' => '1', '_collaborate_revision' => 'abc']];
    }

    /** @param array<string, mixed> $posted */
    #[DataProvider('unsynchronized')]
    public function testAFormPostedWithoutItsSynchronizedRevisionIsSentBackToSynchronize(array $posted): void
    {
        $request = new Request(request: $posted);

        try {
            $this->guard()->begin($request, $this->scope(), [], 'Alice');
            self::fail('A save without its revision was accepted.');
        } catch (ConflictHttpException $conflict) {
            self::assertSame(DraftSave::SYNC, $conflict->getMessage());
            self::assertSame(DraftSave::SYNC, $request->attributes->get('_collaborate_conflict'));
        }
        self::assertFalse($this->locked());
    }

    public function testTheRedirectAfterCreatingARecordDropsOnlyTheDraftKeyFromItsQuery(): void
    {
        $guard = $this->guard();
        $guard->begin($this->request, $this->scope(true), ['choice' => 'a'], 'Alice');
        $guard->completed(new Article());
        $redirect = new Response(status: Response::HTTP_FOUND, headers: [
            'Location' => '/article/new?draft=key&next=/list?page=2',
        ]);

        $this->respond($guard, $redirect);

        self::assertSame('/article/new?next=%2Flist%3Fpage%3D2', $redirect->headers->get('Location'));
    }

    public function testARecordPersistedOutsideASaveOfTheDraftIsLeftAlone(): void
    {
        $this->guard()->completed(new Article());

        self::assertFalse($this->request->attributes->has('_collaborate_save'));
    }

    public function testASubRequestResponseDoesNotEndTheSave(): void
    {
        $guard = $this->guard();
        $this->persisted($guard);

        $guard->finish(
            new ResponseEvent(
                $this->createStub(KernelInterface::class),
                $this->request,
                HttpKernelInterface::SUB_REQUEST,
                new Response(),
            ),
        );

        self::assertTrue($this->locked());
        self::assertTrue($this->request->attributes->has('_collaborate_save'));
        self::assertSame('a', $this->journal->replay('record', self::FIELDS, 0)['fields']['choice']['saved']);
    }
}
