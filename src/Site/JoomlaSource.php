<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Where a Joomla release comes from. A seam, so the install stage is testable
 * without the network.
 */
interface JoomlaSource
{
    /**
     * The newest stable Joomla version.
     */
    public function latest(): string;

    /**
     * Put the release's files into $path, leaving the names in $tolerate alone.
     *
     * @param  list<string>  $tolerate
     */
    public function fetch(string $version, string $path, array $tolerate = []): void;
}
