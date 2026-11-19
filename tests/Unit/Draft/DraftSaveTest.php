<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Draft;

use Janalis\Collaborate\Draft\DraftJournal;
use Janalis\Collaborate\Draft\DraftLocks;
use Janalis\Collaborate\Draft\DraftSave;
use Janalis\Collaborate\Draft\FieldNames;
use Janalis\Collaborate\Draft\RecordId;
use Janalis\Collaborate\Draft\RecordIdentifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(DraftSave::class)]
#[CoversClass(RecordId::class)]
#[UsesClass(DraftLocks::class)]
#[UsesClass(FieldNames::class)]
final class DraftSaveTest extends TestCase
{
    public function testANewRecordWithAnObjectIdentifierIsCheckpointedUnderItsString(): void
    {
        // A UUID or ULID key: Doctrine hands back the `Uid` object, which is Stringable.
        $uuid = new class implements \Stringable {
            public function __toString(): string
            {
                return '0193a6c4-8f2e-7c1a-9b3d-5e6f7a8b9c0d';
            }
        };
        $identifier = $this->createStub(RecordIdentifierInterface::class);
        $identifier->method('value')->willReturn($uuid);
        $journal = $this->createMock(DraftJournal::class);
        $journal->expects(self::once())->method('created')->with('draft-1', '0193a6c4-8f2e-7c1a-9b3d-5e6f7a8b9c0d');
        $request = new Request();
        $request->attributes->set('_collaborate_save', [
            'scope' => ['new' => true, 'id' => 'draft-1'],
            'completed' => false,
            'actor' => ['id' => 1, 'name' => 'Ada'],
        ]);
        $requests = new RequestStack();
        $requests->push($request);

        new DraftSave(
            $journal,
            $requests,
            $identifier,
            new NullLogger(),
            $this->createStub(TranslatorInterface::class),
        )->completed(new \stdClass());

        self::assertTrue($request->attributes->all('_collaborate_save')['completed']);
    }

    public function testOnlyScalarAndStringableIdentifiersHaveAString(): void
    {
        self::assertSame('42', RecordId::string(42));
        self::assertSame('a-slug', RecordId::string('a-slug'));
        self::assertSame('u', RecordId::string(new class implements \Stringable {
            public function __toString(): string
            {
                return 'u';
            }
        }));
        self::assertNull(RecordId::string(null));
        self::assertNull(RecordId::string(4.2));
        self::assertNull(RecordId::string(['id' => 1]));
        self::assertNull(RecordId::string(new \stdClass()));
    }
}
