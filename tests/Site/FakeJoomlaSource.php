<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\JoomlaSource;

/**
 * A {@see JoomlaSource} that records what it was asked for and touches nothing.
 */
final class FakeJoomlaSource implements JoomlaSource
{
    /** @var list<array{version: string, path: string, tolerate: list<string>}> */
    public array $fetched = [];

    public ?\Throwable $failWith = null;

    public function __construct(private readonly string $latest = '6.1.4')
    {
    }

    public function latest(): string
    {
        return $this->latest;
    }

    public function fetch(string $version, string $path, array $tolerate = []): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->fetched[] = ['version' => $version, 'path' => $path, 'tolerate' => $tolerate];
    }
}
