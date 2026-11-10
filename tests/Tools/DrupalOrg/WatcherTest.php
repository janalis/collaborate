<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Tools\DrupalOrg;

use Janalis\Collaborate\Tools\DrupalOrg\DrupalOrg;
use Janalis\Collaborate\Tools\DrupalOrg\GitHub;
use Janalis\Collaborate\Tools\DrupalOrg\Http;
use Janalis\Collaborate\Tools\DrupalOrg\ProcessRunner;
use Janalis\Collaborate\Tools\DrupalOrg\Runner;
use Janalis\Collaborate\Tools\DrupalOrg\Watcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

foreach (['Http', 'Runner', 'ProcessRunner', 'DrupalOrg', 'GitHub', 'Watcher'] as $class) {
    require_once __DIR__ . '/../../../tools/DrupalOrg/' . $class . '.php';
}

/** The watcher between the module's drupal.org project and GitHub, on a fake drupal.org and fake `git` and `gh`. */
#[CoversClass(Watcher::class)]
#[CoversClass(DrupalOrg::class)]
#[CoversClass(GitHub::class)]
#[CoversClass(ProcessRunner::class)]
final class WatcherTest extends TestCase
{
    private const string PROJECT = 'https://git.drupalcode.org/api/v4/projects/project%2Fcollaborate';

    private const string MRS = self::PROJECT . '/merge_requests?state=opened&per_page=100&target_branch=0.x';

    private const string NID = 'https://www.drupal.org/api-d7/node.json?field_project_machine_name=collaborate';

    private const string ISSUES = 'https://www.drupal.org/api-d7/node.json?type=project_issue&field_project=99&sort=nid&direction=DESC&limit=50';

    /** @var array<string, array{int, mixed}> "METHOD url" => the response */
    private array $responses = [];

    /** @var list<array{string, string, array<string, string>, array<string, string>}> */
    private array $requests = [];

    /** @var list<array{list<string>, \Closure(list<string>): array{int, string, string}}> */
    private array $commands = [];

