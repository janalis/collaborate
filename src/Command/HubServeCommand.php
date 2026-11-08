<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Command;

use Janalis\Collaborate\Cursor\CursorEvents;
use Janalis\Collaborate\Hub\Server\Cursors;
use Janalis\Collaborate\Hub\Server\ServerConfig;
use Janalis\Collaborate\Hub\Server\ServerFactory;
use Janalis\Collaborate\Hub\Transport\WebSocket\RequestSigner;
use Janalis\Collaborate\Hub\Transport\WebSocket\SubscriberToken;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the WebSocket hub (`collaborate.hub.transport: websocket`) until SIGTERM or SIGINT. Its signature names
 * no ReactPHP type: the bundle loads without ReactPHP, and this command says what to install.
 */
#[AsCommand(
    name: 'collaborate:hub:serve',
    description: 'Run the WebSocket hub: browsers subscribe on collaborate.hub.websocket.listen, the application publishes on publish_listen.',
)]
class HubServeCommand extends Command
{
    /** What the server runs on: package => a class of it. */
    protected const array REQUIREMENTS = [
        'react/http' => \React\Http\HttpServer::class,
        'react/socket' => \React\Socket\SocketServer::class,
        'react/event-loop' => \React\EventLoop\Loop::class,
        'react/stream' => \React\Stream\ThroughStream::class,
        'ratchet/rfc6455' => \Ratchet\RFC6455\Messaging\MessageBuffer::class,
    ];

    /**
     * Connections `select()` handles (FD_SETSIZE): more need ext-ev or ext-event. Counted on max_connections alone:
     * the server itself refuses sockets past the budget it shares with max_pending and the publish side
     * ({@see ServerFactory::SELECT_SOCKETS}), so the default max_connections stays usable on any loop.
     */
    private const int SELECT_LIMIT = ServerFactory::SELECT_SOCKETS;

    /** Bytes of memory a connection takes, about: 45 KiB, as measured by tests/bench/hub-fanout.php. */
    private const int CONNECTION_MEMORY = 46_080;

    /** @param array<array-key, mixed> $options the `collaborate.hub.websocket` configuration */
    public function __construct(
        private readonly SubscriberToken $tokens,
        private readonly RequestSigner $signer,
        private readonly string $topicPrefix,
        private readonly CursorEvents $cursorEvents,
        /** `cursors.enabled`; `hub.websocket.cursor_events` turns the socket path off on its own. */
        private readonly bool $cursorsEnabled,
        private readonly int $maxEventBytes,
        /** `cursors.throttle`, milliseconds. */
        private readonly int $throttle,
        private readonly array $options,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'listen',
                null,
                InputOption::VALUE_REQUIRED,
                'Browser-facing address (host:port or unix:///path), instead of listen',
            )
            ->addOption(
                'publish-listen',
                null,
                InputOption::VALUE_REQUIRED,
                'Publishing address, instead of publish_listen',
            )
            ->addOption(
                'allow-remote-publish',
                null,
                InputOption::VALUE_NONE,
                'Accept a publishing address other than loopback or a unix socket',
            )
            ->addOption(
                'shutdown-timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Seconds to wait for clients to close on SIGTERM, instead of shutdown_timeout',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Run over 1000 connections on the stream_select() loop anyway',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $missing = array_keys(array_filter(
            static::REQUIREMENTS,
            static fn(string $class): bool => !class_exists($class),
        ));
        if ([] !== $missing) {
            $io->error(\sprintf('The WebSocket hub needs more packages: composer require %s', implode(' ', $missing)));

            return Command::FAILURE;
        }
        $logger = $this->logger ?? new ConsoleLogger($output);
        $shutdownTimeout = self::option($input, 'shutdown-timeout');
        try {
            $config = ServerConfig::fromOptions(
                $this->options,
                $this->topicPrefix,
                self::option($input, 'listen'),
                self::option($input, 'publish-listen'),
                null === $shutdownTimeout ? null : (int) $shutdownTimeout,
                true === $input->getOption('allow-remote-publish'),
            );
        } catch (\InvalidArgumentException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
        $loop = $this->loop();
        $connections = $config->limits->maxConnections;
        if (
            $loop instanceof StreamSelectLoop
            && $connections > self::SELECT_LIMIT
            && true !== $input->getOption('force')
        ) {
            $io->error(\sprintf(
                'max_connections is %d, but stream_select() handles %d at most: install ext-ev or ext-event, lower max_connections, or pass --force.',
                $connections,
                self::SELECT_LIMIT,
            ));

            return Command::FAILURE;
        }
        $memory = self::memoryLimit();
        if (!str_starts_with($memory, '-') && self::bytes($memory) < ($connections * self::CONNECTION_MEMORY)) {
            $logger->warning('memory_limit ({limit}) is short of the {need} bytes max_connections may take: raise it.', [
                'limit' => \ini_get('memory_limit'),
                'need' => $connections * self::CONNECTION_MEMORY,
            ]);
        }
        $logger->info(
            'Hub starting on {loop}: browsers on {listen}, the application on {publish_listen}; {max_connections} connections at most, {shutdown_timeout} s to close them.',
            [
                'loop' => $loop::class,
                'listen' => $config->listen,
                'publish_listen' => $config->publishListen,
                'max_connections' => $connections,
                'shutdown_timeout' => $config->timings->shutdownTimeout,
            ],
        );
        try {
            ServerFactory::create(
                $loop,
                $config,
                $this->tokens,
                $this->signer,
                $logger,
                cursors: $this->cursors(),
            )->start();
        } catch (\RuntimeException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
        $loop->run();
        $logger->info('Hub stopped.');

        return Command::SUCCESS;
    }

    /** Cursor events over sockets, unless cursors or `hub.websocket.cursor_events` are off. */
    protected function cursors(): ?Cursors
    {
        if (!$this->cursorsEnabled || false === ($this->options['cursor_events'] ?? true)) {
            return null;
        }

        return new Cursors($this->cursorEvents, $this->maxEventBytes, $this->throttle);
    }

    /** The loop the server runs on: the global one. */
    protected function loop(): LoopInterface
    {
        return Loop::get();
    }

    /** @infection-ignore-all ini_get() returns a string for an option that exists: the cast only narrows its type */
    private static function memoryLimit(): string
    {
        return (string) \ini_get('memory_limit');
    }

    private static function option(InputInterface $input, string $name): ?string
    {
        // @mago-expect analysis:mixed-assignment an option is mixed: the next line narrows it
        $value = $input->getOption($name);

        return \is_string($value) ? $value : null;
    }

    /** Bytes of an ini size (`128M`). */
    private static function bytes(string $size): int
    {
        $bytes = (int) $size;

        return match (strtolower(substr($size, -1))) {
            'g' => $bytes << 30,
            'm' => $bytes << 20,
            'k' => $bytes << 10,
            default => $bytes,
        };
    }
}
