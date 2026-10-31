<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tools;

/**
 * Prepares a release: resolves the new version (an explicit X.Y.Z, or a patch, minor or major bump of the
 * latest vX.Y.Z tag), turns the changelog's [Unreleased] section into that version's section, keeps the
 * comparison links at the bottom of the changelog current and writes the section as release notes.
 * {@see run()} prints `version=` and `tag=` lines for $GITHUB_OUTPUT.
 */
final class ReleasePrep
{
    public const string UNRELEASED = '## [Unreleased]';

    private const string USAGE = 'usage: relprep.php --bump X.Y.Z|patch|minor|major [--date YYYY-MM-DD] [--notes file] [--changelog file] [--git dir]';

    private const array OPTIONS = ['bump', 'date', 'notes', 'changelog', 'git'];

    /**
     * @param list<string> $arguments the command-line arguments, without the script name
     * @param resource     $stdout
     * @param resource     $stderr
     *
     * @return int 0 on success, 1 when the release cannot be prepared, 2 on a usage error
     */
    public function run(array $arguments, $stdout, $stderr): int
    {
        $options = $this->parse($arguments);
        if (null === $options || '' === ($options['bump'] ?? '')) {
            fwrite($stderr, self::USAGE . "\n");

            return 2;
        }
        try {
            $version = $this->prepare(
                $options['bump'],
                $options['date'] ?? gmdate('Y-m-d'),
                $options['notes'] ?? null,
                $options['changelog'] ?? 'CHANGELOG.md',
                $options['git'] ?? '.',
            );
        } catch (\RuntimeException $error) {
            fwrite($stderr, 'relprep: ' . $error->getMessage() . "\n");

            return 1;
        }
        fwrite($stdout, \sprintf("version=%s\ntag=v%s\n", $version, $version));

        return 0;
    }

    /** Updates the changelog, writes the notes and returns the new version. */
    private function prepare(
        string $bump,
        string $date,
        ?string $notes,
        string $changelog,
        string $gitDirectory,
    ): string {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new \RuntimeException(\sprintf('bad date "%s" (want YYYY-MM-DD)', $date));
        }
        $version = $this->nextVersion($bump, $this->tags($gitDirectory));
        $contents = is_file($changelog) ? file_get_contents($changelog) : false;
        if (false === $contents) {
            throw new \RuntimeException(\sprintf('cannot read %s', $changelog));
        }
        [$updated, $body] = $this->cutChangelog($contents, $version, $date);
        if (null !== $notes) {
            $this->write($notes, $body . "\n");
        }
        $this->write($changelog, $updated);