    /** @var list<array{list<string>, ?string}> */
    private array $ran = [];

    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/drupalorg-' . bin2hex(random_bytes(4));
        mkdir($this->temporary);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->temporary));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function usages(): iterable
    {
        yield 'no command' => [[]];
        yield 'an unknown command' => [['sync']];
        yield 'a missing argument' => [['closed']];
        yield 'an extra argument' => [['mrs', 'now']];
        yield 'an unknown option' => [['mrs', '--force']];
        yield 'an option without its value' => [['mrs', '--repo']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('usages')]
    public function testPrintsItsUsage(array $arguments): void
    {
        [$status, , $error] = $this->watch($arguments);

        self::assertSame(2, $status);
        self::assertStringStartsWith('usage: drupalorg.php', $error);
    }

    public function testNeedsTheRepository(): void
    {
        [$status] = $this->watch(['mrs'], repository: '');

        self::assertSame(2, $status);
    }

    public function testDoesNothingBeforeTheProjectExists(): void
    {
        self::assertSame([0, "No drupal.org project: nothing to mirror.\n", ''], $this->watch(['mrs']));
        self::assertSame([0, "No drupal.org project: nothing to mirror.\n", ''], $this->watch(['issues']));
        $this->ghJson(['issue', 'list', '--state', 'open'], [[
            'number' => 4,
            'body' => '<!-- drupalorg-release: 0.6.0 -->',
        ]]);
        self::assertSame([0, '', ''], $this->watch(['releases']));
    }

    public function testMirrorsANewMergeRequestAsAPullRequestAndTellsItWhereItIsReviewed(): void
    {
        $this->mergeRequest();
        $this->respond('GET', self::PROJECT . '/merge_requests/7/notes?per_page=100', [['body' => 'Nice.']]);
        $this->respond('POST', self::PROJECT . '/merge_requests/7/notes', ['id' => 1], 201);
        $this->ghJson(['pr', 'list'], []);
        $this->command(['git', 'merge-base'], "base\n");
        $this->command(['git', 'format-patch'], 'PATCH');
        $this->command(['gh', 'pr', 'create'], "https://github.com/janalis/collaborate/pull/40\n");

        [$status, $output] = $this->watch(['mrs'], token: 'secret');

        self::assertSame(0, $status, $output);
        self::assertSame(
            "Pushed drupalorg/mr-7.\nOpen a pull request for !7.\nTell !7 where it is reviewed.\n",
            $output,
        );
        $am = $this->ran(['git', '-C']);
        self::assertContains('--directory=drupal', $am[0][0]);
        self::assertSame('PATCH', $am[0][1]);
        self::assertContains('HEAD:refs/heads/drupalorg/mr-7', $am[1][0]);
        self::assertContains('https://git.drupalcode.org/project/collaborate.git', $this->ran(['git', 'fetch'])[1][0]);
        self::assertContains('+feature:refs/drupalorg/mr-7', $this->ran(['git', 'fetch'])[2][0]);
        self::assertCount(2, $this->ran(['git', 'update-ref', '-d']));
        $create = $this->ran(['gh', 'pr', 'create'])[0];
        self::assertContains('[drupal.org !7] Fix the bell', $create[0]);
        self::assertStringContainsString('<!-- drupalorg-mr: 7 @ abc123 -->', (string) $create[1]);
        self::assertStringContainsString('by **alice**', (string) $create[1]);
        self::assertStringContainsString('It rings twice.', (string) $create[1]);
        $note = $this->request('POST', self::PROJECT . '/merge_requests/7/notes');
        self::assertStringContainsString(
            'Mirrored to https://github.com/janalis/collaborate/pull/40',
            $note[3]['body'],
        );
        self::assertSame(['PRIVATE-TOKEN' => 'secret'], $note[2]);
    }

    public function testLeavesAMergeRequestWhosePullRequestIsCurrent(): void
    {
        $this->mergeRequest();
        $this->ghJson(['pr', 'list'], [[
            'number' => 40,
            'body' => 'x <!-- drupalorg-mr: 7 @ abc123 -->',
            'url' => 'u',
            'headRefName' => 'drupalorg/mr-7',
            'state' => 'OPEN',
        ]]);

        self::assertSame([0, '', ''], $this->watch(['mrs'], token: 'secret'));
        self::assertSame([], $this->ran(['git']));
    }

    public function testUpdatesThePullRequestOfAMergeRequestThatMoved(): void
    {
        $this->mergeRequest(description: '');
        $this->respond('GET', self::PROJECT . '/merge_requests/7/notes?per_page=100', [
            ['body' => 'Mirrored. <!-- github-mirror -->'],
            'not a note',
        ]);
        $this->ghJson(['pr', 'list'], [[
            'number' => 40,
            'body' => '<!-- drupalorg-mr: 7 @ old -->',
            'url' => 'https://github.com/janalis/collaborate/pull/40',
            'headRefName' => 'drupalorg/mr-7',
            'state' => 'OPEN',
        ]]);

        [$status, $output] = $this->watch(['mrs'], token: 'secret');

        self::assertSame([0, "Pushed drupalorg/mr-7.\nUpdate https://github.com/janalis/collaborate/pull/40.\n"], [
            $status,
            $output,
        ]);
        $edit = $this->ran(['gh', 'pr', 'edit'])[0];
        self::assertStringContainsString('_No description._', (string) $edit[1]);
        self::assertStringContainsString('@ abc123 -->', (string) $edit[1]);
    }

    public function testAsksAMergeRequestWhoseCommitsDoNotApplyToRebaseOnce(): void
    {
        $this->mergeRequest();
        $this->respond('GET', self::PROJECT . '/merge_requests/7/notes?per_page=100', []);
        $this->respond('POST', self::PROJECT . '/merge_requests/7/notes', [], 201);
        $this->ghJson(['pr', 'list'], []);
        $this->command(
            ['git', '-C'],
            '',
            1,
            static fn(array $command): bool => \in_array('am', $command, true) && !\in_array('--abort', $command, true),
        );

        [$status, $output] = $this->watch(['mrs'], token: 'secret');

        self::assertSame([0, "Comment on !7: its commits do not apply.\n"], [$status, $output]);
        self::assertNotEmpty(array_filter($this->ran(['git', '-C']), static fn(array $call): bool => \in_array(
            '--abort',
            $call[0],
            true,
        )));
        self::assertSame([], $this->ran(['gh', 'pr', 'create']));
        self::assertStringContainsString(
            '<!-- github-mirror-conflict: abc123 -->',
            $this->request('POST', self::PROJECT . '/merge_requests/7/notes')[3]['body'],
        );

        // Already asked: not again.
        $this->respond('GET', self::PROJECT . '/merge_requests/7/notes?per_page=100', [[
            'body' => '<!-- github-mirror-conflict: abc123 -->',
        ]]);
        self::assertSame([0, '', ''], $this->watch(['mrs'], token: 'secret'));
    }

    public function testReadsMergeRequestsWithoutWritingToThemWithoutAToken(): void
    {
        $this->mergeRequest();
        $this->ghJson(['pr', 'list'], []);
        $this->command(['gh', 'pr', 'create'], "https://github.com/janalis/collaborate/pull/40\n");

        [$status, $output] = $this->watch(['mrs']);

        self::assertSame([0, "Pushed drupalorg/mr-7.\nOpen a pull request for !7.\n"], [$status, $output]);
        self::assertSame([], array_filter($this->requests, static fn(array $request): bool => str_contains(
            $request[1],
            '/notes',
        )));
    }

    public function testADryRunWritesNothing(): void
    {
        $this->mergeRequest();
        $this->respond('GET', self::PROJECT . '/merge_requests/7/notes?per_page=100', []);
        $this->ghJson(['pr', 'list'], []);

        [$status, $output] = $this->watch(['mrs', '--dry-run'], token: 'secret');

        self::assertSame(0, $status);
        self::assertSame(
            "Would replay !7 onto origin/main and push drupalorg/mr-7.\nWould open a pull request for !7.\nWould tell !7 where it is reviewed.\n",
            $output,
        );
        self::assertSame([], $this->ran(['git']));
        self::assertSame([], $this->ran(['gh', 'pr', 'create']));
        self::assertSame([], array_filter($this->requests, static fn(array $request): bool => 'GET' !== $request[0]));
    }

    public function testWatchesTheProjectBranchAndRemoteItIsGiven(): void
    {
        $this->respond(
            'GET',
            'https://git.drupalcode.org/api/v4/projects/project%2Fother/merge_requests?state=opened&per_page=100&target_branch=1.x',
            [[
                'iid' => 2,
                'title' => 'T',
                'sha' => 'f00',
                'source_project_id' => 5,
                'source_branch' => 'b',
            ]],
        );
        $this->ghJson(['pr', 'list'], []);

        [$status, $output] = $this->watch([
            'mrs',
            '--repo',
            'me/fork',
            '--project',
            'other',
            '--branch',
            '1.x',
            '--remote',
            'upstream',
            '--dry-run',
        ]);

        self::assertSame(0, $status);
        self::assertStringStartsWith("Would replay !2 onto upstream/main and push drupalorg/mr-2.\n", $output);
        self::assertContains('me/fork', $this->ran(['gh', 'pr', 'list'])[0][0]);
    }

    public function testMirrorsTheNewIssuesOldestFirst(): void
    {
        $this->project();
        $this->respond('GET', self::ISSUES, [
            'list' => [
                ['nid' => 3, 'title' => 'Third', 'body' => ['value' => '<p>' . str_repeat('word ', 400) . '</p>']],
                ['nid' => 2, 'title' => 'Second', 'body' => []],
                ['nid' => 1, 'title' => 'First', 'body' => ['value' => 'Seen.']],
                'not an issue',
            ],
        ]);
        $this->ghJson(['issue', 'list', '--state', 'all'], [
            ['body' => 'x <!-- drupalorg-issue: 1 -->'],
            'not an issue',
        ]);

        [$status, $output] = $this->watch(['issues']);

        self::assertSame(0, $status);
        self::assertSame("Open an issue for drupal.org #2.\nOpen an issue for drupal.org #3.\n", $output);
        [$second, $third] = $this->ran(['gh', 'issue', 'create']);
        self::assertContains('[drupal.org #2] Second', $second[0]);
        self::assertStringContainsString('_No description._', (string) $second[1]);
        self::assertStringContainsString('https://www.drupal.org/node/3', (string) $third[1]);
        self::assertStringContainsString('…', (string) $third[1]);
        self::assertStringContainsString('<!-- drupalorg-issue: 3 -->', (string) $third[1]);
        self::assertNotEmpty($this->ran(['gh', 'label', 'create', 'drupal.org']));
    }

    /** @return iterable<string, array{bool, non-empty-string}> */
    public static function closings(): iterable
    {
        yield 'merged' => [true, 'Merged on GitHub as https://github.com/janalis/collaborate/pull/40'];
        yield 'closed' => [false, 'Closed on GitHub without merging: https://github.com/janalis/collaborate/pull/40.'];
    }

    /** @param non-empty-string $message */
    #[DataProvider('closings')]
    public function testTellsAndClosesTheMergeRequestOfAClosedPullRequest(bool $merged, string $message): void
    {
        $this->closedPull($merged);
        $this->respond('POST', self::PROJECT . '/merge_requests/7/notes', [], 201);
        $this->respond('PUT', self::PROJECT . '/merge_requests/7', []);

        [$status, $output] = $this->watch(['closed', '40'], token: 'secret');

        self::assertSame([0, "Tell !7 and close it.\nDelete drupalorg/mr-7.\n"], [$status, $output]);
        self::assertStringStartsWith(
            $message,
            $this->request('POST', self::PROJECT . '/merge_requests/7/notes')[3]['body'],
        );
        self::assertSame(['state_event' => 'close'], $this->request('PUT', self::PROJECT . '/merge_requests/7')[3]);
        self::assertSame(
            ['gh', 'api', '-X', 'DELETE', 'repos/janalis/collaborate/git/refs/heads/drupalorg/mr-7'],
            $this->ran(['gh', 'api'])[0][0],
        );
    }

    public function testOnlyDeletesTheBranchWithoutAToken(): void
    {
        $this->closedPull(true);

        self::assertSame([0, "Delete drupalorg/mr-7.\n", ''], $this->watch(['closed', '40']));
    }

    public function testClosesOnlyTheMergeRequestTheWatchersBranchNames(): void
    {
        // A description cannot name another merge request: the branch and the last marker must agree.
        $this->ghJson(['pr', 'view'], [
            'number' => 40,
            'body' => "<!-- drupalorg-mr: 9 @ abc -->\n\n<!-- drupalorg-mr: 7 @ abc123 -->",
            'url' => 'u',
            'headRefName' => 'drupalorg/mr-8',
            'state' => 'MERGED',
        ]);

        self::assertSame([0, "Not a mirrored merge request.\n", ''], $this->watch(['closed', '40'], token: 'secret'));
        self::assertSame([], array_filter($this->requests, static fn(array $request): bool => 'GET' !== $request[0]));
    }

    public function testShowsTheCommentsOfADescriptionAndUpdatesAPullRequestWhoseDescriptionHoldsTheMarker(): void
    {
        $this->mergeRequest(description: 'Hidden <!-- drupalorg-mr: 7 @ abc123 --> marker.');
        $this->ghJson(['pr', 'list'], [[
            'number' => 40,
            'body' => 'Hidden <!-- drupalorg-mr: 7 @ abc123 --> marker. <!-- drupalorg-mr: 7 @ old -->',
            'url' => 'u',
            'headRefName' => 'drupalorg/mr-7',
            'state' => 'OPEN',
        ]]);

        [$status, $output] = $this->watch(['mrs']);

        self::assertSame([0, "Pushed drupalorg/mr-7.\nUpdate u.\n"], [$status, $output]);
        self::assertStringContainsString(
            'Hidden &lt;!-- drupalorg-mr: 7 @ abc123 --&gt; marker.',
            (string) $this->ran(['gh', 'pr', 'edit'])[0][1],
        );
    }

    public function testLeavesAPullRequestThatMirrorsNothing(): void
    {
        $this->ghJson(['pr', 'view'], [
            'number' => 41,
            'body' => 'Mine.',
            'url' => 'u',
            'headRefName' => 'fix',
            'state' => 'MERGED',
        ]);

        self::assertSame([0, "Not a mirrored merge request.\n", ''], $this->watch(['closed', '41']));
    }

    public function testOpensTheReleaseChecklistOnce(): void
    {
        file_put_contents($this->temporary . '/notes.md', "### Added\n\n- A feature.\n");
        $this->ghJson(['issue', 'list', '--state', 'open'], []);

        [$status, $output] = $this->watch(['release-issue', '0.6.0', $this->temporary . '/notes.md']);

        self::assertSame([0, "Open the release checklist of 0.6.0.\n"], [$status, $output]);
        $create = $this->ran(['gh', 'issue', 'create'])[0];
        self::assertContains('Publish the Drupal module 0.6.0 on drupal.org', $create[0]);
        self::assertStringContainsString('https://www.drupal.org/project/collaborate/releases', (string) $create[1]);
        self::assertStringContainsString("- A feature.\n", (string) $create[1]);
        self::assertStringContainsString('<!-- drupalorg-release: 0.6.0 -->', (string) $create[1]);

        $this->ghJson(['issue', 'list', '--state', 'open'], [
            ['number' => 4, 'body' => '<!-- drupalorg-release: 0.6.0 -->'],
            ['number' => 5, 'body' => 'Other.'],
        ]);
        self::assertSame([0, '', ''], $this->watch(['release-issue', '0.6.0', $this->temporary . '/notes.md']));
    }

    public function testClosesTheChecklistOfAReleaseDrupalOrgLists(): void
    {
        $this->project();
        $this->respond(
            'GET',
            'https://www.drupal.org/api-d7/node.json?type=project_release&field_release_project=99&field_release_version=0.6.0',
            ['list' => [['nid' => 5]]],
        );
        $this->respond(
            'GET',
            'https://www.drupal.org/api-d7/node.json?type=project_release&field_release_project=99&field_release_version=0.7.0',
            ['list' => []],
        );
        $this->ghJson(['issue', 'list', '--state', 'open'], [
            ['number' => 4, 'body' => '<!-- drupalorg-release: 0.6.0 -->'],
            ['number' => 6, 'body' => '<!-- drupalorg-release: 0.7.0 -->'],
            ['number' => 8, 'body' => '<!-- drupalorg-release: -->'],
        ]);

        [$status, $output] = $this->watch(['releases']);

        self::assertSame([0, "Close the release checklist of 0.6.0.\n"], [$status, $output]);
        self::assertSame(
            [
                'gh',
                'issue',
                'close',
                '4',
                '--comment',
                'drupal.org lists the release.',
                '--repo',
                'janalis/collaborate',
            ],
            $this->ran(['gh', 'issue', 'close'])[0][0],
        );
    }

    /** @return iterable<string, array{\Closure(self): list<string>, non-empty-string}> */
    public static function failures(): iterable
    {
        yield 'unreadable notes' => [
            static fn(self $test): array => ['release-issue', '0.6.0', '/no/such/notes.md'],
            'drupalorg: cannot read /no/such/notes.md',
        ];
        yield 'a failing gh' => [
            static function (self $test): array {
                $test->command(['gh'], '', 1);

                return ['closed', '40'];
            },
            'drupalorg: gh pr view 40',
        ];
        yield 'a failing git' => [
            static function (self $test): array {
                $test->mergeRequest();
                $test->ghJson(['pr', 'list'], []);
                $test->command(['git', 'fetch'], '', 128);

                return ['mrs'];
            },
            'drupalorg: git fetch',
        ];
        yield 'a refused note' => [
            static function (self $test): array {
                $test->closedPull(true);
                $test->respond('POST', self::PROJECT . '/merge_requests/7/notes', ['message' => '403 Forbidden'], 403);

                return ['closed', '40'];
            },
            'drupalorg: POST /merge_requests/7/notes: HTTP 403',
        ];
    }

    /**
     * @param \Closure(self): list<string> $setUp
     * @param non-empty-string             $message
     */
    #[DataProvider('failures')]
    public function testReportsWhatFailed(\Closure $setUp, string $message): void
    {
        [$status, , $error] = $this->watch($setUp($this), token: 'secret');

        self::assertSame(1, $status);
        self::assertStringStartsWith($message, $error);
    }

    public function testRunsItsCommandsInTheWorkingDirectory(): void
    {
        self::assertSame(
            [0, 'out', 'err'],
            new ProcessRunner()->run(
                [\PHP_BINARY, '-r', 'echo stream_get_contents(STDIN); fwrite(STDERR, "err");'],
                'out',
            ),
        );
        self::assertSame(3, new ProcessRunner()->run([\PHP_BINARY, '-r', 'exit(3);'])[0]);
    }

    public function testTheEntryScriptRunsTheWatcher(): void
    {
        $output = [];
        $status = null;
        exec(
            escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(\dirname(__DIR__, 3) . '/tools/drupalorg.php') . ' 2>&1',
            $output,
            $status,
        );

        self::assertSame(2, $status);
        self::assertStringStartsWith('usage: drupalorg.php', implode("\n", $output));
    }

    /** The drupal.org project, node 99. */
    public function project(): void
    {
        $this->respond('GET', self::NID, ['list' => [['nid' => 99]]]);
    }

    /** Merge request !7, from alice's issue fork. */
    public function mergeRequest(string $description = 'It rings twice.'): void
    {
        $this->respond('GET', self::MRS, [
            [
                'iid' => 7,
                'title' => 'Fix the bell',
                'description' => $description,
                'sha' => 'abc123',
                'author' => ['username' => 'alice'],
                'web_url' => 'https://git.drupalcode.org/project/collaborate/-/merge_requests/7',
                'source_project_id' => 123,
                'source_branch' => 'feature',
            ],
            'not a merge request',
        ]);
        $this->respond('GET', 'https://git.drupalcode.org/api/v4/projects/123', [
            'http_url_to_repo' => 'https://git.drupalcode.org/issue/collaborate-1.git',
        ]);
        $this->respond('GET', 'https://git.drupalcode.org/api/v4/projects/0', null, 404);
    }

    /** Pull request #40, the mirror of !7, merged or closed. */
    public function closedPull(bool $merged): void
    {
        $this->ghJson(['pr', 'view'], [
            'number' => 40,
            'body' => '<!-- drupalorg-mr: 7 @ abc123 -->',
            'url' => 'https://github.com/janalis/collaborate/pull/40',
            'headRefName' => 'drupalorg/mr-7',
            'state' => $merged ? 'MERGED' : 'CLOSED',
        ]);
    }

    public function respond(string $method, string $url, mixed $body, int $status = 200): void
    {
        $this->responses[$method . ' ' . $url] = [$status, $body];
    }

    /** @param list<string> $prefix */
    public function ghJson(array $prefix, mixed $json): void
    {
        $this->command(['gh', ...$prefix], json_encode($json, \JSON_THROW_ON_ERROR));
    }

    /**
     * Answers the commands that start with `$prefix` (the latest answer wins).
     *
     * @param list<string>                         $prefix
     * @param (\Closure(list<string>): bool)|null  $when
     */
    public function command(array $prefix, string $output, int $status = 0, ?\Closure $when = null): void
    {
        array_unshift($this->commands, [
            $prefix,
            /** @param list<string> $command */
            static fn(array $command): array => (
                null === $when || $when($command) ? [$status, $output, 'failed'] : [0, '', '']
            ),
        ]);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function watch(
        array $arguments,
        string $repository = 'janalis/collaborate',
        #[\SensitiveParameter]
        string $token = '',
    ): array {
        $test = $this;
        $http = new class($test) implements Http {
            public function __construct(
                private readonly WatcherTest $test,
            ) {}

            public function request(string $method, string $url, array $headers = [], array $form = []): array
            {
                return $this->test->answer($method, $url, $headers, $form);
            }
        };
        $runner = new class($test) implements Runner {
            public function __construct(
                private readonly WatcherTest $test,
            ) {}

            public function run(array $command, ?string $input = null): array
            {
                return $this->test->execute($command, $input);
            }
        };
        $environment = array_filter(
            ['GITHUB_REPOSITORY' => $repository, 'DRUPALORG_GITLAB_TOKEN' => $token],
            static fn(string $value): bool => '' !== $value,
        );
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        \assert(false !== $stdout && false !== $stderr);
        $status = new Watcher($http, $runner, $environment, $this->temporary)->run($arguments, $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);

        return [$status, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $form
     *
     * @return array{int, mixed}
     */
    public function answer(string $method, string $url, array $headers, array $form): array
    {
        $this->requests[] = [$method, $url, $headers, $form];

        return $this->responses[$method . ' ' . $url] ?? [404, null];
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string, string}
     */
    public function execute(array $command, ?string $input): array
    {
        $this->ran[] = [$command, $input];
        foreach ($this->commands as [$prefix, $answer]) {
            if (\array_slice($command, 0, \count($prefix)) === $prefix) {
                return $answer($command);
            }
        }

        return [0, '', ''];
    }

    /**
     * The commands run that start with `$prefix`.
     *
     * @param list<string> $prefix
     *
     * @return list<array{list<string>, ?string}>
     */
    private function ran(array $prefix): array
    {
        return array_values(array_filter(
            $this->ran,
            static fn(array $call): bool => \array_slice($call[0], 0, \count($prefix)) === $prefix,
        ));
    }

    /** @return array{string, string, array<string, string>, array<string, string>} the last request sent there */
    private function request(string $method, string $url): array
    {
        $sent = array_values(array_filter(
            $this->requests,
            static fn(array $request): bool => $request[0] === $method && $request[1] === $url,
        ));
        self::assertNotSame([], $sent, $method . ' ' . $url . ' was not sent');

        return $sent[\count($sent) - 1];
    }
}
