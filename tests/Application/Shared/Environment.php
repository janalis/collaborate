<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared;

/** Reads the variables the test applications run with: PHPUnit's `<env>`, the web server's or the shell's. */
final class Environment
{
    public static function get(string $name): ?string
    {
        foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)] as $value) {
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
