<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification;

use Janalis\Collaborate\Notification\MandatoryTopicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MandatoryTopicException::class)]
final class MandatoryTopicExceptionTest extends TestCase
{
    public function testNamesTheTopic(): void
    {
        $exception = new MandatoryTopicException('security');
        self::assertSame('security', $exception->topic);
        self::assertSame('Every user is subscribed to the notification topic "security".', $exception->getMessage());
    }
}
