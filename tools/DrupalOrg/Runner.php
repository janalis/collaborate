<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tools\DrupalOrg;

/** Runs `git` and `gh`. */
interface Runner
{
    /**
     * @param list<string> $command
     *
     * @return array{int, string, string} the exit status, the output and the error output
     */
    public function run(array $command, ?string $input = null): array;
}
