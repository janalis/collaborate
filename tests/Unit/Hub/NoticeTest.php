<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub;

use Janalis\Collaborate\Hub\Notice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Notice::class)]
final class NoticeTest extends TestCase
{
    public function testOneTopicIsAListOfOne(): void
    {
        $notice = new Notice('urn:a', '{"channel":"cursors"}');

        self::assertSame(['urn:a'], $notice->topics);
        self::assertSame('{"channel":"cursors"}', $notice->data);
    }

    public function testATopicNamedTwiceIsKeptOnceAndTheListIsReindexed(): void
    {
        self::assertSame(['urn:a', 'urn:b'], new Notice(['urn:a', 'urn:b'], '{}')->topics);
        self::assertSame(['urn:a', 'urn:b', 'urn:c'], new Notice(['urn:a', 'urn:b', 'urn:a', 'urn:c'], '{}')->topics);
    }

    public function testTheBundlesNoticesNameTheirChannelAndNoContent(): void
    {
        $draft = Notice::draft('urn:draft', 'd1', 'discard');
        self::assertSame(['urn:draft'], $draft->topics);
        self::assertSame('{"channel":"draft","draft":"d1","kind":"discard"}', $draft->data);
        // An append names its place in the journal.
        self::assertSame(
            '{"channel":"draft","draft":"d1","kind":"append","generation":3,"revision":7}',
            Notice::draft('urn:draft', 'd1', 'append', ['generation' => 3, 'revision' => 7])->data,
        );
        $records = Notice::records(['urn:a', 'urn:b']);
        self::assertSame(['urn:a', 'urn:b'], $records->topics);
        self::assertSame('{"channel":"draft","kind":"record"}', $records->data);
        self::assertSame(
            '{"channel":"updates","kind":"saved","entities":["Article","Tag"]}',
            Notice::updates('urn:updates', ['Article', 'Tag'])->data,
        );
        self::assertSame('{"channel":"notifications"}', Notice::notifications('urn:n')->data);
    }

    public function testANoticeNamesAtLeastOneTopic(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A notice names at least one topic.');
        new Notice([], '{}');
    }
}
