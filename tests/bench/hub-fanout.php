<?php

declare(strict_types=1);

/*
 * Fan-out bench of collaborate:hub:serve (not run in CI). The hub runs in its own process; browsers run in worker
 * processes (pawl); the publisher, in this process, publishes open-loop through the real signed endpoint, each
 * notice carrying its hrtime. Workers measure the latency of every notice after the warm-up.
 *
 *   php tests/bench/hub-fanout.php --connections=2000 --rooms=200 --rate=500 --duration=120 --warmup=30
 *   php tests/bench/hub-fanout.php --connections=10000 --rooms=1000 --rate=2000 --workers=16 --force
 *   php tests/bench/hub-fanout.php --connections=2000 --rooms=1 --rate=50
 *   php tests/bench/hub-fanout.php --connections=2000 --rooms=200 --rate=500 --stalled=5
 *   php tests/bench/hub-fanout.php --scenario=cursor --connections=200 --room-size=5 --moves=20
 *
 * The cursor scenario has pages send cursor moves over their sockets (`{"hub":"cursor"}`), `--moves` a second
 * each, in rooms of `--room-size`: it reports the latency from a move sent to its receipt by each peer.
 *
 * On Linux, raise the descriptor limit first (`ulimit -n 65536`) and pin the hub apart from the workers, e.g.
 * `taskset -c 0 php …` for the hub through --hub-cpu=0 and the rest on other cores. Over 1000 connections the
 * hub needs ext-ev or ext-event, or --force on stream_select(). Reports p50/p95/p99/p99.9/max latency, notices
 * lost by the clients that read, the clients dropped, the hub's peak RSS and CPU time and its loop class.
 */

use Janalis\Collaborate\Cursor\CursorIdentity;
use Janalis\Collaborate\Hub\Notice;
use Janalis\Collaborate\Hub\Transport\WebSocket\Keys;
use Janalis\Collaborate\Hub\Transport\WebSocket\RequestSigner;
use Janalis\Collaborate\Hub\Transport\WebSocket\SubscriberToken;
use Janalis\Collaborate\Hub\Transport\WebSocket\WebSocketTransport;
use Ratchet\Client\Connector as WebSocketConnector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectorInterface;
use React\Socket\UnixConnector;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const SECRET = 'a-bench-secret-of-thirty-two-bytes-or-more';

const PREFIX = 'urn:bench:';

const ORIGIN = 'https://bench.example';

const TTL = 86_400;

/** Latency buckets: 1% wide, in microseconds. */
const BUCKET = 1.01;

/**
 * @param list<string> $argv
 * @param array<string, string|int|bool> $defaults
 *
 * @return array<string, string|int|bool>
 */
function options(array $argv, array $defaults): array
{
    $options = $defaults;
    foreach (array_slice($argv, 1) as $argument) {
        $match = [];
        if (1 !== preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $match)) {
            fwrite(STDERR, "Unknown argument {$argument}\n");
            exit(2);
        }
        $value = $match[2] ?? true;
        $options[$match[1]] = is_string($value) && is_numeric($value) ? (int) $value : $value;
    }

    return $options;
}

function socket_connector(string $path): ConnectorInterface
{
    return new readonly class($path, new UnixConnector(Loop::get())) implements ConnectorInterface {
        public function __construct(
            private string $path,
            private UnixConnector $connector,
        ) {}

        public function connect($uri): PromiseInterface
        {
            return $this->connector->connect('unix://' . $this->path);
        }
    };
}

/**
 * A worker: connections [first, first + count), each subscribed to room `index % rooms`. Prints its histogram as
 * JSON once the run is over.
 *
 * @param array<string, string|int|bool> $options
 */
