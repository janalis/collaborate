<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification\Push;

/**
 * A dotenv file read and written a variable at a time: what is set is replaced on its own line, what is new
 * appended, and every other line (comments included) kept as it is.
 */
final class EnvFile
{
    /** The value a file gives a variable, or null when it gives none. */
    public static function read(string $path, string $name): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $value = null;
        foreach (self::lines((string) file_get_contents($path)) as $line) {
            $match = [];
            if (
                1 === preg_match('/^\s*(?:export\s+)?' . preg_quote($name, '/') . '\s*=\s*(.*?)\s*$/D', $line, $match)
            ) {
                $value = self::unquote($match[1]);
            }
        }

        return null === $value || '' === $value ? null : $value;
    }

    /**
     * Sets variables in a file, made when missing, readable by its owner alone.
     *
     * @param array<string, string> $values
     */
    public static function write(string $path, array $values): void
    {
        $lines = is_file($path) ? self::lines((string) file_get_contents($path)) : [];
        foreach ($values as $name => $value) {
            $line = $name . '=' . self::quote($value);
            $set = false;
            foreach ($lines as $index => $existing) {
                if (1 !== preg_match('/^\s*(?:export\s+)?' . preg_quote($name, '/') . '\s*=/', $existing)) {
                    continue;
                }
                $lines[$index] = $line;
                $set = true;
            }
            if (!$set) {
                $lines[] = $line;
            }
        }
        $directory = \dirname($path);
        // A file where a directory goes, or a directory where the file goes: said rather than warned of.
        if (file_exists($directory) && !is_dir($directory) || !is_dir($directory) && !mkdir($directory, 0o755, true)) {
            throw new \RuntimeException(\sprintf('The directory "%s" could not be made.', $directory));
        }
        if (is_dir($path) || false === file_put_contents($path, implode("\n", $lines) . "\n")) {
            throw new \RuntimeException(\sprintf('The file "%s" could not be written.', $path));
        }
        chmod($path, 0o600);
    }

    /** @return list<string> */
    private static function lines(string $content): array
    {
        $lines = preg_split('/\r?\n/', rtrim($content, "\r\n"));

        return false === $lines || [''] === $lines ? [] : $lines;
    }

    /** A value is quoted when it holds what dotenv would read otherwise; base64url keys need none. */
    private static function quote(string $value): string
    {
        return 1 === preg_match('/^[A-Za-z0-9_.\/:@+-]*$/D', $value)
            ? $value
            : "'" . str_replace("'", "'\\''", $value) . "'";
    }

    private static function unquote(string $value): string
    {
        $match = [];
        // A value is a line's, which holds no line feed for `$` to stop before.
        if (1 === preg_match("/^'(.*)'$/", $value, $match) || 1 === preg_match('/^"(.*)"$/', $value, $match)) {
            return $match[1];
        }

        return (string) preg_replace('/\s+#.*$/', '', $value);
    }
}
