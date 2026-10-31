<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared\Command;

use Doctrine\ORM\EntityManagerInterface;
use Janalis\Collaborate\Tests\Application\Shared\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** `app:setup`: (re)creates the application's database, its tables and the bundle's, with the fixtures. */
#[AsCommand(name: 'app:setup', description: 'Recreate the test application database and load its fixtures.')]
final class SetupCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Database::reset($this->em);
        $output->writeln(\sprintf(
            'Database "%s" is ready.',
            (string) ($this->em->getConnection()->getParams()['dbname'] ?? ''),
        ));

        return Command::SUCCESS;
    }
}
