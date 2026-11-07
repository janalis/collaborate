<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Command;

use Janalis\Collaborate\Command\PushSetupCommand;
use Janalis\Collaborate\Notification\Push\EnvFile;
use Janalis\Collaborate\Notification\Push\PushSubscriptions;
use Janalis\Collaborate\Notification\Push\Vapid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PushSetupCommand::class)]
#[UsesClass(EnvFile::class)]
#[UsesClass(Vapid::class)]
final class PushSetupCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/collaborate-push-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testWritesAPairAndItsSubjectIntoEnvLocal(): void
    {
        $tester = $this->execute(new PushSetupCommand($this->directory), ['--subject' => 'mailto:ops@example.org']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $file = $this->directory . '/.env.local';
        self::assertStringContainsString('written into', $this->display($tester));
        self::assertStringContainsString('.env.local', $this->display($tester));
        $public = EnvFile::read($file, 'WEB_PUSH_PUBLIC_KEY');
        $private = EnvFile::read($file, 'WEB_PUSH_PRIVATE_KEY');
        self::assertSame('mailto:ops@example.org', EnvFile::read($file, 'WEB_PUSH_SUBJECT'));
        self::assertSame([], Vapid::problems($public, $private, 'mailto:ops@example.org'));
        // Off, web push is to be turned on, the configuration printed.
        self::assertStringContainsString("public_key: '%env(WEB_PUSH_PUBLIC_KEY)%'", $this->display($tester));
        self::assertStringContainsString('Web push is off', $this->display($tester));
    }

    public function testRefusesToReplaceAPairUnlessForcedAndThenForgetsTheSubscriptions(): void
    {
        $file = $this->directory . '/config/.env.prod.local';
        EnvFile::write($file, ['WEB_PUSH_PUBLIC_KEY' => 'old', 'WEB_PUSH_SUBJECT' => 'mailto:old@example.org']);
        $subscriptions = $this->createMock(PushSubscriptions::class);
        $subscriptions->expects($this->once())->method('removeAll')->willReturn(3);
        $command = new PushSetupCommand($this->directory, true, subscriptions: $subscriptions);

        $refused = $this->execute($command, ['--env-file' => 'config/.env.prod.local']);
        self::assertSame(Command::FAILURE, $refused->getStatusCode());
        self::assertStringContainsString(
            'VAPID keys are configured already. Replacing them stops the pushes to every browser subscribed with '
            . 'them: pass --force to do so.',
            $this->display($refused),
        );
        self::assertSame('old', EnvFile::read($file, 'WEB_PUSH_PUBLIC_KEY'));

        $forced = $this->execute($command, ['--env-file' => $file, '--force' => true]);
        self::assertSame(Command::SUCCESS, $forced->getStatusCode());
        self::assertNotSame('old', EnvFile::read($file, 'WEB_PUSH_PUBLIC_KEY'));
        // The subject it had is kept.
        self::assertSame('mailto:old@example.org', EnvFile::read($file, 'WEB_PUSH_SUBJECT'));
        self::assertStringContainsString('Removed 3 push subscription(s)', $this->display($forced));
        self::assertStringNotContainsString('Web push is off', $this->display($forced));
    }

    public function testAConfiguredPairCountsAsOneAndASubscriptionsTableMayBeMissing(): void
    {
        $subscriptions = $this->createMock(PushSubscriptions::class);
        $subscriptions
            ->expects($this->once())
            ->method('removeAll')
            ->willThrowException(new \RuntimeException('no table'));
        $command = new PushSetupCommand(
            $this->directory,
            true,
            'configured',
            'secret',
            'mailto:ops@example.org',
            subscriptions: $subscriptions,
        );
        self::assertSame(Command::FAILURE, $this->execute($command)->getStatusCode());
        $forced = $this->execute($command, ['--force' => true]);
        self::assertSame(Command::SUCCESS, $forced->getStatusCode());
        self::assertStringContainsString('could not be removed: no table', $this->display($forced));
        self::assertSame('mailto:ops@example.org', EnvFile::read($this->directory . '/.env.local', 'WEB_PUSH_SUBJECT'));
        // Kept on request, the subscriptions are said to be useless.
        $subscriptions = $this->createMock(PushSubscriptions::class);
        $subscriptions->expects($this->never())->method('removeAll');
        $kept = $this->execute(new PushSetupCommand($this->directory, true, subscriptions: $subscriptions), [
            '--force' => true,
            '--keep-subscriptions' => true,
        ]);
        self::assertStringContainsString('The subscriptions are kept', $this->display($kept));
        // Without the table's service, nothing is said of them.
        $none = $this->execute(new PushSetupCommand($this->directory, true), ['--force' => true]);
        self::assertStringNotContainsString('subscription', $this->display($none));
    }

    public function testAnEmptyConfiguredKeyOrOptionIsNone(): void
    {
        $command = new PushSetupCommand($this->directory, true, '', '', 'mailto:ops@example.org');
        $tester = $this->execute($command, ['--format' => '', '--env-file' => '']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('mailto:ops@example.org', EnvFile::read($this->directory . '/.env.local', 'WEB_PUSH_SUBJECT'));
    }

    public function testRefusesASubjectThatIsNone(): void
    {
        foreach ([[], ['--subject' => 'ops@example.org'], ['--subject' => '']] as $input) {
            $tester = $this->execute(new PushSetupCommand($this->directory), $input);
            self::assertSame(Command::INVALID, $tester->getStatusCode());
            self::assertStringContainsString('Pass --subject', $this->display($tester));
        }
        self::assertFileDoesNotExist($this->directory . '/.env.local');
    }

    public function testSealsThePairInTheVault(): void
    {
        $vault = new SodiumVault($this->directory . '/secrets');
        $vault->generateKeys();
        $command = new PushSetupCommand($this->directory, vault: $vault);
        $tester = $this->execute($command, ['--vault' => true, '--subject' => 'https://example.org']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('sealed in the secrets vault', $this->display($tester));
        self::assertSame('https://example.org', $vault->reveal('WEB_PUSH_SUBJECT'));
        self::assertSame(
            [],
            Vapid::problems(
                $vault->reveal('WEB_PUSH_PUBLIC_KEY'),
                $vault->reveal('WEB_PUSH_PRIVATE_KEY'),
                'https://example.org',
            ),
        );
        self::assertFileDoesNotExist($this->directory . '/.env.local');
        // Sealed, a pair is one: the vault is read, not the dotenv file.
        EnvFile::write($this->directory . '/.env.local', ['WEB_PUSH_SUBJECT' => 'mailto:file@example.org']);
        self::assertSame(
            Command::FAILURE,
            $this->execute($command, ['--vault' => true, '--subject' => 'https://example.org'])->getStatusCode(),
        );
        $again = $this->execute($command, ['--vault' => true, '--force' => true]);
        self::assertSame(Command::INVALID, $again->getStatusCode());

        $off = $this->execute(new PushSetupCommand($this->directory), [
            '--vault' => true,
            '--subject' => 'https://example.org',
        ]);
        self::assertSame(Command::FAILURE, $off->getStatusCode());
        self::assertStringContainsString('The secrets vault is off', $this->display($off));
    }

    public function testPrintsAPairAlone(): void
    {
        $env = $this->execute(new PushSetupCommand($this->directory), [
            '--format' => 'env',
            '--subject' => 'mailto:a@b.c',
        ]);
        self::assertSame(Command::SUCCESS, $env->getStatusCode());
        self::assertMatchesRegularExpression(
            '/^WEB_PUSH_PUBLIC_KEY=[A-Za-z0-9_-]{87}\nWEB_PUSH_PRIVATE_KEY=[A-Za-z0-9_-]{43}\nWEB_PUSH_SUBJECT=mailto:a@b.c\n$/D',
            $env->getDisplay(),
        );
        $json = $this->execute(new PushSetupCommand($this->directory), [
            '--format' => 'json',
            '--subject' => 'https://example.org/contact',
        ]);
        // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next line narrows it
        $keys = json_decode($json->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($keys);
        self::assertSame(['WEB_PUSH_PUBLIC_KEY', 'WEB_PUSH_PRIVATE_KEY', 'WEB_PUSH_SUBJECT'], array_keys($keys));
        self::assertSame('https://example.org/contact', $keys['WEB_PUSH_SUBJECT']);
        self::assertStringNotContainsString('\/', $json->getDisplay());
        self::assertFileDoesNotExist($this->directory . '/.env.local');
        $bad = $this->execute(new PushSetupCommand($this->directory), ['--format' => 'yaml']);
        self::assertSame(Command::INVALID, $bad->getStatusCode());
        self::assertStringContainsString('The format is env or json.', $this->display($bad));
    }

    public function testChecksTheConfiguredPair(): void
    {
        $keys = Vapid::generate();
        $off = $this->execute(new PushSetupCommand($this->directory), ['--check' => true]);
        self::assertSame(Command::SUCCESS, $off->getStatusCode());
        self::assertStringContainsString('nothing to check', $this->display($off));
        $good = $this->execute(
            new PushSetupCommand($this->directory, true, $keys['publicKey'], $keys['privateKey'], 'mailto:a@b.c'),
            ['--check' => true],
        );
        self::assertSame(Command::SUCCESS, $good->getStatusCode());
        self::assertStringContainsString('can sign a push', $this->display($good));
        $bad = $this->execute(
            new PushSetupCommand($this->directory, true, $keys['publicKey'], Vapid::generate()['privateKey'], 'nope'),
            ['--check' => true],
        );
        self::assertSame(Command::FAILURE, $bad->getStatusCode());
        self::assertStringContainsString('The public key is not the one of the private key.', $this->display($bad));
        self::assertStringContainsString('The subject is no mailto: or https: URL.', $this->display($bad));
    }

    public function testIsNamedAndDescribed(): void
    {
        $command = new PushSetupCommand($this->directory);
        self::assertSame('collaborate:push:setup', $command->getName());
        self::assertSame('.env.local', $command->getDefinition()->getOption('env-file')->getDefault());
        self::assertStringContainsString('VAPID', $command->getDescription());
    }

    /** @param array<string, mixed> $input */
    private function execute(PushSetupCommand $command, array $input = []): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute($input, ['decorated' => false]);

        return $tester;
    }

    /** The output, its lines joined back: SymfonyStyle wraps them. */
    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
