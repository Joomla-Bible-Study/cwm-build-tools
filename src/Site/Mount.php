<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * One bind mount from the host into a site's web container.
 */
final class Mount
{
    /**
     * @param  string  $host       Absolute path on the host.
     * @param  string  $container  Absolute path inside the container.
     * @param  string  $reason     Why the mount exists, for plan output and docs.
     */
    public function __construct(
        public readonly string $host,
        public readonly string $container,
        public readonly string $reason,
    ) {
    }
}
