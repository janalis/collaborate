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

    /** @return iterable<string, array{list<string>}> */
    public static function malformedArguments(): iterable
    {
        yield 'an option inside an argument' => [['mrs', 'x--repo', 'other/repo']];
        yield 'an option name with a suffix' => [['mrs', '--repox', 'other/repo']];
        yield 'an option name with a line break' => [['mrs', "--repo\n", 'other/repo']];
        yield 'a command after an unknown option' => [['--force', 'mrs']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('malformedArguments')]
    public function testPrintsItsUsageExactlyForAMalformedOption(array $arguments): void
    {
        [$status, $output, $error] = $this->watch($arguments);

        self::assertSame([2, ''], [$status, $output]);
        self::assertSame(
            "usage: drupalorg.php mrs|issues|releases|closed <pr>|release-issue <version> <notes> [--repo owner/name] [--project name] [--branch 0.x] [--remote origin] [--dry-run]\n",
            $error,
        );
        self::assertSame([], $this->ran);
    }

    public function testTakesAnOptionGivenLast(): void
    {
        $this->project();
        $this->respond('GET', self::ISSUES, ['list' => []]);

        self::assertSame([0, '', ''], $this->watch(['issues', '--repo', 'other/repo'], repository: ''));
        self::assertSame(
            [
                'gh',
                'issue',
                'list',
                '--state',
                'all',
                '--label',
                'drupal.org',
                '--limit',
                '500',
                '--json',
                'body',
                '--repo',
                'other/repo',
            ],
            $this->ran[0][0],
        );
    }

    public function testRunsEachStepOfAMirrorExactly(): void
    {
        $this->mergeRequest(description: "  It rings twice.\n");
        $this->respond('GET', self::PROJECT . '/merge_requests/7/notes?per_page=100', []);
        $this->respond('POST', self::PROJECT . '/merge_requests/7/notes', [], 201);
        $this->ghJson(['pr', 'list'], []);
        $this->command(['git', 'merge-base'], "base\n");
        $this->command(['git', 'format-patch'], 'PATCH');
        $this->command(['gh', 'pr', 'create'], "https://github.com/janalis/collaborate/pull/40\n");

        self::assertSame(0, $this->watch(['mrs'], token: 'secret')[0]);

        $bot = [
            '-c',
            'user.name=github-actions[bot]',
            '-c',
            'user.email=41898282+github-actions[bot]@users.noreply.github.com',
        ];
        $body = "Mirrored from drupal.org merge request [!7](https://git.drupalcode.org/project/collaborate/-/merge_requests/7) by **alice** ([drupal.org profile](https://www.drupal.org/u/alice)), its commits replayed under `drupal/` with their authors.\n\nIt rings twice.\n\n---\n\nReview happens here. Once merged, the change reaches drupal.org's `0.x` through the split, and the merge request is closed with a link back.\n\n<!-- drupalorg-mr: 7 @ abc123 -->\n";
        self::assertSame(
            [
                [
                    [
                        'gh',
                        'pr',
                        'list',
                        '--state',
                        'open',
                        '--head',
                        'drupalorg/mr-7',
                        '--json',
                        'number,body,url,headRefName,state',
                        '--repo',
                        'janalis/collaborate',
                    ],
                    null,
                ],
                [['git', 'fetch', '--quiet', '--no-tags', 'origin', 'main'], null],
                [
                    [
                        'git',
                        'fetch',
                        '--quiet',
                        '--no-tags',
                        '--force',
                        'https://git.drupalcode.org/project/collaborate.git',
                        '+0.x:refs/drupalorg/target',
                    ],
                    null,
                ],
                [
                    [
                        'git',
                        'fetch',
                        '--quiet',
                        '--no-tags',
                        '--force',
                        'https://git.drupalcode.org/issue/collaborate-1.git',
                        '+feature:refs/drupalorg/mr-7',
                    ],
                    null,
                ],
                [['git', 'merge-base', 'refs/drupalorg/target', 'refs/drupalorg/mr-7'], null],
                [['git', 'format-patch', '--stdout', 'base..refs/drupalorg/mr-7'], null],
                [['git', 'worktree', 'add', '--quiet', '--detach', '{work}', 'origin/main'], null],
                [['git', '-C', '{work}', ...$bot, 'am', '--quiet', '-3', '--directory=drupal'], 'PATCH'],
                [
                    ['git', '-C', '{work}', 'push', '--quiet', '--force', 'origin', 'HEAD:refs/heads/drupalorg/mr-7'],
                    null,
                ],
                [['git', 'worktree', 'remove', '--force', '{work}'], null],
                [['git', 'update-ref', '-d', 'refs/drupalorg/target'], null],
                [['git', 'update-ref', '-d', 'refs/drupalorg/mr-7'], null],
                [
                    [
                        'gh',
                        'label',
                        'create',
                        'drupal.org',
                        '--force',
                        '--color',
                        '0678BE',
                        '--description',
                        "Mirrored from the module's drupal.org project",
                        '--repo',
                        'janalis/collaborate',
                    ],
                    null,
                ],
                [
                    [
                        'gh',
                        'pr',
                        'create',
                        '--head',
                        'drupalorg/mr-7',
                        '--base',
                        'main',
                        '--title',
                        '[drupal.org !7] Fix the bell',
                        '--label',
                        'drupal.org',
                        '--body-file',
                        '-',
                        '--repo',
                        'janalis/collaborate',
                    ],
                    $body,
                ],
            ],
            $this->commands($this->temporary),
        );
        self::assertSame(
            [
                ['GET', self::MRS, ['PRIVATE-TOKEN' => 'secret'], []],
                ['GET', 'https://git.drupalcode.org/api/v4/projects/123', [], []],
                ['GET', self::PROJECT . '/merge_requests/7/notes?per_page=100', ['PRIVATE-TOKEN' => 'secret'], []],
                [
                    'POST',
                    self::PROJECT . '/merge_requests/7/notes',
                    ['PRIVATE-TOKEN' => 'secret'],
                    [
                        'body' => "Mirrored to https://github.com/janalis/collaborate/pull/40: this project is developed on GitHub, and reviewed there. New commits here update it.\n\n<!-- github-mirror -->",
                    ],
                ],
            ],
            $this->requests,
        );
    }

    public function testReplaysInTheSystemsTemporaryDirectoryByDefault(): void
    {
        $this->mergeRequest();
        $this->ghJson(['pr', 'list'], []);

        $this->watch(['mrs'], temporary: '');

        self::assertMatchesRegularExpression(
            '#^' . preg_quote(sys_get_temp_dir(), '#') . '/drupalorg-mr-7-[0-9a-f]{8}$#D',
            $this->ran(['git', 'worktree', 'add'])[0][0][5],
        );
    }

    public function testCleansUpAndGoesOnAfterAMergeRequestWhoseCommitsDoNotApply(): void
    {
        $this->mergeRequests([$this->mergeRequestOf(7), $this->mergeRequestOf(8, sha: 'def456')]);
        $this->respond('GET', self::PROJECT . '/merge_requests/7/notes?per_page=100', []);
        $this->respond('GET', self::PROJECT . '/merge_requests/8/notes?per_page=100', []);
        $this->respond('POST', self::PROJECT . '/merge_requests/7/notes', [], 201);
        $this->respond('POST', self::PROJECT . '/merge_requests/8/notes', [], 201);
        $this->ghJson(['pr', 'list'], []);
        $this->command(['gh', 'pr', 'create'], "u\n");
        $this->command(
            ['git', '-C'],
            '',
            1,
            static fn(array $command): bool => (
                \in_array('am', $command, true)
                && \in_array('-3', $command, true)
                && str_contains(implode(' ', $command), 'mr-7-')
            ),
        );

        [$status, $output] = $this->watch(['mrs'], token: 'secret');

        self::assertSame([
            0,
            "Comment on !7: its commits do not apply.\nPushed drupalorg/mr-8.\nOpen a pull request for !8.\nTell !8 where it is reviewed.\n",
        ], [$status, $output]);
        $commands = $this->commands($this->temporary);
        self::assertContains([['git', '-C', '{work}', 'am', '--abort'], null], $commands);
        self::assertCount(2, array_filter(
            $commands,
            static fn(array $call): bool => ['git', 'worktree', 'remove', '--force', '{work}'] === $call[0],
        ));
        self::assertCount(4, $this->ran(['git', 'update-ref', '-d']));
        self::assertSame(
            [
                'body' => "Its commits do not apply on GitHub's `main`: please rebase this merge request on `0.x`.\n\n<!-- github-mirror-conflict: abc123 -->",
            ],
            $this->request('POST', self::PROJECT . '/merge_requests/7/notes')[3],
        );
    }

    public function testGoesOnAfterAMergeRequestWhosePullRequestIsCurrent(): void
    {
        $this->mergeRequests([$this->mergeRequestOf(7), $this->mergeRequestOf(8, sha: 'def456')]);
        $this->command(
            ['gh', 'pr', 'list', '--state', 'open', '--head', 'drupalorg/mr-7'],
            json_encode(
                [
                    'junk',
                    [
                        'number' => 40,
                        'body' => "x\n\n<!-- drupalorg-mr: 7 @ abc123 -->\n",
                        'url' => 'u',
                        'headRefName' => 'drupalorg/mr-7',
                    ],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        );
        $this->command(['gh', 'pr', 'list', '--state', 'open', '--head', 'drupalorg/mr-8'], '[]');
        $this->command(['gh', 'pr', 'create'], "u\n");

        self::assertSame([0, "Pushed drupalorg/mr-8.\nOpen a pull request for !8.\n", ''], $this->watch(['mrs']));
    }

    public function testAsksForTheSourceOfAMergeRequestWithoutOne(): void
    {
        $this->mergeRequests([['iid' => '3', 'sha' => 'f00', 'source_branch' => 'b']]);
        $this->ghJson(['pr', 'list'], []);

        $this->watch(['mrs', '--dry-run']);

        self::assertSame('https://git.drupalcode.org/api/v4/projects/0', $this->requests[1][1]);
    }

    public function testMirrorsAMergeRequestWithoutADescriptionAndANumberForTitle(): void
    {
        $this->mergeRequests([['title' => 42, 'description' => " \n "] + $this->mergeRequestOf(9)]);
        $this->ghJson(['pr', 'list'], [[
            'number' => 41,
            'body' => '<!-- drupalorg-mr: 9 @ old -->',
            'url' => 'u',
            'headRefName' => 'drupalorg/mr-9',
        ]]);

        self::assertSame(0, $this->watch(['mrs'])[0]);

        $edit = $this->ran(['gh', 'pr', 'edit'])[0];
        self::assertSame(
            [
                'gh',
                'pr',
                'edit',
                '41',
                '--title',
                '[drupal.org !9] 42',
                '--body-file',
                '-',
                '--repo',
                'janalis/collaborate',
            ],
            $edit[0],
        );
        self::assertStringContainsString("with their authors.\n\n_No description._\n\n---", (string) $edit[1]);
    }

    public function testFindsAnIssueMirroredAfterOthers(): void
    {
        $this->project();
        $this->respond('GET', self::ISSUES, ['list' => [['nid' => 2, 'title' => 'Mirrored']]]);
        $this->ghJson(['issue', 'list', '--state', 'all'], [
            ['body' => 'Other.'],
            ['body' => 'x <!-- drupalorg-issue: 2 -->'],
        ]);

        self::assertSame([0, '', ''], $this->watch(['issues']));
    }

    public function testWritesTheIssueExactly(): void
    {
        $this->respond('GET', self::NID, ['list' => ['not a project', ['nid' => 99]]]);
        $this->respond('GET', self::ISSUES, [
            'list' => [
                ['nid' => 5, 'title' => 'Long', 'body' => ['value' => str_repeat('é', 1501)]],
                ['nid' => 4, 'title' => 'Exact', 'body' => ['value' => str_repeat('é', 1500)]],
                ['nid' => 3, 'title' => 'Short', 'body' => ['value' => "<p>  Seen  </p>\n"]],
                ['nid' => 2, 'title' => 'Mirrored'],
            ],
        ]);
        $this->ghJson(['issue', 'list', '--state', 'all'], [
            ['body' => 'x <!-- drupalorg-issue: 2 -->'],
            ['body' => 'Other.'],
            ['nobody' => true],
        ]);

        self::assertSame(0, $this->watch(['issues'])[0]);

        $created = $this->ran(['gh', 'issue', 'create']);
        self::assertCount(3, $created);
        $body = static fn(
            int $nid,
            string $excerpt,
        ): string => "Reported on drupal.org: https://www.drupal.org/node/{$nid}\n\n{$excerpt}\n\n---\n\nIts status on drupal.org is not synced: answer there, and link this issue's fix.\n\n<!-- drupalorg-issue: {$nid} -->\n";
        self::assertSame(
            [
                'gh',
                'issue',
                'create',
                '--title',
                '[drupal.org #3] Short',
                '--label',
                'drupal.org',
                '--body-file',
                '-',
                '--repo',
                'janalis/collaborate',
            ],
            $created[0][0],
        );
        self::assertSame($body(3, '> Seen'), $created[0][1]);
        self::assertSame($body(4, '> ' . str_repeat('é', 1500)), $created[1][1]);
        self::assertSame($body(5, '> ' . str_repeat('é', 1500) . '…'), $created[2][1]);
    }

    public function testWritesTheReleaseChecklistExactlyAndClosesEveryListedRelease(): void
    {
        file_put_contents($this->temporary . '/notes.md', "\n\n### Added\n\n- A feature.\n\n");
        $this->ghJson(['issue', 'list', '--state', 'open'], []);

        $this->watch(['release-issue', '0.6.0', $this->temporary . '/notes.md']);

        self::assertSame(
            "The tag `0.6.0` is pushed to drupal.org. drupal.org creates a release by hand:\n\n1. Open https://www.drupal.org/project/collaborate/releases and click **Add new release**.\n2. Choose the tag `0.6.0`.\n3. Paste the notes below as its release notes, and save. Pasted as HTML, `<code>` keeps `<` and `>` as they are: drupal.org escapes a code span itself, so `&lt;` would show as written.\n4. For a new series (a new minor or major), tick it **Supported** under the project's **Administer releases**, or drupal.org shows the release as unsupported.\n5. If `drupal/README.md` changed since the last release, paste it (rendered as HTML) as the project's description, and its first paragraph as the summary: **Edit** on the project.\n\nThis issue closes once drupal.org lists the release.\n\n<details><summary>Release notes</summary>\n\n### Added\n\n- A feature.\n</details>\n\n<!-- drupalorg-release: 0.6.0 -->\n",
            $this->ran(['gh', 'issue', 'create'])[0][1],
        );

        $this->ran = [];
        $this->project();
        foreach (['0.6.0' => [['nid' => 5]], '0.6.1' => [['nid' => 6]], '0.7.0' => []] as $version => $list) {
            $this->respond(
                'GET',
                'https://www.drupal.org/api-d7/node.json?type=project_release&field_release_project=99&field_release_version='
                . $version,
                ['list' => $list],
            );
        }
        $this->ghJson(['issue', 'list', '--state', 'open'], [
            ['number' => 6, 'body' => '<!-- drupalorg-release: 0.7.0 -->'],
            ['number' => 4, 'body' => '<!-- drupalorg-release: 0.6.0 -->'],
            ['number' => 5, 'body' => '<!-- drupalorg-release: 0.6.1 -->'],
            ['body' => '<!-- drupalorg-release: 0.6.0 -->'],
        ]);

        self::assertSame(
            [0, "Close the release checklist of 0.6.0.\nClose the release checklist of 0.6.1.\n", ''],
            $this->watch(['releases']),
        );
    }

    public function testClosesNoChecklistBeforeTheProjectExists(): void
    {
        // Were the project's lookup skipped, this answer would close it.
        $this->respond(
            'GET',
            'https://www.drupal.org/api-d7/node.json?type=project_release&field_release_project=&field_release_version=0.6.0',
            ['list' => [['nid' => 1]]],
        );
        $this->ghJson(['issue', 'list', '--state', 'open'], [[
            'number' => 4,
            'body' => '<!-- drupalorg-release: 0.6.0 -->',
        ]]);

        self::assertSame([0, '', ''], $this->watch(['releases']));
    }

    /** @return iterable<string, array{string, string}> */
    public static function foreignPulls(): iterable
    {
        yield 'a branch with a prefix' => ['xdrupalorg/mr-7', '<!-- drupalorg-mr: 7 @ abc123 -->'];
        yield 'a branch with a suffix' => ['drupalorg/mr-7x', '<!-- drupalorg-mr: 7 @ abc123 -->'];
        yield 'a branch with a line break' => ["drupalorg/mr-7\n", '<!-- drupalorg-mr: 7 @ abc123 -->'];
        yield 'a marker that is not the last' => ['drupalorg/mr-7', "<!-- drupalorg-mr: 7 @ abc123 -->\n\nMore."];
    }

    #[DataProvider('foreignPulls')]
    public function testLeavesAPullRequestTheWatcherDidNotOpen(string $branch, string $body): void
    {
        $this->ghJson(['pr', 'view'], [
            'number' => 40,
            'body' => $body,
            'url' => 'u',
            'headRefName' => $branch,
            'state' => 'MERGED',
        ]);

        self::assertSame([0, "Not a mirrored merge request.\n", ''], $this->watch(['closed', '40'], token: 'secret'));
    }

    public function testClosesTheMergeRequestOfAPullRequestWhoseBodyEndsWithItsMarker(): void
    {
        $this->closedPull(true);
        $this->ghJson(['pr', 'view'], [
            'number' => 40,
            'body' => "x\n<!-- drupalorg-mr: 7 @ abc123 -->\n",
            'url' => 'u',
            'headRefName' => 'drupalorg/mr-7',
            'state' => 'CLOSED',
        ]);
        $this->respond('POST', self::PROJECT . '/merge_requests/7/notes', [], 201);
        $this->respond('PUT', self::PROJECT . '/merge_requests/7', []);

        self::assertSame(
            [0, "Tell !7 and close it.\nDelete drupalorg/mr-7.\n", ''],
            $this->watch(['closed', '40'], token: 'secret'),
        );
        self::assertSame(
            ['gh', 'pr', 'view', '40', '--json', 'number,body,url,headRefName,state', '--repo', 'janalis/collaborate'],
            $this->ran[0][0],
        );
    }

    /** @return iterable<string, array{\Closure(self): list<string>, string}> */
    public static function exactFailures(): iterable
    {
        yield 'a failing gh' => [
            static function (self $test): array {
                $test->command(['gh'], '', 1, error: "not found\n");

                return ['closed', '40'];
            },
            "drupalorg: gh pr view 40 --json number,body,url,headRefName,state: not found\n",
        ];
        yield 'a failing git' => [
            static function (self $test): array {
                $test->mergeRequest();
                $test->ghJson(['pr', 'list'], []);
                $test->command(['git', 'fetch'], '', 128, error: "denied\n");

                return ['mrs'];
            },
            "drupalorg: git fetch --quiet --no-tags origin main: denied\n",
        ];
        yield 'a redirected note' => [
            static function (self $test): array {
                $test->closedPull(true);
                $test->respond('POST', self::PROJECT . '/merge_requests/7/notes', [], 300);

                return ['closed', '40'];
            },
            "drupalorg: POST /merge_requests/7/notes: HTTP 300\n",
        ];
        yield 'a pull request without its number' => [
            static function (self $test): array {
                $test->ghJson(['pr', 'view'], ['body' => '', 'headRefName' => 'drupalorg/mr-7']);

                return ['closed', '40'];
            },
            "drupalorg: gh gave a pull request without its number\n",
        ];
    }

    /** @param \Closure(self): list<string> $setUp */
    #[DataProvider('exactFailures')]
    public function testReportsWhatFailedExactly(\Closure $setUp, string $message): void
    {
        self::assertSame([1, '', $message], $this->watch($setUp($this), token: 'secret'));
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

    /**
     * A merge request from alice's issue fork, its iid as GitLab's JSON gives it.
     *
     * @return array<string, mixed>
     */
    public function mergeRequestOf(int $iid, string $sha = 'abc123'): array
    {
        $this->respond('GET', 'https://git.drupalcode.org/api/v4/projects/123', [
            'http_url_to_repo' => 'https://git.drupalcode.org/issue/collaborate-1.git',
        ]);

        return [
            'iid' => (string) $iid,
            'title' => 'Change ' . $iid,
            'description' => '',
            'sha' => $sha,
            'author' => ['username' => 'alice'],
            'web_url' => 'https://git.drupalcode.org/project/collaborate/-/merge_requests/' . $iid,
            'source_project_id' => 123,
            'source_branch' => 'feature-' . $iid,
        ];
    }

    /** @param list<array<string, mixed>> $requests */
    public function mergeRequests(array $requests): void
    {
        $this->respond('GET', self::MRS, $requests);
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
    public function command(
        array $prefix,
        string $output,
        int $status = 0,
        ?\Closure $when = null,
        string $error = 'failed',
    ): void {
        array_unshift($this->commands, [
            $prefix,
            /** @param list<string> $command */
            static fn(array $command): array => (
                null === $when || $when($command) ? [$status, $output, $error] : [0, '', '']
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
        ?string $temporary = null,
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
        $status = new Watcher($http, $runner, $environment, $temporary ?? $this->temporary)->run(
            $arguments,
            $stdout,
            $stderr,
        );
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
     * Every command run, and its input, a worktree of the watcher's named `{work}`.
     *
     * @return list<array{list<string>, ?string}>
     */
    private function commands(string $temporary): array
    {
        return array_map(static fn(array $call): array => [
            array_map(
                static fn(string $part): string => (string) preg_replace(
                    '#^' . preg_quote($temporary, '#') . '/drupalorg-mr-\d+-[0-9a-f]{8}$#D',
                    '{work}',
                    $part,
                ),
                $call[0],
            ),
            $call[1],
        ], $this->ran);
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
