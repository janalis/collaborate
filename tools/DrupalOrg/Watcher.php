<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tools\DrupalOrg;

/**
 * Watches the module's drupal.org project for GitHub, where its code is merged:
 *
 * - `mrs`: each open merge request on git.drupalcode.org becomes a pull request here, its commits replayed under
 *   `drupal/` with their authors (`drupalorg/mr-<iid>`), updated when the merge request moves; the merge request
 *   is told where its review happens, or asked to rebase when its commits do not apply;
 * - `issues`: each new issue of the project becomes an issue here, linking back;
 * - `closed <pr>`: a mirrored pull request was merged or closed: its merge request is told, and closed;
 * - `release-issue <version> <notes>`: opens the issue that hands a maintainer drupal.org's "Create release";
 * - `releases`: closes those issues once drupal.org lists the release.
 *
 * Until the project exists on drupal.org, every command notes it and does nothing. Without a GitLab token, the
 * merge requests are read but not written to. `--dry-run` prints what would be written, here and there.
 *
 * @phpstan-import-type MergeRequest from DrupalOrg
 */
final class Watcher
{
    private const string USAGE = 'usage: drupalorg.php mrs|issues|releases|closed <pr>|release-issue <version> <notes> [--repo owner/name] [--project name] [--branch 0.x] [--remote origin] [--dry-run]';

    /** The marker of a mirrored merge request in its pull request: `<!-- drupalorg-mr: <iid> @ <sha> -->`. */
    public const string MR = '<!-- drupalorg-mr: %d @ %s -->';

    /** The marker of a mirrored issue: `<!-- drupalorg-issue: <nid> -->`. */
    public const string ISSUE = '<!-- drupalorg-issue: %d -->';

    /** The marker of a release's checklist issue. */
    public const string RELEASE = '<!-- drupalorg-release: %s -->';

    /** The marker of the note that tells a merge request where it is reviewed. */
    public const string MIRRORED = '<!-- github-mirror -->';

    /** The identity the replayed commits are committed with; their authors stay theirs. */
    private const array COMMITTER = [
        '-c',
        'user.name=github-actions[bot]',
        '-c',
        'user.email=41898282+github-actions[bot]@users.noreply.github.com',
    ];

    /** @var resource */
    private $stdout;

    private bool $dryRun = false;

    /** @param array<string, string> $environment `GITHUB_REPOSITORY`, `DRUPALORG_GITLAB_TOKEN` */
    public function __construct(
        private readonly Http $http,
        private readonly Runner $runner,
        private readonly array $environment = [],
        private readonly string $temporary = '',
    ) {}

    /**
     * @param list<string> $arguments
     * @param resource     $stdout
     * @param resource     $stderr
     *
     * @return int 0 on success, 1 on a failure, 2 on a usage error
     */
    public function run(array $arguments, $stdout, $stderr): int
    {
        $this->stdout = $stdout;
        $options = [
            'repo' => $this->environment['GITHUB_REPOSITORY'] ?? '',
            'project' => 'collaborate',
            'branch' => '0.x',
            'remote' => 'origin',
        ];
        $positional = [];
        $rest = $arguments;
        while ([] !== $rest) {
            $argument = array_shift($rest);
            if ('--dry-run' === $argument) {
                $this->dryRun = true;
            } elseif (1 === preg_match('/^--(repo|project|branch|remote)$/D', $argument) && [] !== $rest) {
                $options[substr($argument, 2)] = array_shift($rest);
            } elseif (str_starts_with($argument, '--')) {
                $positional = [];
                break;
            } else {
                $positional[] = $argument;
            }
        }
        $command = $positional[0] ?? '';
        $arity = ['mrs' => 1, 'issues' => 1, 'releases' => 1, 'closed' => 2, 'release-issue' => 3][$command] ?? null;
        if (null === $arity || \count($positional) !== $arity || '' === $options['repo']) {
            fwrite($stderr, self::USAGE . "\n");

            return 2;
        }
        $drupal = new DrupalOrg($this->http, $options['project'], $this->environment['DRUPALORG_GITLAB_TOKEN'] ?? '');
        $github = new GitHub($this->runner, $options['repo']);
        try {
            match ($command) {
                'mrs' => $this->mergeRequests($drupal, $github, $options['branch'], $options['remote']),
                'issues' => $this->issues($drupal, $github),
                'releases' => $this->releases($drupal, $github),
                'closed' => $this->closed($drupal, $github, (int) $positional[1]),
                default => $this->releaseIssue($drupal, $github, $positional[1], $positional[2]),
            };
        } catch (\RuntimeException $error) {
            fwrite($stderr, 'drupalorg: ' . $error->getMessage() . "\n");

            return 1;
        }

        return 0;
    }

