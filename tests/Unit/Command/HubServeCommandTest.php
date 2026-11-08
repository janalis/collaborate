<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Command;

use Janalis\Collaborate\Command\HubServeCommand;
use Janalis\Collaborate\Cursor\CursorEvents;
use Janalis\Collaborate\Hub\Server\BrowserEndpoint;
use Janalis\Collaborate\Hub\Server\ClientAddress;
use Janalis\Collaborate\Hub\Server\Counter;
use Janalis\Collaborate\Hub\Server\Cursors;
use Janalis\Collaborate\Hub\Server\Limits;
use Janalis\Collaborate\Hub\Server\PublishEndpoint;
use Janalis\Collaborate\Hub\Server\Router;
use Janalis\Collaborate\Hub\Server\Seconds;
use Janalis\Collaborate\Hub\Server\Server;
use Janalis\Collaborate\Hub\Server\ServerConfig;
use Janalis\Collaborate\Hub\Server\ServerFactory;
use Janalis\Collaborate\Hub\Server\Sockets;
use Janalis\Collaborate\Hub\Server\Timings;
use Janalis\Collaborate\Hub\Transport\WebSocket\Keys;
use Janalis\Collaborate\Hub\Transport\WebSocket\Origin;
use Janalis\Collaborate\Hub\Transport\WebSocket\RequestSigner;
use Janalis\Collaborate\Hub\Transport\WebSocket\SubscriberToken;
use Janalis\Collaborate\Hub\Transport\WebSocket\WebSocketTransport;
use Janalis\Collaborate\Tests\Unit\Hub\Server\RecordingLoop;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(HubServeCommand::class)]
#[UsesClass(BrowserEndpoint::class)]
#[UsesClass(ClientAddress::class)]
#[UsesClass(Counter::class)]
#[UsesClass(Cursors::class)]
#[UsesClass(CursorEvents::class)]
#[UsesClass(Limits::class)]
#[UsesClass(Seconds::class)]
#[UsesClass(PublishEndpoint::class)]
#[UsesClass(Router::class)]
#[UsesClass(Server::class)]
#[UsesClass(ServerConfig::class)]
#[UsesClass(ServerFactory::class)]
#[UsesClass(Sockets::class)]
#[UsesClass(Timings::class)]
#[UsesClass(Keys::class)]
#[UsesClass(Origin::class)]
#[UsesClass(RequestSigner::class)]
#[UsesClass(SubscriberToken::class)]
#[UsesClass(WebSocketTransport::class)]
final class HubServeCommandTest extends TestCase
{
    private string $directory;

    private string $memoryLimit;

