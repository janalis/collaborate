<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Janalis\Collaborate\Access\RecordDraftAccessCheckerInterface;
use Janalis\Collaborate\Access\RoleDraftAccessChecker;
use Janalis\Collaborate\Draft\DraftAccess;
use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftTooLargeException;
use Janalis\Collaborate\Draft\HeadlessDraft;
use Janalis\Collaborate\Grant;
use Janalis\Collaborate\Hub\Topics;
use Janalis\Collaborate\Identity\SecurityCollaboratorProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[CoversClass(HeadlessDraft::class)]
#[UsesClass(DraftAccess::class)]
#[UsesClass(RoleDraftAccessChecker::class)]
#[UsesClass(DraftTooLargeException::class)]
#[UsesClass(Grant::class)]
#[UsesClass(Topics::class)]
#[UsesClass(SecurityCollaboratorProvider::class)]
final class HeadlessDraftTest extends TestCase
{
    use DraftAccessFactory;

    public function testMaximumSourceStaysOutsideTheGrantAndEveryOperationRechecksAccess(): void
    {
        $user = $this->user();
        $checker = $this->createMock(RecordDraftAccessCheckerInterface::class);
        $checker->method('canEdit')->willReturn(true);
        $checker
            ->expects($this->exactly(3))
            ->method('canEditRecord')
            ->with($user, 'update', \stdClass::class, 'document-1')
            ->willReturn(true);
        $access = $this->access(checker: $checker);
        $journal = $this->createMock(DraftJournal::class);
        $source = str_repeat('x', HeadlessDraft::MAX_SOURCE_BYTES);
        $id = $access->draftId(\stdClass::class, 'document-1');
        $journal
            ->expects($this->once())
            ->method('join')
            ->with($id, ['source' => ['type' => 'source', 'initial' => $source]], 123.5)
            ->willReturn([]);
        $journal
            ->expects($this->once())
            ->method('replay')
            ->with($id, ['source' => ['type' => 'source', 'initial' => '']], 5, 0.0, 'known-digest')
            ->willReturn([]);
        $journal
            ->expects($this->once())
            ->method('append')
            ->with(
                $id,
                ['source' => ['type' => 'source', 'initial' => '']],
                ['field' => 'source'],
                7,
                'alice',
                HeadlessDraft::MAX_SOURCE_BYTES,
            )
            ->willReturn([]);
        $subject = new HeadlessDraft(
            $access,
            $journal,
            new SecurityCollaboratorProvider(),
            new MockClock('2026-01-01'),
        );
        $opened = $subject->open($user, \stdClass::class, 'document-1', $source, 123.5);
        self::assertLessThan(2048, \strlen($opened['grant']));
        self::assertSame([], $opened['state']);
        self::assertSame([], $subject->replay($opened['grant'], $user, 5, 'known-digest'));
        self::assertSame([], $subject->append($opened['grant'], $user, ['field' => 'source']));
    }

    public function testReconciliationUsesHostSourceAndRechecksRecordAccess(): void
    {
        $user = $this->user();
        $checker = $this->createMock(RecordDraftAccessCheckerInterface::class);
        $checker->method('canEdit')->willReturn(true);
        $checker->expects($this->exactly(2))->method('canEditRecord')->willReturn(true);
        $access = $this->access(checker: $checker);
        $journal = $this->createMock(DraftJournal::class);
        $journal->method('join')->willReturn([]);
        $journal
            ->expects($this->once())
            ->method('reconcile')
            ->with(
                $access->draftId(\stdClass::class, '1'),
                1,
                2,
                ['source' => ['type' => 'source', 'initial' => 'external source']],
                false,
                123.5,
                7,
                'alice',
            )
            ->willReturn([]);
        $subject = new HeadlessDraft($access, $journal, new SecurityCollaboratorProvider());
        $opened = $subject->open($user, \stdClass::class, '1', 'old');
        self::assertSame([], $subject->reconcile($opened['grant'], $user, 1, 2, 'external source', false, 123.5));
    }

    public function testCannotOpenWithTheDefaultClassOnlyChecker(): void
    {
        $journal = $this->createMock(DraftJournal::class);
        $journal->expects($this->never())->method('join');
        $subject = new HeadlessDraft($this->access(), $journal, new SecurityCollaboratorProvider());
        $this->expectException(AccessDeniedHttpException::class);
        $subject->open($this->user(), \stdClass::class, '1', 'source');
    }

    public function testRejectsInvalidIdentity(): void
    {
        $subject = new HeadlessDraft(
            $this->access(),
            $this->createStub(DraftJournal::class),
            new SecurityCollaboratorProvider(),
        );
        $this->expectException(\InvalidArgumentException::class);
        $subject->open($this->user(), '', '1', 'source');
    }

