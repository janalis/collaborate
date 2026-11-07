<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Command;

use Janalis\Collaborate\Notification\Push\EnvFile;
use Janalis\Collaborate\Notification\Push\PushSubscriptions;
use Janalis\Collaborate\Notification\Push\Vapid;
use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Makes the VAPID keys web push signs with and puts them where the application reads them: a dotenv file
 * (`.env.local` by default), the secrets vault of the environment, or the output alone. Existing keys are
 * kept unless asked: a browser subscribed with the former public key is pushed to no more.
 */
#[AsCommand(
    name: 'collaborate:push:setup',
    description: 'Generate the VAPID keys of web push and write them into .env.local (or the secrets vault), or check the configured ones.',
)]
final class PushSetupCommand extends Command
{
    public const string PUBLIC_KEY = 'WEB_PUSH_PUBLIC_KEY';
    public const string PRIVATE_KEY = 'WEB_PUSH_PRIVATE_KEY';
    public const string SUBJECT = 'WEB_PUSH_SUBJECT';

    public function __construct(
        private readonly string $projectDir,
        private readonly bool $enabled = false,
        private readonly ?string $publicKey = null,
        #[\SensitiveParameter]
        private readonly ?string $privateKey = null,
        private readonly ?string $subject = null,
        private readonly ?AbstractVault $vault = null,
        private readonly ?PushSubscriptions $subscriptions = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'subject',
                null,
                InputOption::VALUE_REQUIRED,
                'mailto: or https: URL a push service may contact',
            )
            ->addOption(
                'env-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Dotenv file to write, relative to the project',
                '.env.local',
            )
            ->addOption(
                'vault',
                null,
                InputOption::VALUE_NONE,
                'Store the keys in the secrets vault of the environment instead',
            )
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Print the keys only: env or json')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Replace existing keys, and remove every subscription they made',
            )
            ->addOption(
                'keep-subscriptions',
                null,
                InputOption::VALUE_NONE,
                'With --force, keep the subscriptions (a copy of another installation)',
            )
            ->addOption(
                'check',
                null,
                InputOption::VALUE_NONE,
                'Check the configured keys and subject, and exit non-zero on a problem',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (true === $input->getOption('check')) {
            return $this->check($io);
        }
        $format = self::option($input, 'format');
        $subject = self::option($input, 'subject');
        if (null !== $format) {
            return $this->print($io, $output, $format, $subject);
        }
        $file = $this->path(self::option($input, 'env-file') ?? '.env.local');
        $vault = true === $input->getOption('vault');
        $existing = $vault ? $this->vaulted() : EnvFile::read($file, self::PUBLIC_KEY);
        $existing ??= '' === $this->publicKey ? null : $this->publicKey;
        $subject ??= ($vault ? null : EnvFile::read($file, self::SUBJECT)) ?? $this->subject;
        if (!Vapid::isSubject($subject)) {
            $io->error('Pass --subject: a mailto: or https: URL the push services may contact (RFC 8292).');

            return Command::INVALID;
        }
        \assert(null !== $subject);
        if (null !== $existing && true !== $input->getOption('force')) {
            $io->error(
                'VAPID keys are configured already. Replacing them stops the pushes to every browser subscribed with '
                . 'them: pass --force to do so.',
            );

            return Command::FAILURE;
        }
        $keys = Vapid::generate();
        $values = [
            self::PUBLIC_KEY => $keys['publicKey'],
            self::PRIVATE_KEY => $keys['privateKey'],
            self::SUBJECT => $subject,
        ];
        if ($vault) {
            if (null === $this->vault) {
                $io->error('The secrets vault is off (framework.secrets).');

                return Command::FAILURE;
            }
            foreach ($values as $name => $value) {
                $this->vault->seal($name, $value);
            }
            $io->success('The VAPID keys and subject are sealed in the secrets vault.');
        } else {
            EnvFile::write($file, $values);
            $io->success(\sprintf('The VAPID keys and subject are written into %s.', $file));
        }
        if (null !== $existing) {
            $this->forget($io, true === $input->getOption('keep-subscriptions'));
        }
        if (!$this->enabled) {
            $io->note('Web push is off: turn it on in config/packages/collaborate.yaml.');
            $io->writeln(self::snippet());
        }

        return Command::SUCCESS;
    }

    /** The configuration that reads the variables this command writes. */
    public static function snippet(): string
    {
        return <<<'YAML'
            collaborate:
                notifications:
                    web_push:
                        enabled: true
                        public_key: '%env(WEB_PUSH_PUBLIC_KEY)%'
                        private_key: '%env(WEB_PUSH_PRIVATE_KEY)%'
                        subject: '%env(WEB_PUSH_SUBJECT)%'
            YAML;
    }

    private function check(SymfonyStyle $io): int
    {
        if (!$this->enabled) {
            $io->warning('Web push is off: there is nothing to check.');

            return Command::SUCCESS;
        }
        $problems = Vapid::problems($this->publicKey, $this->privateKey, $this->subject);
        if ([] !== $problems) {
            $io->error($problems);

            return Command::FAILURE;
        }
        $io->success('The VAPID keys and subject can sign a push.');

        return Command::SUCCESS;
    }

    private function print(SymfonyStyle $io, OutputInterface $output, string $format, ?string $subject): int
    {
        if (!\in_array($format, ['env', 'json'], true)) {
            $io->error('The format is env or json.');

            return Command::INVALID;
        }
        $keys = Vapid::generate();
        $values =
            [self::PUBLIC_KEY => $keys['publicKey'], self::PRIVATE_KEY => $keys['privateKey']]
            + (null === $subject ? [] : [self::SUBJECT => $subject]);
        if ('json' === $format) {
            $output->writeln(json_encode($values, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($values as $name => $value) {
                $output->writeln($name . '=' . $value);
            }
        }

        return Command::SUCCESS;
    }

    private function vaulted(): ?string
    {
        $value = $this->vault?->reveal(self::PUBLIC_KEY);

        return null === $value || '' === $value ? null : $value;
    }

    private function forget(SymfonyStyle $io, bool $keep): void
    {
        if ($keep) {
            $io->warning(
                'The subscriptions are kept: the browsers they name will not accept a push signed by the new keys.',
            );

            return;
        }
        try {
            $removed = $this->subscriptions?->removeAll();
        } catch (\Throwable $error) {
            $io->warning('The subscriptions could not be removed: ' . $error->getMessage());

            return;
        }
        if (null !== $removed) {
            $io->note(\sprintf(
                'Removed %d push subscription(s) the former keys made: their browsers subscribe again.',
                $removed,
            ));
        }
    }

    private static function option(InputInterface $input, string $name): ?string
    {
        // @mago-expect analysis:mixed-assignment an option is mixed: the next line narrows it
        $value = $input->getOption($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function path(string $file): string
    {
        return str_starts_with($file, '/') ? $file : $this->projectDir . '/' . $file;
    }
}
