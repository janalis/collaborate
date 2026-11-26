<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Janalis\Collaborate\Draft\DraftSource;
use Janalis\Collaborate\Draft\DraftTooLargeException;
use Janalis\Collaborate\Draft\InvalidDraftEventException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DraftSource::class)]
#[UsesClass(InvalidDraftEventException::class)]
#[UsesClass(DraftTooLargeException::class)]
final class DraftSourceTest extends TestCase
{
    private const string A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const string B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    private const string C = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

    /** @param array{run: string, offset: int}|null $after
     * @return array<string, mixed>
     */
    private function insert(string $id, string $text, ?array $after, int $clock = 1): array
    {
        return [
            '__className' => 'SourceTransaction',
            'id' => $id,
            'clock' => $clock,
            'inserts' => [['id' => $id . ':0', 'after' => $after, 'text' => $text]],
            'deletes' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function toggle(string $id, string $target, bool $active, int $clock): array
    {
        return [
            '__className' => 'SourceToggle',
            'id' => $id,
            'target' => $target,
            'active' => $active,
            'clock' => $clock,
        ];
    }

    public function testUndoRetainsPeerInsertionAnchoredInsideTheHiddenRun(): void
    {
        $subject = new DraftSource();
        $first = $subject->apply('ab', [], [$this->insert(self::A, 'XYZ', ['run' => 'initial', 'offset' => 0])], 1);
        $second = $subject->apply(
            'ab',
            $first['events'],
            [$this->insert(self::B, '😀', ['run' => self::A . ':0', 'offset' => 0], 2)],
            2,
        );
        self::assertSame('aX😀YZb', $second['text']);
        $undo = $subject->apply('ab', $second['events'], [$this->toggle(self::C, self::A, false, 3)], 1);
        self::assertSame('a😀b', $undo['text']);
        self::assertSame(1, $undo['operations'][0]['actor']);
        $redo = $subject->apply(
            'ab',
            $undo['events'],
            [$this->toggle('dddddddd-dddd-dddd-dddd-dddddddddddd', self::A, true, 4)],
            1,
        );
        self::assertSame('aX😀YZb', $redo['text']);
    }

    public function testOverlappingDeletesRetainTheOtherAuthorsMaskAfterUndo(): void
    {
        $subject = new DraftSource();
        $a = [
            '__className' => 'SourceTransaction',
            'id' => self::A,
            'clock' => 1,
            'inserts' => [],
            'deletes' => [['run' => 'initial', 'start' => 1, 'end' => 3]],
        ];
        $b = [
            '__className' => 'SourceTransaction',
            'id' => self::B,
            'clock' => 1,
            'inserts' => [],
            'deletes' => [['run' => 'initial', 'start' => 2, 'end' => 3]],
        ];
        $state = $subject->apply('abcd', [], [$a], 1);
        $state = $subject->apply('abcd', $state['events'], [$b], 2);
        self::assertSame('ad', $state['text']);
        $state = $subject->apply('abcd', $state['events'], [$this->toggle(self::C, self::A, false, 2)], 1);
        self::assertSame('abd', $state['text']);
    }

    public function testConcurrentSiblingOrderDoesNotDependOnArrivalOrder(): void
    {
        $subject = new DraftSource();
        $a = $this->insert(self::A, 'A', null) + ['actor' => 1];
        $b = $this->insert(self::B, 'B', null) + ['actor' => 2];
        self::assertSame('ABx', $subject->apply('x', [$a, $b], [], 1)['text']);
        self::assertSame('ABx', $subject->apply('x', [$b, $a], [], 1)['text']);
        $b['clock'] = 2;
        self::assertSame('BAx', $subject->apply('x', [$a, $b], [], 1)['text']);
    }

    public function testAToggleCannotImpersonateAnotherAuthor(): void
    {
        $subject = new DraftSource();
        $state = $subject->apply('ab', [], [$this->insert(self::A, 'X', null)], 1);
        $this->expectException(InvalidDraftEventException::class);
        $subject->apply('ab', $state['events'], [$this->toggle(self::B, self::A, false, 2) + ['actor' => 1]], 2);
    }

    public function testStaleToggleCannotOverrideANewerToggle(): void
    {
        $subject = new DraftSource();
        $state = $subject->apply('x', [], [$this->insert(self::A, 'A', null)], 1);
        $state = $subject->apply(
            'x',
            $state['events'],
            [$this->toggle(self::B, self::A, false, 2), $this->toggle(self::C, self::A, true, 1)],
            1,
        );
        self::assertSame('x', $state['text']);
    }

    public function testRejectsInvalidAnchorsAndRunNames(): void
    {
        $subject = new DraftSource();
        $this->expectException(InvalidDraftEventException::class);
        $subject->apply('a', [], [$this->insert(self::A, 'x', ['run' => 'initial', 'offset' => 1])], 1);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedOperations(): iterable
    {
        $valid = [
            '__className' => 'SourceTransaction',
            'id' => self::A,
            'clock' => 1,
            'inserts' => [],
            'deletes' => [],
        ];
        yield 'invalid id' => [['id' => 'bad'] + $valid];
        yield 'empty transaction' => [$valid];
        yield 'missing inserts' => [['inserts' => null] + $valid];
        yield 'scalar insert' => [['inserts' => [1]] + $valid];
        yield 'scalar anchor' => [['inserts' => [['id' => self::A . ':0', 'text' => 'x', 'after' => 1]]] + $valid];
        yield 'scalar deletion' => [['deletes' => [1]] + $valid];
        yield 'bad insertion' => [['inserts' => [['id' => 'bad', 'text' => 'x', 'after' => null]]] + $valid];
        yield 'bad deletion' => [['deletes' => [['run' => 'initial', 'start' => 0, 'end' => 2]]] + $valid];
        yield 'unknown operation' => [['__className' => 'Unknown'] + $valid];
    }

    /** @param array<string, mixed> $operation */
    #[DataProvider('malformedOperations')]
    public function testMalformedOperationsAreRejected(array $operation): void
    {
        $this->expectException(InvalidDraftEventException::class);
        new DraftSource()->apply('a', [], [$operation], 1);
    }

    public function testSequentialCharacterInsertionsDoNotHaveARecursiveDepthLimit(): void
    {
        $inserts = [];
        for ($i = 0; $i < 512; ++$i) {
            $inserts[] = [
                'id' => self::A . ':' . $i,
                'text' => 'x',
                'after' => ['run' => 0 === $i ? 'initial' : self::A . ':' . ($i - 1), 'offset' => 0],
            ];
        }
        $result = new DraftSource()->apply(
            'a',
            [],
            [[
                '__className' => 'SourceTransaction',
                'id' => self::A,
                'clock' => 1,
                'inserts' => $inserts,
                'deletes' => [],
            ]],
            1,
        );
        self::assertSame('a' . str_repeat('x', 512), $result['text']);
    }

    public function testLargeDeletionDescriptorsAreAcceptedWithinTheJournalByteLimit(): void
    {
        $deletes = array_fill(0, 2050, ['run' => 'initial', 'start' => 0, 'end' => 1]);
        $result = new DraftSource()->apply(
            'abc',
            [],
            [[
                '__className' => 'SourceTransaction',
                'id' => self::A,
                'clock' => 1,
                'inserts' => [],
                'deletes' => $deletes,
            ]],
            1,
        );
        self::assertSame('bc', $result['text']);
        self::assertSame($deletes, $result['operations'][0]['deletes']);
    }

    public function testNewOperationsCannotJumpTheObservedLogicalClock(): void
    {
        $this->expectException(InvalidDraftEventException::class);
        new DraftSource()->apply('a', [], [$this->insert(self::A, 'x', null, 9_007_199_254_740_991)], 1);
    }

    public function testStoredHistoryClocksRemainReplayableAndNewOperationsCanAdvanceOneStep(): void
    {
        $history = [$this->insert(self::A, 'x', null, 100) + ['actor' => 1]];
        $result = new DraftSource()->apply('a', $history, [$this->insert(self::B, 'y', null, 101)], 1);
        self::assertSame('yxa', $result['text']);
    }

    public function testRunCountIsBounded(): void
    {
        $events = [];
        for ($transaction = 0; $transaction < 5; ++$transaction) {
            $id = 'transaction-id-000000-' . $transaction;
            $inserts = [];
            for ($i = 0; $i < 2048; ++$i) {
                $inserts[] = ['id' => $id . ':' . $i, 'text' => 'x', 'after' => null];
            }
            $events[] = [
                '__className' => 'SourceTransaction',
                'id' => $id,
                'clock' => 1,
                'inserts' => $inserts,
                'deletes' => [],
            ];
        }
        $this->expectException(DraftTooLargeException::class);
        new DraftSource()->apply('a', [], $events, 1);
    }
}
