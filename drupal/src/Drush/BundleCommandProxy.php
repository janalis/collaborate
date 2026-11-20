<?php

declare(strict_types=1);

namespace Drupal\collaborate\Drush;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A command of the bundle under Drush: the bundle's own when the module registers it, otherwise a hidden command
 * that says what it needs. Outside Commands/, which Drush reads its commands from.
 */
abstract class BundleCommandProxy extends Command
{
    /** @return class-string<Command> the bundle's command */
    abstract protected static function bundleCommand(): string;

    /** The command's name, the bundle's. */
    abstract protected static function commandName(): string;

    /** What the bundle's command needs, which is off: `the websocket hub transport`, `shared drafts`. */
    abstract protected static function needs(): string;

    /** How to turn it on, after the sentence saying it is needed; empty when its name says enough. */
    protected static function hint(): string
    {
        return '';
    }

    // Final, so that create() may build any proxy alike.
    // @mago-expect lint:no-redundant-method-override
    final public function __construct()
    {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): Command
    {
        $command = $container->has(static::bundleCommand()) ? $container->get(static::bundleCommand()) : null;

        return $command instanceof Command ? $command : new static();
    }

    final protected function configure(): void
    {
        $this
            ->setName(static::commandName())
            ->setDescription(\sprintf('Needs %s.', static::needs()))
            ->setHidden(true);
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(\sprintf(
            '<error>%s needs %s%s.</error>',
            static::commandName(),
            static::needs(),
            '' === static::hint() ? '' : ': ' . static::hint(),
        ));

        return self::FAILURE;
    }
}
