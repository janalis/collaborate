<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tools\DrupalOrg;

/**
 * {@see Runner} with proc_open, in the working directory.
 *
 * @infection-ignore-all stream_get_contents() returns false only on a stream it cannot read: the casts that
 *                       turn that into '' cannot be told apart from the pipes this opens.
 */
final class ProcessRunner implements Runner
{
    public function run(array $command, ?string $input = null): array
    {
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        // proc_open() refuses an empty command; any other starts a process, which reports its own failure.
        \assert(\is_resource($process));
        /** @var array{resource, resource, resource} $pipes */
        fwrite($pipes[0], $input ?? '');
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }
}
