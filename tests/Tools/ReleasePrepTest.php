<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Tools;

use Janalis\Collaborate\Tools\ReleasePrep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tools/ReleasePrep.php';

#[CoversClass(ReleasePrep::class)]
final class ReleasePrepTest extends TestCase
{
    private const string CHANGELOG = <<<'MD'
        # Changelog

        Intro.

        ## [Unreleased]

        ### Added

        - A feature.

        ## [0.1.0] - 2026-01-02

        ### Added

        - The first feature.

        [Unreleased]: https://github.com/janalis/collaborate/compare/v0.1.0...HEAD
        [0.1.0]: https://github.com/janalis/collaborate/releases/tag/v0.1.0

        MD;

    private const string USAGE = 'usage: relprep.php --bump X.Y.Z|patch|minor|major [--date YYYY-MM-DD] [--notes file] [--changelog file] [--git dir] [--module composer.json]';

    /** The Drupal module's composer.json, as far as the release reads it. */
    private const string MODULE = <<<'JSON'
        {
            "name": "janalis/collaborate_drupal",
            "require": {
                "php": "^8.4",
                "janalis/collaborate": "^0.5@dev"
            }
        }

        JSON;

    private string $directory;
    private string $previous;

    /** Runs in a scratch directory: the default changelog and repository are the scratch ones, never this checkout's. */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/relprep-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->previous = (string) getcwd();
        chdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->previous);
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    /** @return iterable<string, array{string, list<string>, string}> */
    public static function versions(): iterable
    {
        yield 'patch of the latest tag' => ['patch', ['v0.1.0', 'v0.2.3', 'v0.10.1', 'other'], '0.10.2'];
        yield 'the latest tag wherever git lists it' => ['patch', ['v0.10.1', 'v0.2.3', 'v0.9.0'], '0.10.2'];
        yield 'minor' => ['minor', ['v1.2.3'], '1.3.0'];
        yield 'major' => ['major', ['v1.2.3'], '2.0.0'];
        yield 'first patch' => ['patch', [], '0.0.1'];
        yield 'first minor' => ['minor', [], '0.1.0'];
        yield 'explicit' => ['1.0.0', ['v0.9.9'], '1.0.0'];
        yield 'explicit with a v' => ['v0.2.0', ['v0.1.0'], '0.2.0'];
        yield 'tags without a v and prereleases are compared too' => [
            'patch',
            ['1.4.0', 'v2.0.0-rc.1', 'v01.0.0'],
            '1.4.1',
        ];
    }

    /** @param list<string> $tags */
    #[DataProvider('versions')]
    public function testResolvesTheNextVersion(string $bump, array $tags, string $expected): void
    {
        self::assertSame($expected, new ReleasePrep()->nextVersion($bump, $tags));
    }

    public function testRefusesAVersionThatIsNotGreater(): void
    {
        $this->expectExceptionMessage('version 0.1.0 is not greater than the latest tag v0.1.0');
        new ReleasePrep()->nextVersion('0.1.0', ['v0.1.0']);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedVersions(): iterable
    {
        yield 'two numbers' => ['1.0'];
        yield 'a leading zero' => ['01.2.3'];
        yield 'a trailing line break' => ["1.2.3\n"];
        yield 'a prerelease' => ['1.2.3-rc.1'];
    }

    #[DataProvider('malformedVersions')]
    public function testRefusesAMalformedVersion(string $version): void
    {
        $this->expectExceptionMessage('bad version "' . $version . '" (want X.Y.Z, patch, minor or major)');
        new ReleasePrep()->nextVersion($version, []);
    }

    public function testCutsTheUnreleasedSectionAndUpdatesTheLinks(): void
    {
        [$changelog, $notes] = new ReleasePrep()->cutChangelog(self::CHANGELOG, '0.2.0', '2026-10-31');

        self::assertSame("### Added\n\n- A feature.", $notes);
        self::assertSame(<<<'MD'
            # Changelog

            Intro.

            ## [Unreleased]

            ## [0.2.0] - 2026-10-31

            ### Added

            - A feature.

            ## [0.1.0] - 2026-01-02

            ### Added

            - The first feature.

            [Unreleased]: https://github.com/janalis/collaborate/compare/v0.2.0...HEAD
            [0.2.0]: https://github.com/janalis/collaborate/compare/v0.1.0...v0.2.0
            [0.1.0]: https://github.com/janalis/collaborate/releases/tag/v0.1.0

            MD, $changelog);
    }

    public function testLinksTheFirstReleaseToItsTag(): void
    {
        $first =
            "# Changelog\n\n## [Unreleased]\n\n### Added\n\n- Everything.\n\n"
            . "[Unreleased]: https://github.com/janalis/collaborate/commits/main\n";

        [$changelog, $notes] = new ReleasePrep()->cutChangelog($first, '0.1.0', '2026-10-31');

        self::assertSame("### Added\n\n- Everything.", $notes);
        self::assertSame(
            "# Changelog\n\n## [Unreleased]\n\n## [0.1.0] - 2026-10-31\n\n### Added\n\n- Everything.\n\n"
            . "[Unreleased]: https://github.com/janalis/collaborate/compare/v0.1.0...HEAD\n"
            . "[0.1.0]: https://github.com/janalis/collaborate/releases/tag/v0.1.0\n",
            $changelog,
        );
    }

    public function testKeepsAChangelogWithoutLinksOrOlderSections(): void
    {
        [$changelog] = new ReleasePrep()->cutChangelog(
            "# Changelog\n\n## [Unreleased]\n\n- One.\n",
            '1.0.0',
            '2026-10-31',
        );

        self::assertSame("# Changelog\n\n## [Unreleased]\n\n## [1.0.0] - 2026-10-31\n\n- One.\n", $changelog);
    }

    public function testCutsABodyThatFollowsTheHeadingWithoutABlankLine(): void
    {
        [$changelog, $notes] = new ReleasePrep()->cutChangelog(
            "# Changelog\n\n## [Unreleased]\n- One.\n",
            '1.0.0',
            '2026-10-31',
        );

        self::assertSame('- One.', $notes);
        self::assertSame("# Changelog\n\n## [Unreleased]\n\n## [1.0.0] - 2026-10-31\n\n- One.\n", $changelog);
    }

    /** @return iterable<string, array{string}> */
    public static function foreignLinks(): iterable
    {
        yield 'a comparison quoted in a line' => ['- See [Unreleased]: https://example.com/compare/v0.1.0...HEAD'];
        yield 'a comparison followed by text' => ['[Unreleased]: https://example.com/compare/v0.1.0...HEAD soon'];
        yield 'a history quoted in a line' => ['- See [Unreleased]: https://example.com/commits/main'];
        yield 'a history followed by text' => ['[Unreleased]: https://example.com/commits/main soon'];
    }

    /** Only a line that is wholly an [Unreleased] link is one. */
    #[DataProvider('foreignLinks')]
    public function testLeavesLinesThatAreNotTheUnreleasedLinkAlone(string $line): void
    {
        [$changelog] = new ReleasePrep()->cutChangelog(
            "# Changelog\n\n## [Unreleased]\n\n- One.\n\n## [0.1.0] - 2026-01-02\n\n" . $line . "\n",
            '0.2.0',
            '2026-10-31',
        );

        self::assertSame(
            "# Changelog\n\n## [Unreleased]\n\n## [0.2.0] - 2026-10-31\n\n- One.\n\n## [0.1.0] - 2026-01-02\n\n"
            . $line
            . "\n",
            $changelog,
        );
    }

    public function testRefusesAnEmptyUnreleasedSection(): void
    {
        $this->expectExceptionMessage('the [Unreleased] changelog section is empty');
        new ReleasePrep()->cutChangelog(
            "# Changelog\n\n## [Unreleased]\n\n## [0.1.0] - 2026-01-02\n\n- Old.\n",
            '0.2.0',
            '2026-10-31',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function headingless(): iterable
    {
        yield 'released sections only' => ["# Changelog\n\n## [0.1.0] - 2026-01-02\n"];
        yield 'a heading with more on its line' => ["# Changelog\n\n## [Unreleased] - soon\n\n- One.\n"];
    }

    #[DataProvider('headingless')]
    public function testRefusesAChangelogWithoutUnreleasedHeading(string $changelog): void
    {
        $this->expectExceptionMessage('the changelog has no "## [Unreleased]" heading');
        new ReleasePrep()->cutChangelog($changelog, '0.2.0', '2026-10-31');
    }

    public function testRunUpdatesTheChangelogWritesTheNotesAndPrintsTheOutputs(): void
    {
        $this->git('init', '-q');
        $this->git(
            '-c',
            'user.name=t',
            '-c',
            'user.email=t@example.com',
            'commit',
            '-q',
            '--allow-empty',
            '-m',
            'init',
        );
        $this->git('tag', 'v0.1.0');
        file_put_contents($this->directory . '/CHANGELOG.md', self::CHANGELOG);

        [$status, $stdout, $stderr] = $this->relprep([
            '--bump',
            'minor',
            '--date=2026-10-31',
            '--notes',
            $this->directory . '/notes.md',
            '--changelog=' . $this->directory . '/CHANGELOG.md',
            '--git',
            $this->directory,
        ]);

        self::assertSame([0, "version=0.2.0\ntag=v0.2.0\nmodule_tag=0.2.0\n", ''], [$status, $stdout, $stderr]);
        self::assertSame("### Added\n\n- A feature.\n", file_get_contents($this->directory . '/notes.md'));
        self::assertStringContainsString(
            "## [Unreleased]\n\n## [0.2.0] - 2026-10-31\n",
            (string) file_get_contents($this->directory . '/CHANGELOG.md'),
        );
    }

    public function testRunMakesTheModuleRequireTheReleasedBundle(): void
    {
        $this->git('init', '-q');
        file_put_contents($this->directory . '/CHANGELOG.md', self::CHANGELOG);
        file_put_contents($this->directory . '/composer.json', self::MODULE);

        [$status] = $this->relprep(['--bump', '0.6.0', '--module', $this->directory . '/composer.json']);

        self::assertSame(0, $status);
        self::assertSame(
            str_replace('"^0.5@dev"', '"^0.6.0"', self::MODULE),
            file_get_contents($this->directory . '/composer.json'),
        );
    }

    public function testRunDefaultsToTodayAndWritesNoNotes(): void
    {
        $this->git('init', '-q');
        file_put_contents($this->directory . '/CHANGELOG.md', self::CHANGELOG);
        [$status, $stdout] = $this->relprep(['--bump', '0.2.0']);

        self::assertSame([0, "version=0.2.0\ntag=v0.2.0\nmodule_tag=0.2.0\n"], [$status, $stdout]);
        self::assertStringContainsString(
            '## [0.2.0] - ' . gmdate('Y-m-d') . "\n",
            (string) file_get_contents($this->directory . '/CHANGELOG.md'),
        );
        self::assertFileDoesNotExist($this->directory . '/notes.md');
    }

    /** @return iterable<string, array{list<string>}> */
    public static function usageErrors(): iterable
    {
        yield 'no arguments' => [[]];
        yield 'empty bump' => [['--bump=']];
        yield 'missing value' => [['--bump']];
        yield 'unknown option' => [['--bump', 'patch', '--force', 'yes']];
        yield 'positional argument' => [['patch']];
        yield 'an option inside an argument' => [['x--bump=patch']];
        yield 'an option name with a suffix' => [['--bump-x', 'patch']];
        yield 'an option name with a line break' => [["--bump\n", 'patch']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('usageErrors')]
    public function testRunPrintsTheUsage(array $arguments): void
    {
        [$status, $stdout, $stderr] = $this->relprep($arguments);

        self::assertSame([2, '', self::USAGE . "\n"], [$status, $stdout, $stderr]);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function failures(): iterable
    {
        yield 'bad date' => [['--date', '2026-02-30'], 'relprep: bad date "2026-02-30" (want YYYY-MM-DD)'];
        yield 'not a date' => [['--date', 'tomorrow'], 'relprep: bad date "tomorrow" (want YYYY-MM-DD)'];
        yield 'a value across lines' => [
            ["--date=2026-10-31\nx"],
            "relprep: bad date \"2026-10-31\nx\" (want YYYY-MM-DD)",
        ];
        yield 'missing changelog' => [['--changelog', '{dir}/missing.md'], 'relprep: cannot read {dir}/missing.md'];
        yield 'unwritable notes' => [
            ['--notes', '{dir}/no/such/notes.md'],
            'relprep: cannot write {dir}/no/such/notes.md',
        ];
        yield 'not a repository' => [['--git', '{dir}/missing'], 'relprep: git tag: '];
        yield 'bad version' => [['--bump', 'next'], 'relprep: bad version "next"'];
        yield 'missing module' => [['--module', '{dir}/missing.json'], 'relprep: cannot read {dir}/missing.json'];
        yield 'a module that does not require the bundle' => [
            ['--module', '{dir}/other.json'],
            "relprep: the module's composer.json does not require janalis/collaborate",
        ];
    }

    /** @param list<string> $arguments */
    #[DataProvider('failures')]
    public function testRunReportsWhyTheReleaseCannotBePrepared(array $arguments, string $message): void
    {
        $this->git('init', '-q');
        file_put_contents($this->directory . '/CHANGELOG.md', self::CHANGELOG);
        file_put_contents($this->directory . '/other.json', '{"require": {"php": "^8.4"}}');
        $arguments = array_map(fn(string $argument): string => str_replace('{dir}', $this->directory, $argument), [
            '--bump',
            'patch',
            '--changelog',
            $this->directory . '/CHANGELOG.md',
            '--git',
            $this->directory,
            ...$arguments,
        ]);

        [$status, $stdout, $stderr] = $this->relprep($arguments);

        self::assertSame([1, ''], [$status, $stdout]);
        self::assertTrue(str_starts_with($stderr, str_replace('{dir}', $this->directory, $message)), $stderr);
        self::assertSame(self::CHANGELOG, file_get_contents($this->directory . '/CHANGELOG.md'));
    }

    public function testRunReportsGitsOwnError(): void
    {
        [$status, , $stderr] = $this->relprep(['--bump=patch', '--git=' . $this->directory . '/missing']);

        self::assertSame(1, $status);
        self::assertMatchesRegularExpression('/^relprep: git tag: \S.*\S\n$/s', $stderr);
        self::assertStringContainsString($this->directory . '/missing', $stderr);
    }

    public function testAWriteFailureRaisesNoWarningAndRestoresTheErrorHandler(): void
    {
        $this->git('init', '-q');
        file_put_contents($this->directory . '/CHANGELOG.md', self::CHANGELOG);
        error_clear_last();
        [$status] = $this->relprep(['--bump=patch', '--notes=' . $this->directory . '/no/such/notes.md']);
        self::assertSame(1, $status);
        self::assertNull(error_get_last());

        $handler = static fn(): bool => false;
        set_error_handler($handler);
        $this->expectException(\ValueError::class);
        try {
            $this->relprep(['--bump=patch', "--notes=notes\0.md"]);
        } finally {
            $current = set_error_handler(null);
            restore_error_handler();
            restore_error_handler();
            self::assertSame($handler, $current);
        }
    }

    public function testRunBumpsTheHighestOfTheRepositorysTags(): void
    {
        $this->git('init', '-q');
        $this->git(
            '-c',
            'user.name=t',
            '-c',
            'user.email=t@example.com',
            'commit',
            '-q',
            '--allow-empty',
            '-m',
            'init',
        );
        $this->git('tag', 'v0.1.0');
        $this->git('tag', 'v0.3.0');
        file_put_contents($this->directory . '/CHANGELOG.md', self::CHANGELOG);

        self::assertSame([0, "version=0.3.1\ntag=v0.3.1\nmodule_tag=0.3.1\n", ''], $this->relprep(['--bump', 'patch']));
    }

    public function testRunReportsAnUnwritableChangelog(): void
    {
        $this->git('init', '-q');
        file_put_contents($this->directory . '/CHANGELOG.md', self::CHANGELOG);
        chmod($this->directory . '/CHANGELOG.md', 0o444);

        [$status, , $stderr] = $this->relprep([
            '--bump=patch',
            '--changelog=' . $this->directory . '/CHANGELOG.md',
            '--git=' . $this->directory,
        ]);

        self::assertSame(1, $status);
        self::assertSame('relprep: cannot write ' . $this->directory . "/CHANGELOG.md\n", $stderr);
    }

    public function testTheEntryScriptRunsTheTool(): void
    {
        $output = [];
        $status = 0;
        exec(
            \sprintf(
                '%s %s --bump 2>&1',
                escapeshellarg(\PHP_BINARY),
                escapeshellarg(__DIR__ . '/../../tools/relprep.php'),
            ),
            $output,
            $status,
        );

        self::assertSame(2, $status);
        self::assertStringStartsWith('usage: relprep.php', implode("\n", $output));
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function relprep(array $arguments): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $status = new ReleasePrep()->run($arguments, $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);

        return [$status, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    private function git(string ...$arguments): void
    {
        $output = [];
        $status = 0;
        exec(
            'git -C '
            . escapeshellarg($this->directory)
            . ' '
            . implode(' ', array_map(escapeshellarg(...), $arguments))
            . ' 2>&1',
            $output,
            $status,
        );
        self::assertSame(0, $status, implode("\n", $output));
    }
}
