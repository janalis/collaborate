<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tools\DrupalOrg;

/**
 * The repository's pull requests, issues and branches, through the `gh` command line: the workflow's token is
 * its credentials. A mirrored merge request's pull request and a mirrored issue are found by the markers their
 * bodies hold, so the watcher keeps no state of its own.
 *
 * @phpstan-type Pull array{number: int, body: string, url: string, branch: string, merged: bool, state: string}
 */
final class GitHub
{
    /** The label of everything mirrored from drupal.org. */
    public const string LABEL = 'drupal.org';

    public function __construct(
        private readonly Runner $runner,
        private readonly string $repository,
    ) {}

    /**
     * The open pull request of a branch, or null.
     *
     * @return Pull|null
     */
    public function pullOf(string $branch): ?array
    {
        $pulls = $this->rows([
            'pr',
            'list',
            '--state',
            'open',
            '--head',
            $branch,
            '--json',
            'number,body,url,headRefName,state',
        ]);

        return [] === $pulls ? null : self::read($pulls[0]);
    }

    /** @return Pull */
    public function pull(int $number): array
    {
        return self::read($this->json(['pr', 'view', (string) $number, '--json', 'number,body,url,headRefName,state']));
    }

    /** Opens a pull request from a branch onto main: its URL. */
    public function openPull(string $branch, string $title, string $body): string
    {
        $this->label();

        return trim($this->gh([
            'pr',
            'create',
            '--head',
            $branch,
            '--base',
            'main',
            '--title',
            $title,
            '--label',
            self::LABEL,
            '--body-file',
            '-',
        ], $body));
    }

    public function editPull(int $number, string $title, string $body): void
    {
        $this->gh(['pr', 'edit', (string) $number, '--title', $title, '--body-file', '-'], $body);
    }

    /** The bodies of the issues mirrored so far, open or closed. */
    public function mirroredIssues(): string
    {
        $bodies = '';
        foreach ($this->rows([
            'issue',
            'list',
            '--state',
            'all',
            '--label',
            self::LABEL,
            '--limit',
            '500',
            '--json',
            'body',
        ]) as $issue) {
            $bodies .= (\is_string($issue['body'] ?? null) ? $issue['body'] : '') . "\n";
        }

        return $bodies;
    }

    /**
     * The open issues whose body holds a marker.
     *
     * @return list<array{number: int, body: string}>
     */
    public function openIssuesWith(string $marker): array
    {
        $issues = [];
        foreach ($this->rows([
            'issue',
            'list',
            '--state',
            'open',
            '--label',
            self::LABEL,
            '--limit',
            '500',
            '--json',
            'number,body',
        ]) as $issue) {
            $body = \is_string($issue['body'] ?? null) ? $issue['body'] : '';
            if (str_contains($body, $marker)) {
                $issues[] = [
                    'number' => \is_int($issue['number'] ?? null) ? $issue['number'] : 0,
                    'body' => $body,
                ];
            }
        }

        return $issues;
    }

    public function openIssue(string $title, string $body): string
    {
        $this->label();

        return trim($this->gh([
            'issue',
            'create',
            '--title',
            $title,
            '--label',
            self::LABEL,
            '--body-file',
            '-',
        ], $body));
    }

    public function closeIssue(int $number, string $comment): void
    {
        $this->gh(['issue', 'close', (string) $number, '--comment', $comment]);
    }

    public function deleteBranch(string $branch): void
    {
        $this->gh(['api', '-X', 'DELETE', 'repos/' . $this->repository . '/git/refs/heads/' . $branch]);
    }

    private function label(): void
    {
        $this->gh([
            'label',
            'create',
            self::LABEL,
            '--force',
            '--color',
            '0678BE',
            '--description',
            'Mirrored from the module\'s drupal.org project',
        ]);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function json(array $arguments): array
    {
        return self::array(json_decode($this->gh($arguments), true));
    }

    /** @return array<array-key, mixed> */
    private static function array(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /**
     * The objects of a JSON list `gh` prints.
     *
     * @param list<string> $arguments
     *
     * @return list<array<array-key, mixed>>
     */
    private function rows(array $arguments): array
    {
        /** @var list<array<array-key, mixed>> */
        return array_values(array_filter($this->json($arguments), \is_array(...)));
    }

    /** @param list<string> $arguments */
    private function gh(array $arguments, ?string $input = null): string
    {
        // `gh api` names the repository in its path.
        $repository = 'api' === $arguments[0] ? [] : ['--repo', $this->repository];
        [$status, $output, $error] = $this->runner->run(['gh', ...$arguments, ...$repository], $input);
        if (0 !== $status) {
            throw new \RuntimeException('gh ' . implode(' ', $arguments) . ': ' . trim($error));
        }

        return $output;
    }

    /** @return Pull */
    private static function read(mixed $pull): array
    {
        $pull = \is_array($pull) ? $pull : [];

        return [
            'number' => \is_int($pull['number'] ?? null) ? $pull['number'] : 0,
            'body' => \is_string($pull['body'] ?? null) ? $pull['body'] : '',
            'url' => \is_string($pull['url'] ?? null) ? $pull['url'] : '',
            'branch' => \is_string($pull['headRefName'] ?? null) ? $pull['headRefName'] : '',
            'merged' => 'MERGED' === ($pull['state'] ?? null),
            'state' => \is_string($pull['state'] ?? null) ? $pull['state'] : '',
        ];
    }
}
