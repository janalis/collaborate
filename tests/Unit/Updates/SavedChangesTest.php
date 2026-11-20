<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Updates;

use Janalis\Collaborate\Updates\SavedChanges;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(SavedChanges::class)]
final class SavedChangesTest extends TestCase
{
    public function testEachSaveOfARequestAddsToWhatTheOthersRecordedOnce(): void
    {
        $request = new Request();
        self::assertFalse(SavedChanges::hasData($request));
        self::assertSame([], SavedChanges::dataNames($request));
        self::assertSame([], SavedChanges::recordDrafts($request));
        self::assertNull(SavedChanges::committedDraft($request));

        // A save that changed no named view still counts: the views marked for any save refresh.
        SavedChanges::data($request);
        self::assertTrue(SavedChanges::hasData($request));
        SavedChanges::data($request, 'Tag', 'Article');
        SavedChanges::data($request, 'node', 'Tag');
        self::assertSame(['Tag', 'Article', 'node'], SavedChanges::dataNames($request));

        SavedChanges::records($request, 'b', '42');
        SavedChanges::records($request, '42', 'a');
        // A numeric id stays the string it was.
        self::assertSame(['b', '42', 'a'], SavedChanges::recordDrafts($request));

        SavedChanges::committed($request, 'b');
        self::assertSame('b', SavedChanges::committedDraft($request));
    }

    public function testAClassNameReadsAsItsShortNameAndAnyOtherNameAsIs(): void
    {
        $request = new Request();
        SavedChanges::data($request, 'App\\Entity\\Tag', 'admin/article', 'Tag', 'Article');

        self::assertSame(['Tag', 'admin/article', 'Article'], SavedChanges::dataNames($request));
    }

    public function testWhatIsNoNameIsSkipped(): void
    {
        $request = new Request(attributes: [
            '_collaborate_data_changed' => [7, 'Tag', null, 'Tag', '', 'App\\Entity\\'],
            '_collaborate_records_changed' => [42, 'a', ''],
            '_collaborate_committed' => 42,
        ]);
        self::assertSame(['Tag'], SavedChanges::dataNames($request));
        self::assertSame(['a'], SavedChanges::recordDrafts($request));
        self::assertNull(SavedChanges::committedDraft($request));
        SavedChanges::committed($request, '');
        self::assertNull(SavedChanges::committedDraft($request));
    }
}
