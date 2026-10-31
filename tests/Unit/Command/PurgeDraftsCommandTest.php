<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Command;

use Janalis\Collaborate\Command\PurgeDraftsCommand;
use Janalis\Collaborate\Draft\DraftJournal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PurgeDraftsCommand::class)]
final class PurgeDraftsCommandTest extends TestCase
{
    public function testPurgesAsOfNowAndReportsTheCount(): void
    {
        $journal = $this->createMock(DraftJournal::class);
        $before = new \DateTimeImmutable();
        $journal
            ->expects($this->once())
            ->method('purge')
            ->with(self::callback(
                static fn(\DateTimeImmutable $now): bool => $now >= $before && $now <= new \DateTimeImmutable(),
            ))
            ->willReturn(3);
        $command = new PurgeDraftsCommand($journal);
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame('collaborate:purge', $command->getName());
        self::assertStringContainsString('Purged 3 shared draft(s).', $tester->getDisplay());
    }
}
