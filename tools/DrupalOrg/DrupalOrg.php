<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tools\DrupalOrg;

/**
 * The module's project on drupal.org: its merge requests on git.drupalcode.org (GitLab's API, read without a
 * token, written with one), and its issues and releases on drupal.org (its read-only `api-d7`).
 *
 * @phpstan-type MergeRequest array{iid: int, title: string, description: string, sha: string, author: string, url: string, source: string, branch: string}
 * @phpstan-type Issue array{nid: int, title: string, body: string, url: string}
 */
final class DrupalOrg
{
    public const string GITLAB = 'https://git.drupalcode.org';

    public const string SITE = 'https://www.drupal.org';

    public function __construct(
        private readonly Http $http,
        private readonly string $project = 'collaborate',
        #[\SensitiveParameter]
        private readonly string $token = '',
    ) {}

    /** The project's git repository, which the split pushes to and merge requests target. */
    public function repository(): string
    {
        return self::GITLAB . '/project/' . $this->project . '.git';
    }

    /** Whether the token that writes to merge requests is set. */
    public function writable(): bool
    {
        return '' !== $this->token;
    }

    /**
     * The open merge requests targeting a branch, with the repository of each one's source branch (its issue
     * fork). Null when the project does not exist on git.drupalcode.org.
     *
     * @return list<MergeRequest>|null
     */
    public function mergeRequests(string $branch): ?array
    {
        $response = $this->gitlab(
            'GET',
            '/merge_requests?state=opened&per_page=100&target_branch=' . rawurlencode($branch),
        );
        if (404 === $response[0]) {
            return null;
        }
        $merges = [];
        foreach (self::list($response[1]) as $request) {
            $source = $this->get(self::GITLAB . '/api/v4/projects/' . self::int($request['source_project_id'] ?? null));
            $merges[] = [
                'iid' => self::int($request['iid'] ?? null),
                'title' => self::string($request['title'] ?? null),
                'description' => self::string($request['description'] ?? null),
                'sha' => self::string($request['sha'] ?? null),
                'author' => self::string(
                    \is_array($request['author'] ?? null) ? $request['author']['username'] ?? null : null,
                ),
                'url' => self::string($request['web_url'] ?? null),
                'source' => self::string($source['http_url_to_repo'] ?? null),
                'branch' => self::string($request['source_branch'] ?? null),
            ];
        }

        return $merges;
    }

    /** Whether a merge request has a note whose body holds `$marker`. */
    public function noted(int $iid, string $marker): bool
    {
        foreach (self::list($this->gitlab('GET', '/merge_requests/' . $iid . '/notes?per_page=100')[1]) as $note) {
            if (str_contains(self::string($note['body'] ?? null), $marker)) {
                return true;
            }
        }

        return false;
    }

    public function comment(int $iid, string $body): void
    {
        $this->write('POST', '/merge_requests/' . $iid . '/notes', ['body' => $body]);
    }

    public function close(int $iid): void
    {
        $this->write('PUT', '/merge_requests/' . $iid, ['state_event' => 'close']);
    }

    /**
     * The project's latest issues, the newest first. Null when the project does not exist on drupal.org.
     *
     * @return list<Issue>|null
     */
    public function issues(): ?array
    {
        $nid = $this->nid();
        if (null === $nid) {
            return null;
        }
        $page = $this->get(
            self::SITE
            . '/api-d7/node.json?type=project_issue&field_project='
            . $nid
            . '&sort=nid&direction=DESC&limit=50',
        );
        $issues = [];
        foreach (self::list($page['list'] ?? null) as $issue) {
            $nid = self::int($issue['nid'] ?? null);
            $issues[] = [
                'nid' => $nid,
                'title' => self::string($issue['title'] ?? null),
                'body' => self::string(\is_array($issue['body'] ?? null) ? $issue['body']['value'] ?? null : null),
                'url' => self::SITE . '/node/' . $nid,
            ];
        }

        return $issues;
    }

    /** Whether drupal.org lists a release of the project at that version. */
    public function released(string $version): bool
    {
        $nid = $this->nid();
        if (null === $nid) {
            return false;
        }
        $page = $this->get(
            self::SITE
                . '/api-d7/node.json?type=project_release&field_release_project='
                . $nid
                . '&field_release_version='
                . rawurlencode($version),
        );

        return [] !== self::list($page['list'] ?? null);
    }

    /** The page where a maintainer creates the release of a pushed tag. */
    public function releasePage(): string
    {
        return self::SITE . '/project/' . $this->project . '/releases';
    }

    /** The project's node id, by its machine name; null when drupal.org has no such project. */
    private function nid(): ?int
    {
        $page = $this->get(self::SITE . '/api-d7/node.json?field_project_machine_name=' . rawurlencode($this->project));
        $projects = self::list($page['list'] ?? null);

        return [] === $projects ? null : self::int($projects[0]['nid'] ?? null);
    }

    /** @return array<array-key, mixed> a GET's decoded body, empty when it is not a JSON object or list */
    private function get(string $url): array
    {
        return self::array($this->http->request('GET', $url)[1]);
    }

    /** @return array<array-key, mixed> */
    private static function array(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /**
     * @param array<string, string> $form
     *
     * @return array{int, mixed}
     */
    private function gitlab(string $method, string $path, array $form = []): array
    {
        return $this->http->request(
            $method,
            self::GITLAB . '/api/v4/projects/' . rawurlencode('project/' . $this->project) . $path,
            $this->writable() ? ['PRIVATE-TOKEN' => $this->token] : [],
            $form,
        );
    }

    /** @param array<string, string> $form */
    private function write(string $method, string $path, array $form): void
    {
        [$status] = $this->gitlab($method, $path, $form);
        if ($status >= 300) {
            throw new \RuntimeException(\sprintf('%s %s: HTTP %d', $method, $path, $status));
        }
    }

    /** @return list<array<array-key, mixed>> */
    private static function list(mixed $value): array
    {
        /** @var list<array<array-key, mixed>> */
        return array_values(array_filter(self::array($value), \is_array(...)));
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