    public function testRejectsOversizedSource(): void
    {
        $subject = new HeadlessDraft(
            $this->access(),
            $this->createStub(DraftJournal::class),
            new SecurityCollaboratorProvider(),
        );
        $this->expectException(DraftTooLargeException::class);
        $subject->open($this->user(), \stdClass::class, '1', str_repeat('x', HeadlessDraft::MAX_SOURCE_BYTES + 1));
    }

    public function testRejectsInvalidUtf8(): void
    {
        $subject = new HeadlessDraft(
            $this->access(),
            $this->createStub(DraftJournal::class),
            new SecurityCollaboratorProvider(),
        );
        $this->expectException(DraftTooLargeException::class);
        $subject->open($this->user(), \stdClass::class, '1', "\xFF");
    }

    public function testRejectsAFormGrant(): void
    {
        $access = $this->access();
        $subject = new HeadlessDraft(
            $access,
            $this->createStub(DraftJournal::class),
            new SecurityCollaboratorProvider(),
            new MockClock('@101'),
        );
        $grant = $access->issue(self::SCOPE, 7, 100, $this->user()->getRoles());
        $this->expectException(AccessDeniedHttpException::class);
        $subject->replay($grant, $this->user(), 0);
    }

    public function testCheckpointMarksOnlyTheExactStoredSnapshotCleanUnderTheJournalLock(): void
    {
        $user = $this->user();
        $checker = $this->createStub(RecordDraftAccessCheckerInterface::class);
        $checker->method('canEdit')->willReturn(true);
        $checker->method('canEditRecord')->willReturn(true);
        $access = $this->access(checker: $checker);
        $scope = [
            'id' => $access->draftId(\stdClass::class, '1'),
            'entity' => \stdClass::class,
            'record' => '1',
            'permission' => 'update',
            'new' => false,
            'draft' => null,
            'headless' => true,
            'fields' => ['source' => ['type' => 'source', 'initial' => '']],
        ];
        $grant = $access->issue($scope, 7, 100, $user->getRoles());
        $journal = $this->createMock(DraftJournal::class);
        $journal->expects($this->once())->method('lock')->with($scope['id']);
        $journal
            ->expects($this->once())
            ->method('replay')
            ->willReturn(['generation' => 1, 'revision' => 2, 'fields' => ['source' => ['value' => 'saved']]]);
        $journal
            ->expects($this->once())
            ->method('saved')
            ->with($scope['id'], ['source' => 'saved'], ['userId' => 7, 'label' => 'alice']);
        $journal->expects($this->once())->method('unlock')->with($scope['id']);
        $subject = new HeadlessDraft($access, $journal, new SecurityCollaboratorProvider(), new MockClock('@101'));
        self::assertTrue($subject->checkpointIfCurrent($grant, $user, 1, 2, 'saved'));
    }

    /** @return iterable<array{int, int, string}> */
    public static function staleCheckpoints(): iterable
    {
        yield [2, 2, 'saved'];
        yield [1, 3, 'saved'];
        yield [1, 2, 'newer'];
    }

    #[DataProvider('staleCheckpoints')]
    public function testCheckpointNeverMarksNewerOrDifferentSourceClean(
        int $generation,
        int $revision,
        string $source,
    ): void {
        $checker = $this->createStub(RecordDraftAccessCheckerInterface::class);
        $checker->method('canEdit')->willReturn(true);
        $checker->method('canEditRecord')->willReturn(true);
        $access = $this->access(checker: $checker);
        $scope = [
            'id' => $access->draftId(\stdClass::class, '1'),
            'entity' => \stdClass::class,
            'record' => '1',
            'permission' => 'update',
            'new' => false,
            'draft' => null,
            'headless' => true,
            'fields' => ['source' => ['type' => 'source', 'initial' => '']],
        ];
        $grant = $access->issue($scope, 7, 100, $this->user()->getRoles());
        $journal = $this->createMock(DraftJournal::class);
        $journal->expects($this->once())->method('lock');
        $journal
            ->expects($this->once())
            ->method('replay')
            ->willReturn([
                'generation' => $generation,
                'revision' => $revision,
                'fields' => ['source' => ['value' => $source]],
            ]);
        $journal->expects($this->never())->method('saved');
        $journal->expects($this->once())->method('unlock');
        $subject = new HeadlessDraft($access, $journal, new SecurityCollaboratorProvider(), new MockClock('@101'));
        self::assertFalse($subject->checkpointIfCurrent($grant, $this->user(), 1, 2, 'saved'));
    }
}