        return $version;
    }

    /**
     * Resolves `$bump` against the highest vX.Y.Z tag (0.0.0 when there is none); an explicit version must
     * be greater than it.
     *
     * @param list<string> $tags
     */
    public function nextVersion(string $bump, array $tags): string
    {
        $latest = [0, 0, 0];
        foreach ($tags as $tag) {
            /** @var array{int, int, int} $latest */
            $latest = max($latest, self::semver(str_starts_with($tag, 'v') ? substr($tag, 1) : $tag) ?? $latest);
        }
        $next = match ($bump) {
            'major' => [$latest[0] + 1, 0, 0],
            'minor' => [$latest[0], $latest[1] + 1, 0],
            'patch' => [$latest[0], $latest[1], $latest[2] + 1],
            default => self::semver(
                str_starts_with($bump, 'v') ? substr($bump, 1) : $bump,
            ) ?? throw new \RuntimeException(\sprintf('bad version "%s" (want X.Y.Z, patch, minor or major)', $bump)),
        };
        if ($next <= $latest) {
            throw new \RuntimeException(\sprintf(
                'version %s is not greater than the latest tag v%s',
                self::format($next[0], $next[1], $next[2]),
                self::format($latest[0], $latest[1], $latest[2]),
            ));
        }

        return self::format($next[0], $next[1], $next[2]);
    }

    /**
     * Renames the [Unreleased] section to the version's section and opens a fresh, empty [Unreleased] above
     * it; the comparison links at the bottom follow.
     *
     * @return array{string, string} the new changelog and the released section's body
     */
    public function cutChangelog(string $changelog, string $version, string $date): array
    {
        $start = strpos($changelog, "\n" . self::UNRELEASED . "\n");
        if (false === $start) {
            throw new \RuntimeException('the changelog has no "' . self::UNRELEASED . '" heading');
        }
        // The heading line, then its body (from the heading's line break) up to the next section or the links.
        ++$start;
        $bodyStart = $start + \strlen(self::UNRELEASED);
        $end = \strlen($changelog);
        $offset = $bodyStart;
        foreach (explode("\n", substr($changelog, $bodyStart)) as $line) {
            if (str_starts_with($line, '## ') || 1 === preg_match('/^\[[^\]]+\]: /', $line)) {
                $end = $offset;
                break;
            }
            $offset += \strlen($line) + 1;
        }
        $body = trim(substr($changelog, $bodyStart, $end - $bodyStart));
        if ('' === $body) {
            throw new \RuntimeException('the [Unreleased] changelog section is empty');
        }
        $heading = \sprintf('## [%s] - %s', $version, $date);
        $rest = substr($changelog, $end);
        $updated =
            substr($changelog, 0, $start)
            . self::UNRELEASED
            . "\n\n"
            . $heading
            . "\n\n"
            . $body
            . "\n"
            . ('' === $rest ? '' : "\n" . $rest);

        return [$this->links($updated, $version), $body];
    }

    /** [Unreleased] now starts at the new tag, which gets its own link from the previous one (or its tag page). */
    private function links(string $changelog, string $version): string
    {
        $match = [];
        if (1 === preg_match('/^\[Unreleased\]: (\S+)\/compare\/(\S+)\.{3}HEAD$/m', $changelog, $match)) {
            $links = \sprintf(
                "[Unreleased]: %s/compare/v%s...HEAD\n[%s]: %s/compare/%s...v%s",
                $match[1],
                $version,
                $version,
                $match[1],
                $match[2],
                $version,
            );

            return str_replace($match[0], $links, $changelog);
        }
        if (1 === preg_match('/^\[Unreleased\]: (\S+)\/commits\/\S+$/m', $changelog, $match)) {
            $links = \sprintf(
                "[Unreleased]: %s/compare/v%s...HEAD\n[%s]: %s/releases/tag/v%s",
                $match[1],
                $version,
                $version,
                $match[1],
                $version,
            );

            return str_replace($match[0], $links, $changelog);
        }

        return $changelog;
    }

    /** @return list<string> the repository's `v*` tags */
    private function tags(string $directory): array
    {
        // exec() strips each line's trailing whitespace; git prints a tag per line.
        $output = [];
        $status = null;
        exec('git -C ' . escapeshellarg($directory) . " tag --list 'v*' 2>&1", $output, $status);
        if (0 !== $status) {
            throw new \RuntimeException('git tag: ' . implode("\n", $output));
        }

        return $output;
    }

    /** Writes a file, reporting a failure as an exception rather than a warning. */
    private function write(string $path, string $contents): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            $written = file_put_contents($path, $contents);
        } finally {
            restore_error_handler();
        }
        if (false === $written) {
            throw new \RuntimeException(\sprintf('cannot write %s', $path));
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array<string, string>|null `--name value` and `--name=value` options, or null on an unknown one
     */
    private function parse(array $arguments): ?array
    {
        $options = [];
        for ($index = 0; $index < \count($arguments); ++$index) {
            $match = [];
            if (1 !== preg_match('/^--([a-z]+)(?:=(.*))?$/sD', $arguments[$index], $match)) {
                return null;
            }
            $name = $match[1];
            $value = $match[2] ?? $arguments[++$index] ?? null;
            if (!\in_array($name, self::OPTIONS, true) || null === $value) {
                return null;
            }
            $options[$name] = $value;
        }

        return $options;
    }

    /** @return array{int, int, int}|null a release version X.Y.Z without leading zeros (prereleases are not cut) */
    private static function semver(string $version): ?array
    {
        $match = [];
        if (
            1 !== preg_match('/^(?<major>0|[1-9]\d*)\.(?<minor>0|[1-9]\d*)\.(?<patch>0|[1-9]\d*)$/D', $version, $match)
        ) {
            return null;
        }

        return [(int) $match['major'], (int) $match['minor'], (int) $match['patch']];
    }

    private static function format(int $major, int $minor, int $patch): string
    {
        return $major . '.' . $minor . '.' . $patch;
    }
}