    /** @var list<array{string, string, array<array-key, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/hubcmd-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->memoryLimit = (string) \ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        // @mago-expect lint:no-ini-set the limit a test set is put back
        ini_set('memory_limit', $this->memoryLimit);
        $entries = scandir($this->directory);
        foreach (array_diff(false === $entries ? [] : $entries, ['.', '..']) as $entry) {
            unlink($this->directory . '/' . $entry);
        }
        rmdir($this->directory);
    }

    public function testIsNamedAndTakesItsOptions(): void
    {
        $command = new HubServeCommand(
            $this->tokens(),
            $this->signer(),
            'urn:test:',
            new CursorEvents(),
            true,
            4096,
            100,
            $this->options(),
        );
        $definition = $command->getDefinition();

        self::assertSame('collaborate:hub:serve', $command->getName());
        foreach (['listen', 'publish-listen', 'shutdown-timeout'] as $option) {
            self::assertTrue($definition->getOption($option)->isValueRequired(), $option);
        }
        foreach (['allow-remote-publish', 'force'] as $option) {
            self::assertFalse($definition->getOption($option)->acceptValue(), $option);
        }
    }

    public function testSaysWhichPackagesToInstall(): void
    {
        $command = new class(
            $this->tokens(),
            $this->signer(),
            'urn:test:',
            new CursorEvents(),
            true,
            4096,
            100,
            $this->options(),
        ) extends HubServeCommand {
            protected const array REQUIREMENTS = [
                'react/http' => 'Missing\HttpServer',
                'react/stream' => \React\Stream\ThroughStream::class,
                'ratchet/rfc6455' => 'Missing\MessageBuffer',
            ];
        };
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString(
            'composer require react/http ratchet/rfc6455',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay()),
        );
    }

    #[RequiresFunction('posix_kill')]
    public function testRunsUntilSigterm(): void
    {
        $loop = new StreamSelectLoop();
        $loop->addTimer(0.01, static fn() => posix_kill((int) getmypid(), 15));
        $tester = new CommandTester($this->command($loop));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(
            [
                'Hub starting on {loop}: browsers on {listen}, the application on {publish_listen}; {max_connections} connections at most, {shutdown_timeout} s to close them.',
                'Hub listening for browsers on {listen}, for the application on {publish_listen}.',
                'Hub shutting down: closing {count} connection(s).',
                'Hub stopped.',
            ],
            array_column($this->logs, 1),
        );
        self::assertSame(
            [
                'loop' => StreamSelectLoop::class,
                'listen' => 'unix://' . $this->directory . '/browser.sock',
                'publish_listen' => 'unix://' . $this->directory . '/publish.sock',
                'max_connections' => 800,
                'shutdown_timeout' => 10.0,
            ],
            $this->logs[0][2],
        );
        self::assertFileDoesNotExist($this->directory . '/browser.sock');
    }

    public function testTheOptionsOverrideTheConfiguration(): void
    {
        $tester = new CommandTester($this->command($this->stopping()));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--listen' => 'unix://' . $this->directory . '/other.sock',
            '--publish-listen' => '0.0.0.0:0',
            '--allow-remote-publish' => true,
            '--shutdown-timeout' => '3',
        ]));
        self::assertSame('unix://' . $this->directory . '/other.sock', $this->logs[0][2]['listen'] ?? null);
        self::assertSame('0.0.0.0:0', $this->logs[0][2]['publish_listen'] ?? null);
        self::assertSame(3.0, $this->logs[0][2]['shutdown_timeout'] ?? null);
    }

    public function testRefusesARemotePublishAddressWithoutTheFlag(): void
    {
        $tester = new CommandTester($this->command(new StreamSelectLoop()));

        self::assertSame(Command::FAILURE, $tester->execute(['--publish-listen' => '0.0.0.0:0']));
        self::assertStringContainsString('--allow-remote-publish', $tester->getDisplay());
        self::assertSame([], $this->logs);
    }

    public function testSaysWhyItCannotBind(): void
    {
        file_put_contents($this->directory . '/publish.sock', '');
        $tester = new CommandTester($this->command(new StreamSelectLoop()));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('exists and is no socket', $tester->getDisplay());
    }

    public function testRefusesOverAThousandConnectionsOnStreamSelect(): void
    {
        $tester = new CommandTester($this->command(new StreamSelectLoop(), ['max_connections' => 1001]));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString(
            'max_connections is 1001, but stream_select() handles 1000 at most',
            $tester->getDisplay(),
        );
        self::assertSame([], $this->logs);
    }

    public function testRunsAThousandConnectionsOnStreamSelect(): void
    {
        $loop = new StreamSelectLoop();
        $loop->futureTick($loop->stop(...));
        $tester = new CommandTester($this->command($loop, ['max_connections' => 1000]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    public function testRunsOverAThousandConnectionsWhenForced(): void
    {
        $loop = new StreamSelectLoop();
        $loop->futureTick($loop->stop(...));
        $tester = new CommandTester($this->command($loop, ['max_connections' => 1001]));

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
    }

    public function testRunsOverAThousandConnectionsOnAnotherLoop(): void
    {
        $tester = new CommandTester($this->command($this->stopping(), ['max_connections' => 1001]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(RecordingLoop::class, $this->logs[0][2]['loop'] ?? null);
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function memory(): iterable
    {
        yield 'megabytes, enough' => ['512M', 11_650, false];
        yield 'megabytes, short' => ['512M', 11_651, true];
        yield 'lowercase' => ['512m', 11_651, true];
        yield 'kilobytes, enough' => ['524288K', 11_650, false];
        yield 'kilobytes, short' => ['524288k', 11_651, true];
        yield 'gigabytes, enough' => ['1G', 23_301, false];
        yield 'gigabytes, short' => ['1g', 23_302, true];
        yield 'bytes, enough' => ['536870912', 11_650, false];
        yield 'bytes, short' => ['536870912', 11_651, true];
        yield 'exactly enough' => ['536832000', 11_650, false];
        yield 'no limit' => ['-1', 100_000, false];
    }

    #[DataProvider('memory')]
    public function testWarnsOfAShortMemoryLimit(string $limit, int $connections, bool $warns): void
    {
        // @mago-expect lint:no-ini-set the command reads memory_limit itself
        ini_set('memory_limit', $limit);
        $tester = new CommandTester($this->command($this->stopping(), ['max_connections' => $connections]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $warnings = array_values(array_filter($this->logs, static fn(array $log): bool => 'warning' === $log[0]));
        if ($warns) {
            self::assertSame([
                'memory_limit ({limit}) is short of the {need} bytes max_connections may take: raise it.',
                ['limit' => $limit, 'need' => $connections * 46_080],
            ], [$warnings[0][1] ?? null, $warnings[0][2] ?? null]);
        } else {
            self::assertSame(
                [],
                array_filter($warnings, static fn(array $log): bool => str_starts_with($log[1], 'memory_limit')),
            );
        }
    }

    public function testLogsOnTheConsoleWithoutALogger(): void
    {
        $command = new class(
            $this->tokens(),
            $this->signer(),
            'urn:test:',
            $this->options(),
            null,
            $this->stopping(),
        ) extends HubServeCommand {
            public function __construct(
                SubscriberToken $tokens,
                RequestSigner $signer,
                string $topicPrefix,
                array $options,
                ?LoggerInterface $logger,
                private readonly LoopInterface $testLoop,
            ) {
                parent::__construct(
                    $tokens,
                    $signer,
                    $topicPrefix,
                    new CursorEvents(),
                    true,
                    4096,
                    100,
                    $options,
                    $logger,
                );
            }

            protected function loop(): LoopInterface
            {
                return $this->testLoop;
            }
        };
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([], [
            'verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE,
        ]));
        self::assertStringContainsString('Hub stopped.', $tester->getDisplay());
        self::assertStringContainsString('handles no signals', $tester->getDisplay());
    }

    /** @return iterable<string, array{bool, array<string, mixed>, bool}> */
    public static function cursorSwitches(): iterable
    {
        yield 'cursors on' => [true, [], true];
        yield 'cursor events on' => [true, ['cursor_events' => true], true];
        yield 'cursor events off' => [true, ['cursor_events' => false], false];
        yield 'cursors off' => [false, [], false];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('cursorSwitches')]
    public function testRelaysCursorEventsUnlessOff(bool $enabled, array $options, bool $relays): void
    {
        $events = new CursorEvents();
        $command = new class(
            $this->tokens(),
            $this->signer(),
            'urn:test:',
            $events,
            $enabled,
            1024,
            50,
            $this->options($options),
        ) extends HubServeCommand {
            public function exposedCursors(): ?Cursors
            {
                return $this->cursors();
            }
        };

        self::assertEquals($relays ? new Cursors($events, 1024, 50) : null, $command->exposedCursors());
    }

