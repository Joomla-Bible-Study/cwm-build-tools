<?php

declare(strict_types=1);

namespace CWM\BuildTools\Config;

/**
 * Value object describing a Composer-installed CWM package that has declared
 * its Joomla footprint via `extra.cwm-build-tools.joomlaLinks` in its own
 * composer.json.
 *
 * Produced exclusively by InstalledPackageReader — never instantiated by
 * hand outside of tests.
 *
 * @phpstan-type JoomlaLink array{
 *     type: 'library'|'plugin'|'module'|'component',
 *     name?: string,
 *     group?: string,
 *     element?: string,
 *     client?: 'site'|'administrator'
 * }
 */
final class CwmPackage
{
    /**
     * @param list<array<string, string>> $joomlaLinks Validated tuple list.
     * @param string                      $installPath Absolute realpath of vendor install dir.
     * @param string|null                 $sourcePath  Absolute realpath of the path-repo
     *                                                 source when isPathRepo, else null.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $versionNormalized,
        public readonly array $joomlaLinks,
        public readonly string $installPath,
        public readonly bool $isPathRepo,
        public readonly ?string $sourcePath,
        public readonly ?string $reference,
    ) {
    }

    /**
     * The directory cwm-link / cwm-verify should treat as the authoritative
     * source for this package's files. For path-repo installs this is the
     * sibling checkout; for registry installs it's the vendor copy.
     */
    public function sourceRoot(): string
    {
        return $this->sourcePath ?? $this->installPath;
    }
}