function worker(array $options): never
{
    $first = (int) $options['first'];
    $count = (int) $options['count'];
    $rooms = (int) $options['rooms'];
    $stalled = (int) $options['stalled'];
    $socket = (string) $options['socket'];
    $tokens = new SubscriberToken(new Keys(SECRET), '/hub', PREFIX, TTL, 8);
    $connector = new WebSocketConnector(Loop::get(), socket_connector($socket));
    $histogram = [];
    $received = 0;
    $dropped = 0;
    $connected = 0;
    $failed = 0;
    /** @var array<int, int> $readers connected clients that read, per room */
    $readers = [];
    // A connection refused (a backlog overrun by every worker's burst) is tried again, later each time.
    $connect = static function (int $index, int $attempt = 0) use (
        $connector,
        $rooms,
        $stalled,
        $tokens,
        &$connect,
        &$histogram,
        &$received,
        &$dropped,
        &$connected,
        &$failed,
        &$readers,
    ): void {
        $stall = ($index % 100) < $stalled;
        $connector('ws://localhost/hub', [], ['Origin' => ORIGIN])->then(
            static function (WebSocket $socket) use (
                $index,
                $rooms,
                $stall,
                $tokens,
                &$histogram,
                &$received,
                &$dropped,
                &$connected,
                &$readers,
            ): void {
                ++$connected;
                if (!$stall) {
                    $readers[$index % $rooms] = ($readers[$index % $rooms] ?? 0) + 1;
                }
                $socket->on('message', static function (MessageInterface $message) use (
                    $socket,
                    $stall,
                    &$histogram,
                    &$received,
                ): void {
                    $now = (int) hrtime(true);
                    // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next line narrows it
                    $notice = json_decode($message->getPayload(), true);
                    if (!is_array($notice) || !isset($notice['t']) || !is_int($notice['t'])) {
                        if ($stall) {
                            // Subscribed: stop reading, as a frozen tab would.
                            $socket->pause();
                        }

                        return;
                    }
                    if (true !== ($notice['m'] ?? false)) {
                        return;
                    }
                    ++$received;
                    $micros = max(1, intdiv($now - $notice['t'], 1000));
                    $bucket = (int) round(log($micros) / log(BUCKET));
                    $histogram[$bucket] = ($histogram[$bucket] ?? 0) + 1;
                });
                $socket->on('close', static function (?int $code = null) use (&$dropped): void {
                    if (1001 !== $code) {
                        ++$dropped;
                    }
                });
                $socket->send(json_encode([
                    'hub' => 'subscribe',
                    'id' => 1,
                    'token' => $tokens->sign($index + 1, ORIGIN, [PREFIX . 'room:' . ($index % $rooms)], time()),
                ], JSON_THROW_ON_ERROR));
            },
            static function (Throwable $error) use ($index, $attempt, &$connect, &$failed): void {
                if ($attempt < 20) {
                    Loop::addTimer(0.05 * ($attempt + 1), static fn() => $connect($index, $attempt + 1));

                    return;
                }
                if (0 === $failed++) {
                    fwrite(STDERR, 'A connection failed: ' . $error->getMessage() . "\n");
                }
            },
        );
    };
    // Paced: a burst over the listen backlog (somaxconn, 128 on macOS) is refused.
    $next = $first;
    /** @var TimerInterface|null $pacer */
    $pacer = null;
    $pacer = Loop::addPeriodicTimer(0.01, static function () use (&$next, &$pacer, $first, $count, $connect): void {
        for ($i = 0; $i < 10 && $next < ($first + $count); ++$i) {
            $connect($next++);
        }
        if ($next >= ($first + $count) && null !== $pacer) {
            Loop::cancelTimer($pacer);
        }
    });
    Loop::addTimer((int) $options['until'] - time(), static function () use (
        &$histogram,
        &$received,
        &$dropped,
        &$connected,
        &$failed,
        &$readers,
    ): void {
        echo json_encode([
            'histogram' => $histogram,
            'received' => $received,
            'dropped' => $dropped,
            'connected' => $connected,
            'failed' => $failed,
            'readers' => $readers,
        ], JSON_THROW_ON_ERROR);
        Loop::stop();
    });
    Loop::run();
    exit(0);
}

/**
 * A cursor worker: connections [first, first + count) in rooms of `room-size`, each sending `moves` cursor moves
 * a second. Measures, from `from`, the time from a move sent to its receipt by each peer.
 *
 * @param array<string, string|int|bool> $options
 */
