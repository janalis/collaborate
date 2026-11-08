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

    public function testANoticeNamesAtLeastOneTopic(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A notice names at least one topic.');
        new Notice([], '{}');
    }
}
