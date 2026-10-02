<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\CommandResult;
use CWM\BuildTools\Site\CommandRunner;

/**
 * Records the commands it is asked to run and answers from a script, so
 * provisioning logic can be tested without Docker or DDEV.
 */
class FakeRunner implements CommandRunner
{
    /** @var list<array{command: list<string>, cwd: ?string, stream: bool}> */
    public array $calls = [];

    /**
     * @param  array<string, CommandResult>  $responses  Keyed by the first two words of the command.
     * @param  \Closure|null  $hook  Called with (command, cwd) before each answer, to simulate side effects.
     */
    public function __construct(private readonly array $responses = [], private readonly ?\Closure $hook = null)
    {
    }

    public function run(array $command, ?string $cwd = null, bool $stream = false): CommandResult
    {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd, 'stream' => $stream];

        if ($this->hook !== null) {
            ($this->hook)($command, $cwd);
        }

        $key = implode(' ', \array_slice($command, 0, 2));

        return $this->responses[$key] ?? new CommandResult(0);
    }

    /**
     * @return list<string>  Each recorded command as one string.
     */
    public function commandLines(): array
    {
        return array_map(static fn (array $c): string => implode(' ', $c['command']), $this->calls);
    }
}
