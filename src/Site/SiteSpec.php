<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Everything `cwm-site-create` needs to know about one site.
 */
final class SiteSpec
{
    /**
     * @param  string        $id           Short identifier, also the base of the project name.
     * @param  string        $path         Absolute host path the site is served from.
     * @param  string        $phpVersion   Major.minor, e.g. "8.3".
     * @param  int           $dbHostPort   Host port the database is published on.
     * @param  list<string>  $sourceRoots  Absolute host paths of the trees to mount.
     * @param  bool          $force        Reconfigure a site that already exists.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly string $phpVersion,
        public readonly int $dbHostPort,
        public readonly array $sourceRoots,
        public readonly bool $force = false,
    ) {
    }
}
