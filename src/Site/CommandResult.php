<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * The outcome of one external command.
 */
final class CommandResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }
}
