<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Command;

use Janalis\Collaborate\Draft\DraftJournal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes idle shared drafts ({@see DraftJournal::purge()}). Every visit of a new-record page opens a
 * draft, so the tables only stay bounded with this running from cron (daily).
 */
#[AsCommand(
    name: 'collaborate:purge',
    description: 'Delete idle shared drafts: saved or created ones after drafts.purge.clean_days, any after drafts.purge.unsaved_days.',
)]
final class PurgeDraftsCommand extends Command
{
    public function __construct(
        private readonly DraftJournal $journal,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $purged = $this->journal->purge(new \DateTimeImmutable());
        new SymfonyStyle($input, $output)->success(\sprintf('Purged %d shared draft(s).', $purged));

        return Command::SUCCESS;
    }
}