function cursor_worker(array $options): never
{
    $first = (int) $options['first'];
    $count = (int) $options['count'];
    $size = (int) $options['room-size'];
    $interval = 1 / max(1, (int) $options['moves']);
    $from = (int) $options['from'];
    $tokens = new SubscriberToken(new Keys(SECRET), '/hub', PREFIX, TTL, 8);
    $connector = new WebSocketConnector(Loop::get(), socket_connector((string) $options['socket']));
    /** @var array<string, int> $sentAt when each client sent its last move */
    $sentAt = [];
    $histogram = [];
    $received = 0;
    $sent = 0;
    $connected = 0;
    $failed = 0;
    for ($index = $first; $index < ($first + $count); ++$index) {
        $room = PREFIX . 'cursors:' . intdiv($index, $size);
        $clientId = sprintf('bench-client-%08d', $index);
        $token = $tokens->sign(
            $index + 1,
            ORIGIN,
            [$room],
            time(),
            $room,
            new CursorIdentity($index + 1, 'Bench ' . $index, '#336699'),
        );
        $connector('ws://localhost/hub', [], ['Origin' => ORIGIN])->then(
            static function (WebSocket $socket) use (
                $token,
                $clientId,
                $interval,
                $from,
                &$sentAt,
                &$histogram,
                &$received,
                &$sent,
                &$connected,
            ): void {
                ++$connected;
                $socket->on('message', static function (MessageInterface $message) use (
                    $clientId,
                    $from,
                    &$sentAt,
                    &$histogram,
                    &$received,
                ): void {
                    $now = (int) hrtime(true);
                    // @mago-expect analysis:mixed-assignment decoded JSON is mixed: the next line narrows it
                    $notice = json_decode($message->getPayload(), true);
                    if (
                        !is_array($notice)
                        || !is_string($notice['clientId'] ?? null)
                        || $clientId === $notice['clientId']
                        || time() < $from
                    ) {
                        return;
                    }
                    $at = $sentAt[$notice['clientId']] ?? null;
                    if (null === $at) {
                        return;
                    }
                    ++$received;
                    $bucket = (int) round(log(max(1, intdiv($now - $at, 1000))) / log(BUCKET));
                    $histogram[$bucket] = ($histogram[$bucket] ?? 0) + 1;
                });
                $socket->send(json_encode(['hub' => 'subscribe', 'id' => 1, 'token' => $token], JSON_THROW_ON_ERROR));
                $sequence = 0;
                // Spread the clients' moves over the interval, as pages are.
                Loop::addTimer((mt_rand(0, 1000) / 1000) * $interval, static function () use (
                    $socket,
                    $clientId,
                    $interval,
                    $from,
                    &$sequence,
                    &$sentAt,
                    &$sent,
                ): void {
                    Loop::addPeriodicTimer($interval, static function () use (
                        $socket,
                        $clientId,
                        $from,
                        &$sequence,
                        &$sentAt,
                        &$sent,
                    ): void {
                        $sentAt[$clientId] = (int) hrtime(true);
                        if (time() >= $from) {
                            ++$sent;
                        }
                        $socket->send(json_encode([
                            'hub' => 'cursor',
                            'event' => [
                                'clientId' => $clientId,
                                'sequence' => ++$sequence,
                                'kind' => 'move',
                                'style' => 'mac',
                                'field' => 'field-' . ($sequence % 10),
                            ],
                        ], JSON_THROW_ON_ERROR));
                    });
                });
            },
            static function (Throwable $error) use (&$failed): void {
                if (0 === $failed++) {
                    fwrite(STDERR, 'A connection failed: ' . $error->getMessage() . "\n");
                }
            },
        );
    }
    Loop::addTimer((int) $options['until'] - time(), static function () use (
        &$histogram,
        &$received,
        &$sent,
        &$connected,
        &$failed,
    ): void {
        echo json_encode([
            'histogram' => $histogram,
            'received' => $received,
            'sent' => $sent,
            'connected' => $connected,
            'failed' => $failed,
        ], JSON_THROW_ON_ERROR);
        Loop::stop();
    });
    Loop::run();
    exit(0);
}

/**
 * Runs the cursor scenario against a started hub, prints its report and stops the hub.
 *
 * @param array<string, string|int|bool> $options
 */