    private function mergeRequests(DrupalOrg $drupal, GitHub $github, string $branch, string $remote): void
    {
        $requests = $drupal->mergeRequests($branch);
        if (null === $requests) {
            $this->say('No drupal.org project: nothing to mirror.');

            return;
        }
        foreach ($requests as $request) {
            $name = 'drupalorg/mr-' . $request['iid'];
            $marker = \sprintf(self::MR, $request['iid'], $request['sha']);
            $pull = $github->pullOf($name);
            // The marker the watcher wrote last, at the end of the body: a description cannot stand for it.
            if (null !== $pull && str_ends_with(rtrim($pull['body']), $marker)) {
                continue;
            }
            if (!$this->replay($drupal, $request, $name, $remote, $branch)) {
                $conflict = '<!-- github-mirror-conflict: ' . $request['sha'] . ' -->';
                if ($drupal->writable() && !$drupal->noted($request['iid'], $conflict)) {
                    $this->write('comment on !'
                    . $request['iid']
                    . ': its commits do not apply', static fn() => $drupal->comment(
                        $request['iid'],
                        "Its commits do not apply on GitHub's `main`: please rebase this merge request on `{$branch}`.\n\n{$conflict}",
                    ));
                }
                continue;
            }
            $title = '[drupal.org !' . $request['iid'] . '] ' . $request['title'];
            $body = \sprintf(
                "Mirrored from drupal.org merge request [!%d](%s) by **%s** ([drupal.org profile](%s/u/%s)), its commits replayed under `drupal/` with their authors.\n\n%s\n\n---\n\nReview happens here. Once merged, the change reaches drupal.org's `%s` through the split, and the merge request is closed with a link back.\n\n%s\n",
                $request['iid'],
                $request['url'],
                $request['author'],
                DrupalOrg::SITE,
                rawurlencode($request['author']),
                '' === trim($request['description']) ? '_No description._' : self::inert(trim($request['description'])),
                $branch,
                $marker,
            );
            if (null === $pull) {
                $url = '';
                $this->write('open a pull request for !' . $request['iid'], static function () use (
                    $github,
                    $name,
                    $title,
                    $body,
                    &$url,
                ): void {
                    $url = $github->openPull($name, $title, $body);
                });
            } else {
                $url = $pull['url'];
                $this->write('update ' . $url, static fn() => $github->editPull($pull['number'], $title, $body));
            }
            if ($drupal->writable() && !$drupal->noted($request['iid'], self::MIRRORED)) {
                $this->write('tell !' . $request['iid'] . ' where it is reviewed', static fn() => $drupal->comment(
                    $request['iid'],
                    "Mirrored to {$url}: this project is developed on GitHub, and reviewed there. New commits here update it.\n\n"
                    . self::MIRRORED,
                ));
            }
        }
    }

    /**
     * Replays a merge request's commits under `drupal/` onto the remote's main, in a worktree of its own, and
     * pushes them as the mirror's branch: whether they applied.
     *
     * @param MergeRequest $request
     */
    private function replay(DrupalOrg $drupal, array $request, string $name, string $remote, string $branch): bool
    {
        if ($this->dryRun) {
            $this->say('Would replay !' . $request['iid'] . ' onto ' . $remote . '/main and push ' . $name . '.');

            return true;
        }
        $target = 'refs/drupalorg/target';
        $source = 'refs/drupalorg/mr-' . $request['iid'];
        $this->git(['fetch', '--quiet', '--no-tags', $remote, 'main']);
        $this->git(['fetch', '--quiet', '--no-tags', '--force', $drupal->repository(), '+' . $branch . ':' . $target]);
        $this->git([
            'fetch',
            '--quiet',
            '--no-tags',
            '--force',
            $request['source'],
            '+' . $request['branch'] . ':' . $source,
        ]);
        $base = trim($this->git(['merge-base', $target, $source]));
        $patch = $this->git(['format-patch', '--stdout', $base . '..' . $source]);
        $work =
            ('' === $this->temporary ? sys_get_temp_dir() : $this->temporary)
            . '/drupalorg-mr-'
            . $request['iid']
            . '-'
            . bin2hex(random_bytes(4));
        $this->git(['worktree', 'add', '--quiet', '--detach', $work, $remote . '/main']);
        try {
            [$status] = $this->runner->run([
                'git',
                '-C',
                $work,
                ...self::COMMITTER,
                'am',
                '--quiet',
                '-3',
                '--directory=drupal',
            ], $patch);
            if (0 !== $status) {
                $this->runner->run(['git', '-C', $work, 'am', '--abort']);

                return false;
            }
            $this->git(['-C', $work, 'push', '--quiet', '--force', $remote, 'HEAD:refs/heads/' . $name]);
            $this->say('Pushed ' . $name . '.');
        } finally {
            $this->git(['worktree', 'remove', '--force', $work]);
            $this->git(['update-ref', '-d', $target]);
            $this->git(['update-ref', '-d', $source]);
        }

        return true;
    }