    public function testRunsOnTheGlobalLoopByDefault(): void
    {
        $command = new class(
            $this->tokens(),
            $this->signer(),
            'urn:test:',
            new CursorEvents(),
            true,
            4096,
            100,
            $this->options(),
        ) extends HubServeCommand {
            public function globalLoop(): LoopInterface
            {
                return $this->loop();
            }
        };

        self::assertSame(\React\EventLoop\Loop::get(), $command->globalLoop());
    }

    /** A loop without signals that stops once the server started. */
    private function stopping(): RecordingLoop
    {
        $loop = new RecordingLoop(false);
        $loop->futureTick($loop->stop(...));

        return $loop;
    }

    /** @param array<string, mixed> $options */
    private function command(LoopInterface $loop, array $options = []): HubServeCommand
    {
        $logger = new class($this->logs) extends AbstractLogger {
            /** @param list<array{string, string, array<array-key, mixed>}> $logs */
            public function __construct(
                private array &$logs,
            ) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [(string) $level, (string) $message, $context];
            }
        };

        return new class(
            $this->tokens(),
            $this->signer(),
            'urn:test:',
            $this->options($options),
            $logger,
            $loop,
        ) extends HubServeCommand {
            public function __construct(
                SubscriberToken $tokens,
                RequestSigner $signer,
                string $topicPrefix,
                array $options,
                ?LoggerInterface $logger,
                private readonly LoopInterface $testLoop,
            ) {
                parent::__construct(
                    $tokens,
                    $signer,
                    $topicPrefix,
                    new CursorEvents(),
                    true,
                    4096,
                    100,
                    $options,
                    $logger,
                );
            }

            protected function loop(): LoopInterface
            {
                return $this->testLoop;
            }
        };
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function options(array $options = []): array
    {
        return $options
        + [
            'public_url' => '/hub',
            'path' => null,
            'listen' => 'unix://' . $this->directory . '/browser.sock',
            'publish_listen' => 'unix://' . $this->directory . '/publish.sock',
            'socket_mode' => 0o660,
            'secret' => null,
            'previous_secret' => null,
            'allowed_origins' => [],
            'trusted_proxies' => [],
            'ping_interval' => 25,
            'max_connections' => 800,
            'max_connections_per_ip' => 0,
            'max_connections_per_user' => 50,
            'max_pending' => 100,
            'max_message_size' => 16_384,
            'max_buffer' => 1_048_576,
            'shutdown_timeout' => 10,
        ];
    }

    private function tokens(): SubscriberToken
    {
        return new SubscriberToken(new Keys(str_repeat('s', 32)), '/hub', 'urn:test:', 300, 8);
    }

    private function signer(): RequestSigner
    {
        return new RequestSigner(new Keys(str_repeat('s', 32)));
    }
}
