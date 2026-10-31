<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub;

use Janalis\Collaborate\Hub\Topics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Topics::class)]
final class TopicsTest extends TestCase
{
    public function testNamesEveryTopicUnderThePrefix(): void
    {
        $topics = new Topics();
        self::assertSame('urn:collaborate:cursors:room', $topics->cursors('room'));
        self::assertSame('urn:collaborate:draft:id', $topics->draft('id'));
        self::assertSame('urn:collaborate:updates', $topics->updates());

        $prefixed = new Topics('https://app.example.com/topics/');
        self::assertSame('https://app.example.com/topics/cursors:room', $prefixed->cursors('room'));
        self::assertSame('https://app.example.com/topics/draft:id', $prefixed->draft('id'));
        self::assertSame('https://app.example.com/topics/updates', $prefixed->updates());
    }
}