    private function issues(DrupalOrg $drupal, GitHub $github): void
    {
        $issues = $drupal->issues();
        if (null === $issues) {
            $this->say('No drupal.org project: nothing to mirror.');

            return;
        }
        $mirrored = $github->mirroredIssues();
        foreach (array_reverse($issues) as $issue) {
            $marker = \sprintf(self::ISSUE, $issue['nid']);
            if (array_any($mirrored, static fn(string $body): bool => str_contains($body, $marker))) {
                continue;
            }
            $excerpt = trim((string) preg_replace('/\s+/', ' ', strip_tags($issue['body'])));
            $body = \sprintf(
                "Reported on drupal.org: %s\n\n%s\n\n---\n\nIts status on drupal.org is not synced: answer there, and link this issue's fix.\n\n%s\n",
                $issue['url'],
                '' === $excerpt
                    ? '_No description._'
                    : '> ' . mb_substr($excerpt, 0, 1500) . (mb_strlen($excerpt) > 1500 ? '…' : ''),
                $marker,
            );
            $this->write('open an issue for drupal.org #' . $issue['nid'], static fn() => $github->openIssue(
                '[drupal.org #' . $issue['nid'] . '] ' . $issue['title'],
                $body,
            ));
        }
    }

    private function closed(DrupalOrg $drupal, GitHub $github, int $number): void
    {
        $pull = $github->pull($number);
        // The merge request is the one the watcher's own branch names, and the one its marker, last in the body,
        // names too: nothing a contributor wrote decides which merge request is closed.
        $branch = [];
        $marker = [];
        if (
            1 !== preg_match('#^drupalorg/mr-(\d+)$#D', $pull['branch'], $branch)
            || 1 !== preg_match('/<!-- drupalorg-mr: (\d+) @ [0-9a-f]+ -->\s*$/', $pull['body'], $marker)
            || $branch[1] !== $marker[1]
        ) {
            $this->say('Not a mirrored merge request.');

            return;
        }
        $iid = (int) $branch[1];
        if ($drupal->writable()) {
            $message = $pull['merged']
                ? "Merged on GitHub as {$pull['url']}: it reaches this project's branch with the next split. Thank you!"
                : "Closed on GitHub without merging: {$pull['url']}.";
            $this->write('tell !' . $iid . ' and close it', static function () use ($drupal, $iid, $message): void {
                $drupal->comment($iid, $message);
                $drupal->close($iid);
            });
        }
        $this->write('delete ' . $pull['branch'], static fn() => $github->deleteBranch($pull['branch']));
    }

    private function releaseIssue(DrupalOrg $drupal, GitHub $github, string $version, string $notes): void
    {
        $contents = is_file($notes) ? file_get_contents($notes) : false;
        if (false === $contents) {
            throw new \RuntimeException('cannot read ' . $notes);
        }
        $marker = \sprintf(self::RELEASE, $version);
        if ([] !== $github->openIssuesWith($marker)) {
            return;
        }
        $body = \sprintf(
            "The tag `%s` is pushed to drupal.org. drupal.org creates a release by hand:\n\n1. Open %s and click **Add new release**.\n2. Choose the tag `%s`.\n3. Paste the notes below as its release notes, and save. Pasted as HTML, `<code>` keeps `<` and `>` as they are: drupal.org escapes a code span itself, so `&lt;` would show as written.\n4. For a new series (a new minor or major), tick it **Supported** under the project's **Administer releases**, or drupal.org shows the release as unsupported.\n5. If `drupal/README.md` changed since the last release, paste it (rendered as HTML) as the project's description, and its first paragraph as the summary: **Edit** on the project.\n\nThis issue closes once drupal.org lists the release.\n\n<details><summary>Release notes</summary>\n\n%s\n</details>\n\n%s\n",
            $version,
            $drupal->releasePage(),
            $version,
            trim($contents),
            $marker,
        );
        $this->write('open the release checklist of ' . $version, static fn() => $github->openIssue(
            'Publish the Drupal module ' . $version . ' on drupal.org',
            $body,
        ));
    }

    private function releases(DrupalOrg $drupal, GitHub $github): void
    {
        foreach ($github->openIssuesWith('<!-- drupalorg-release: ') as $issue) {
            $match = [];
            if (
                1 !== preg_match('/<!-- drupalorg-release: (\S+) -->/', $issue['body'], $match)
                || !$drupal->released($match[1])
            ) {
                continue;
            }
            $this->write('close the release checklist of ' . $match[1], static fn() => $github->closeIssue(
                $issue['number'],
                'drupal.org lists the release.',
            ));
        }
    }

    /** A contributor's text, its HTML comments shown rather than hidden: no marker of the watcher's among them. */
    private static function inert(string $text): string
    {
        return str_replace(['<!--', '-->'], ['&lt;!--', '--&gt;'], $text);
    }

    /** Writes here or there, or says what it would write. */
    private function write(string $what, \Closure $action): void
    {
        if ($this->dryRun) {
            $this->say('Would ' . $what . '.');

            return;
        }
        $action();
        $this->say(ucfirst($what) . '.');
    }

    private function say(string $line): void
    {
        fwrite($this->stdout, $line . "\n");
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        [$status, $output, $error] = $this->runner->run(['git', ...$arguments]);
        if (0 !== $status) {
            throw new \RuntimeException('git ' . implode(' ', $arguments) . ': ' . trim($error));
        }

        return $output;
    }
}
