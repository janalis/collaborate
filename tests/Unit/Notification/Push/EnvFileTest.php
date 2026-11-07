<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Notification\Push;

use Janalis\Collaborate\Notification\Push\EnvFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnvFile::class)]
final class EnvFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/collaborate-env-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testWritesANewFileItsOwnerAloneReads(): void
    {
        $path = $this->directory . '/nested/.env.local';
        EnvFile::write($path, ['A' => 'one', 'B' => 'mailto:ops@example.org']);
        self::assertSame("A=one\nB=mailto:ops@example.org\n", file_get_contents($path));
        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertSame('one', EnvFile::read($path, 'A'));
        self::assertNull(EnvFile::read($path, 'C'));
        self::assertNull(EnvFile::read($this->directory . '/missing', 'A'));
    }

    public function testReplacesWhatIsSetAndKeepsEveryOtherLine(): void
    {
        $path = $this->directory . '/.env.local';
        mkdir($this->directory);
        file_put_contents($path, "# keys\r\nexport A = 'old'\nAB=x\nB=\"two words\"\nC=three # comment\n\nD=\n");
        self::assertSame('old', EnvFile::read($path, 'A'));
        self::assertSame('two words', EnvFile::read($path, 'B'));
        self::assertSame('three', EnvFile::read($path, 'C'));
        self::assertNull(EnvFile::read($path, 'D'));
        EnvFile::write($path, ['A' => 'new', 'E' => "it's spaced"]);
        self::assertSame(
            "# keys\nA=new\nAB=x\nB=\"two words\"\nC=three # comment\n\nD=\nE='it'\\''s spaced'\n",
            file_get_contents($path),
        );
        self::assertSame('new', EnvFile::read($path, 'A'));
        self::assertSame('x', EnvFile::read($path, 'AB'));
    }

    public function testTheLastLineSettingAVariableIsWhatItReads(): void
    {
        $path = $this->directory . '/.env';
        mkdir($this->directory);
        file_put_contents($path, "A=first\nA=second");
        self::assertSame('second', EnvFile::read($path, 'A'));
        EnvFile::write($path, ['A' => 'third']);
        self::assertSame("A=third\nA=third\n", file_get_contents($path));
    }

    public function testAnEmptyFileHoldsNothing(): void
    {
        $path = $this->directory . '/.env';
        mkdir($this->directory);
        touch($path);
        self::assertNull(EnvFile::read($path, 'A'));
        EnvFile::write($path, ['A' => 'b']);
        self::assertSame("A=b\n", file_get_contents($path));
    }

    public function testRefusesAPlaceItCannotWrite(): void
    {
        mkdir($this->directory);
        touch($this->directory . '/file');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be made');
        // A directory where a file is.
        EnvFile::write($this->directory . '/file/.env', ['A' => 'b']);
    }

    public function testRefusesAFileItCannotWrite(): void
    {
        mkdir($this->directory);
        mkdir($this->directory . '/nested');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be written');
        EnvFile::write($this->directory . '/nested', ['A' => 'b']);
    }

    public function testReadsTheQuotesAroundAValueAlone(): void
    {
        $path = $this->directory . '/.env';
        mkdir($this->directory);
        file_put_contents($path, "A=x'y'\nB='a'b\nC=x\"y\"\nD=\"a\"b\nE='a'\nF=\"b\"\n");
        self::assertSame("x'y'", EnvFile::read($path, 'A'));
        self::assertSame("'a'b", EnvFile::read($path, 'B'));
        self::assertSame('x"y"', EnvFile::read($path, 'C'));
        self::assertSame('"a"b', EnvFile::read($path, 'D'));
        self::assertSame('a', EnvFile::read($path, 'E'));
        self::assertSame('b', EnvFile::read($path, 'F'));
    }

    public function testANameIsNoPattern(): void
    {
        $path = $this->directory . '/.env';
        mkdir($this->directory);
        file_put_contents($path, "AXB=one\n");
        self::assertNull(EnvFile::read($path, 'A.B'));
        self::assertNull(EnvFile::read($path, 'A/B'));
        EnvFile::write($path, ['A.B' => 'two']);
        self::assertSame("AXB=one\nA.B=two\n", file_get_contents($path));
        self::assertSame('two', EnvFile::read($path, 'A.B'));
    }

    public function testQuotesAValueEndingALine(): void
    {
        $path = $this->directory . '/.env';
        EnvFile::write($path, ['A' => "one\n"]);
        self::assertSame("A='one\n'\n", file_get_contents($path));
    }

    public function testMakesTheDirectoryReadableByEveryone(): void
    {
        $umask = umask(0o022);
        try {
            EnvFile::write($this->directory . '/nested/.env', ['A' => 'b']);
        } finally {
            umask($umask);
        }
        self::assertSame(0o755, fileperms($this->directory) & 0o777);
        self::assertSame(0o755, fileperms($this->directory . '/nested') & 0o777);
    }
}