function cursor_bench(array $options, string $browser, int $from, int $until, Process $hub, int $hubPid): void
{
    $connections = (int) $options['connections'];
    $size = max(1, (int) $options['room-size']);
    $workers = (int) $options['workers'];
    // Whole rooms per worker: a move's peers live in the process that timed it.
    $share = (int) ceil(($connections / $workers) / $size) * $size;
    $clients = [];
    for ($first = 0; $first < $connections; $first += $share) {
        $client = new Process([
            PHP_BINARY,
            '-d',
            'memory_limit=-1',
            __FILE__,
            '--cursor-worker',
            '--first=' . $first,
            '--count=' . min($share, $connections - $first),
            '--room-size=' . $size,
            '--moves=' . (int) $options['moves'],
            '--socket=' . $browser,
            '--from=' . $from,
            '--until=' . ($until + 2),
        ]);
        $client->setTimeout(null);
        $client->start();
        $clients[] = $client;
    }
    fwrite(STDERR, sprintf("Warming up %d s, then measuring %d s…\n", $options['warmup'], $options['duration']));
    while (time() < $until) {
        usleep(100_000);
    }
    $usage = usage($hubPid);
    $histogram = [];
    $received = 0;
    $sent = 0;
    $connected = 0;
    $failed = 0;
    foreach ($clients as $client) {
        $client->wait();
        fwrite(STDERR, $client->getErrorOutput());
        try {
            /** @var array{histogram: array<int, int>, received: int, sent: int, connected: int, failed: int} $report */
            $report = json_decode($client->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            fwrite(STDERR, "A worker printed no report.\n");
            continue;
        }
        foreach ($report['histogram'] as $bucket => $count) {
            $histogram[$bucket] = ($histogram[$bucket] ?? 0) + $count;
        }
        $received += $report['received'];
        $sent += $report['sent'];
        $connected += $report['connected'];
        $failed += $report['failed'];
    }
    $hub->signal(15);
    $hub->wait();
    $loop = [];
    preg_match('/^loop: (.+)$/m', $hub->getErrorOutput(), $loop);
    $total = array_sum($histogram);
    printf(
        "loop %s | cursor | %d connections (%d failed), rooms of %d, %d moves/s each, %d s measured\n",
        $loop[1] ?? '?',
        $connected,
        $failed,
        $size,
        $options['moves'],
        $options['duration'],
    );
    printf(
        "send to peer p50 %s | p95 %s | p99 %s | p99.9 %s | max %s\n",
        percentile($histogram, $total, 0.5),
        percentile($histogram, $total, 0.95),
        percentile($histogram, $total, 0.99),
        percentile($histogram, $total, 0.999),
        percentile($histogram, $total, 1.0),
    );
    printf(
        "%d moves sent, %d receipts of %d expected | hub RSS %s, CPU %s\n",
        $sent,
        $received,
        $sent * ($size - 1),
        $usage['rss'],
        $usage['cpu'],
    );
}

/** @param array<string, string|int|bool> $options */
function hub_script(array $options, string $directory): string
{
    $script = $directory . '/hub.php';
    $connections = (int) $options['connections'];
    file_put_contents($script, sprintf(<<<'PHP'
        <?php
        require %s;

        use Janalis\Collaborate\Command\HubServeCommand;
        use Janalis\Collaborate\Hub\Transport\WebSocket\Keys;
        use Janalis\Collaborate\Hub\Transport\WebSocket\RequestSigner;
        use Janalis\Collaborate\Hub\Transport\WebSocket\SubscriberToken;
        use React\EventLoop\Loop;
        use Symfony\Component\Console\Input\ArrayInput;
        use Symfony\Component\Console\Output\ConsoleOutput;

        fwrite(STDERR, 'loop: ' . Loop::get()::class . "\n");
        $keys = new Keys(%s);
        $command = new HubServeCommand(
            new SubscriberToken($keys, '/hub', %s, %d, 8),
            new RequestSigner($keys),
            %s,
            new Janalis\Collaborate\Cursor\CursorEvents(),
            true,
            4096,
            50,
            ['public_url' => '/hub', 'path' => null, 'listen' => %s, 'publish_listen' => %s, 'socket_mode' => 0600,
             'allowed_origins' => [], 'trusted_proxies' => [], 'ping_interval' => 25, 'max_connections' => %d,
             'max_connections_per_ip' => 0, 'max_connections_per_user' => 50, 'max_pending' => %d,
             'max_message_size' => 16384, 'max_buffer' => 1048576, 'shutdown_timeout' => 5],
        );
        exit($command->run(new ArrayInput(%s), new ConsoleOutput()));
        PHP, var_export(
        dirname(__DIR__, 2) . '/vendor/autoload.php',
        true,
    ), var_export(SECRET, true), var_export(PREFIX, true), TTL, var_export(PREFIX, true), var_export('unix://' . $directory . '/browser.sock', true), var_export('unix://' . $directory . '/publish.sock', true), $connections + 100, $connections + 100, var_export(true === $options['force'] ? ['--force' => true] : [], true)));

    return $script;
}

/** @return array{rss: string, cpu: string} the hub's peak resident memory and CPU time, as the system tells them */
function usage(int $pid): array
{
    $status = is_readable('/proc/' . $pid . '/status') ? file_get_contents('/proc/' . $pid . '/status') : false;
    $match = [];
    if (is_string($status) && 1 === preg_match('/^VmHWM:\s+(\d+) kB/m', $status, $match)) {
        $stat = explode(' ', (string) file_get_contents('/proc/' . $pid . '/stat'));
        $ticks = (int) ($stat[13] ?? 0) + (int) ($stat[14] ?? 0);

        return ['rss' => round((int) $match[1] / 1024, 1) . ' MiB (VmHWM)', 'cpu' => round($ticks / 100, 1) . ' s'];
    }
    // macOS and others: the current RSS, not the peak.
    $ps = trim((string) shell_exec('ps -o rss=,time= -p ' . $pid));
    $fields = preg_split('/\s+/', $ps);
    [$rss, $cpu] = array_pad(false === $fields ? [] : $fields, 2, '?');

    return ['rss' => round((int) $rss / 1024, 1) . ' MiB (current RSS)', 'cpu' => $cpu];
}

/** @param array<int, int> $histogram */
function percentile(array $histogram, int $total, float $rank): string
{
    if (0 === $total) {
        return '-';
    }
    ksort($histogram);
    $target = (int) ceil($total * $rank);
    $seen = 0;
    foreach ($histogram as $bucket => $count) {
        $seen += $count;
        if ($seen >= $target) {
            return number_format((BUCKET ** $bucket) / 1000, 2) . ' ms';
        }
    }

    return '-';
}

$options = options($argv, [
    'connections' => 2000,
    'rooms' => 200,
    'rate' => 500,
    'duration' => 60,
    'warmup' => 30,
    'workers' => 8,
    'stalled' => 0,
    'force' => false,
    'hub-cpu' => '',
    'worker' => false,
    'first' => 0,
    'count' => 0,
    'socket' => '',
    'until' => 0,
    'scenario' => 'fanout',
    'room-size' => 5,
    'moves' => 20,
    'cursor-worker' => false,
    'from' => 0,
]);
if (true === $options['worker']) {
    worker($options);
}
if (true === $options['cursor-worker']) {
    cursor_worker($options);
}

$connections = (int) $options['connections'];
$rooms = (int) $options['rooms'];
$rate = (int) $options['rate'];
$workers = (int) $options['workers'];
$directory = sys_get_temp_dir() . '/hubb-' . bin2hex(random_bytes(4));
mkdir($directory);
$browser = $directory . '/browser.sock';
$publish = $directory . '/publish.sock';

$command = [PHP_BINARY, '-d', 'memory_limit=-1', hub_script($options, $directory)];
if ('' !== $options['hub-cpu']) {
    array_unshift($command, 'taskset', '-c', (string) $options['hub-cpu']);
}
$hub = new Process($command);
$hub->setTimeout(null);
$hub->start();
$deadline = microtime(true) + 10;
while (!(file_exists($browser) && file_exists($publish)) && $hub->isRunning() && microtime(true) < $deadline) {
    usleep(10_000);
}
if (!$hub->isRunning() || !file_exists($publish)) {
    fwrite(STDERR, "The hub did not start:\n" . $hub->getErrorOutput() . $hub->getOutput());
    exit(1);
}
$hubPid = (int) $hub->getPid();

$start = time() + 5;
$measureFrom = $start + (int) $options['warmup'];
$until = $measureFrom + (int) $options['duration'];
if ('cursor' === $options['scenario']) {
    cursor_bench($options, $browser, $measureFrom, $until, $hub, $hubPid);
    $files = glob($directory . '/*');
    array_map(unlink(...), false === $files ? [] : $files);
    rmdir($directory);
    exit(0);
}
$clients = [];
$share = (int) ceil($connections / $workers);
for ($w = 0; $w < $workers; ++$w) {
    $first = $w * $share;
    $count = min($share, $connections - $first);
    if ($count <= 0) {
        break;
    }
    $client = new Process([
        PHP_BINARY,
        '-d',
        'memory_limit=-1',
        __FILE__,
        '--worker',
        '--first=' . $first,
        '--count=' . $count,
        '--rooms=' . $rooms,
        '--stalled=' . (int) $options['stalled'],
        '--socket=' . $browser,
        '--until=' . ($until + 3),
    ]);
    $client->setTimeout(null);
    $client->start();
    $clients[] = $client;
}

$transport = new WebSocketTransport(
    new CurlHttpClient(),
    new RequestSigner(new Keys(SECRET)),
    new SubscriberToken(new Keys(SECRET), '/hub', PREFIX, TTL, 8),
    '/hub',
    'http://localhost',
    $publish,
);
while (time() < $start) {
    usleep(10_000);
}
$published = array_fill(0, max(1, $rooms), 0);
$sent = 0;
$failures = 0;
$began = microtime(true);
fwrite(STDERR, sprintf("Warming up %d s, then measuring %d s…\n", $options['warmup'], $options['duration']));
while (time() < $until) {
    // Open loop: what is due by now, whatever the hub's pace.
    $due = (int) ((microtime(true) - $began) * $rate) - $sent;
    $measured = time() >= $measureFrom;
    $notices = [];
    for ($i = 0; $i < $due; ++$i) {
        $room = ($sent + $i) % $rooms;
        if ($measured) {
            ++$published[$room];
        }
        $notices[] = new Notice(PREFIX . 'room:' . $room, json_encode([
            't' => hrtime(true),
            'm' => $measured,
            'room' => $room,
        ], JSON_THROW_ON_ERROR));
    }
    if ([] !== $notices) {
        try {
            $transport->publish($notices);
        } catch (Throwable $error) {
            if (0 === $failures++) {
                fwrite(STDERR, 'A publish failed: ' . ($error->getPrevious() ?? $error)->getMessage() . "\n");
            }
        }
        $sent += count($notices);
    }
    usleep(2000);
}
$usage = usage($hubPid);

$histogram = [];
/** @var array<int, int> $readers */
$readers = [];
$received = 0;
$dropped = 0;
$connected = 0;
$failed = 0;
foreach ($clients as $client) {
    $client->wait();
    fwrite(STDERR, $client->getErrorOutput());
    try {
        /** @var array{histogram: array<int, int>, received: int, dropped: int, connected: int, failed: int, readers: array<int, int>} $report */
        $report = json_decode($client->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fwrite(STDERR, "A worker printed no report.\n");
        continue;
    }
    foreach ($report['histogram'] as $bucket => $count) {
        $histogram[$bucket] = ($histogram[$bucket] ?? 0) + $count;
    }
    $received += (int) $report['received'];
    $dropped += (int) $report['dropped'];
    $connected += (int) $report['connected'];
    $failed += (int) $report['failed'];
    foreach ($report['readers'] as $room => $count) {
        $readers[$room] = ($readers[$room] ?? 0) + $count;
    }
}
$hub->signal(15);
$hub->wait();
$loop = [];
preg_match('/^loop: (.+)$/m', $hub->getErrorOutput(), $loop);
$files = glob($directory . '/*');
array_map(unlink(...), false === $files ? [] : $files);
rmdir($directory);

// What the connected clients that read should have received: per room, its notices times its readers.
$expected = 0;
foreach ($readers as $room => $count) {
    $expected += $published[$room] * $count;
}
$total = array_sum($histogram);
printf(
    "loop %s | %d connections (%d failed), %d rooms, %d notices/s, %d s measured, %d%% stalled\n",
    $loop[1] ?? '?',
    $connected,
    $failed,
    $rooms,
    $rate,
    $options['duration'],
    $options['stalled'],
);
printf(
    "latency p50 %s | p95 %s | p99 %s | p99.9 %s | max %s\n",
    percentile($histogram, $total, 0.5),
    percentile($histogram, $total, 0.95),
    percentile($histogram, $total, 0.99),
    percentile($histogram, $total, 0.999),
    percentile($histogram, $total, 1.0),
);
printf(
    "delivered %d of %d (%d lost by readers), %d clients dropped, %d publish failures | hub RSS %s, CPU %s\n",
    $received,
    $expected,
    max(0, $expected - $received),
    $dropped,
    $failures,
    $usage['rss'],
    $usage['cpu'],
);
