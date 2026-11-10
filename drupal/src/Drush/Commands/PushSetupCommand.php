<?php

declare(strict_types=1);

namespace Drupal\collaborate\Drush\Commands;

use Janalis\Collaborate\Command\PushSetupCommand as BundleCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * `drush collaborate:push:setup`: the bundle's command, as the module registers it; a reminder of what it needs when that is off.
 */
final class PushSetupCommand extends Command
{
    public static function create(ContainerInterface $container): Command
    {
        $command = $container->has(BundleCommand::class) ? $container->get(BundleCommand::class) : null;

        return $command instanceof Command ? $command : new self();
    }

    protected function configure(): void
    {
        $this->setName('collaborate:push:setup')->setDescription('Needs notifications.')->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>collaborate:push:setup needs notifications.</error>');

        return self::FAILURE;
    }
}
