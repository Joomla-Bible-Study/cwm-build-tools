<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Runs an external command. A seam, so provisioning logic is testable without
 * Docker or DDEV installed.
 */
interface CommandRunner
{
    /**
     * @param  list<string>  $command  Program and arguments, never a shell string.
     * @param  string|null   $cwd      Working directory, or null for the current one.
     * @param  bool          $stream   Echo output as it arrives, for long-running commands.
     */
    public function run(array $command, ?string $cwd = null, bool $stream = false): CommandResult;
}
