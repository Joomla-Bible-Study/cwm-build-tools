<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * {@see CommandRunner} backed by proc_open, with the arguments passed as an
 * array so nothing is interpreted by a shell.
 */
final class ProcessRunner implements CommandRunner
{
    public function run(array $command, ?string $cwd = null, bool $stream = false, ?array $env = null): CommandResult
    {
        // proc_open() replaces the whole environment when it is given one, so add to the current one.
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env === null ? null : array_merge(getenv(), $env));

        if (!\is_resource($process)) {
            return new CommandResult(127, '', 'Could not start ' . ($command[0] ?? '(empty command)'));
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out  = '';
        $err  = '';
        $exit = -1;

        while (true) {
            $read   = [$pipes[1], $pipes[2]];
            $write  = null;
            $except = null;

            if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                foreach ($read as $pipe) {
                    $chunk = (string) fread($pipe, 8192);

                    if ($chunk === '') {
                        continue;
                    }

                    if ($pipe === $pipes[1]) {
                        $out .= $chunk;
                    } else {
                        $err .= $chunk;
                    }

                    if ($stream) {
                        fwrite($pipe === $pipes[1] ? STDOUT : STDERR, $chunk);
                    }
                }
            }

            $status = proc_get_status($process);

            if (!$status['running']) {
                // proc_get_status() reports the exit code once; proc_close() then returns -1.
                $exit = $status['exitcode'];
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);

                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        return new CommandResult($exit, $out, $err);
    }
}
