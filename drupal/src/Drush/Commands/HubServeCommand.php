<?php

declare(strict_types=1);

namespace Drupal\collaborate\Drush\Commands;

use Janalis\Collaborate\Command\HubServeCommand as BundleCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * `drush collaborate:hub:serve`: the bundle's command, as the module registers it; a reminder of what it needs when that is off.
 */
final class HubServeCommand extends Command
{
    public static function create(ContainerInterface $container): Command
    {
        $command = $container->has(BundleCommand::class) ? $container->get(BundleCommand::class) : null;

        return $command instanceof Command ? $command : new self();
    }

    protected function configure(): void
    {
        $this->setName('collaborate:hub:serve')->setDescription('Needs the websocket hub transport.')->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(
            '<error>collaborate:hub:serve needs the websocket hub transport: $settings[\'collaborate\'][\'hub\'][\'transport\'] = \'websocket\' in settings.php.</error>',
        );

        return self::FAILURE;
    }
}
