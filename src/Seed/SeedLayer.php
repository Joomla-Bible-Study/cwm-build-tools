<?php

declare(strict_types=1);

namespace CWM\BuildTools\Seed;

/**
 * One independently applicable, independently removable slice of seed data: a
 * script the project owns.
 */
final class SeedLayer
{
    public function __construct(
        public readonly string $name,
        public readonly string $script,
        public readonly string $description = '',
    ) {
    }
}
