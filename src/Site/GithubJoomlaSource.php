<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

use CWM\BuildTools\Dev\JoomlaInstaller;

/**
 * {@see JoomlaSource} backed by Joomla's GitHub releases, through the same
 * {@see JoomlaInstaller} `cwm-joomla-install` uses.
 */
final class GithubJoomlaSource implements JoomlaSource
{
    /**
     * @param  string|null  $packageUrl  Fetch the package from here instead of GitHub: a mirror, or a
     *                                   local file:// zip when working offline.
     */
    public function __construct(
        private readonly JoomlaInstaller $installer = new JoomlaInstaller(),
        private readonly ?string $packageUrl = null,
    ) {
    }

    public function latest(): string
    {
        return $this->installer->latest()['tag'];
    }

    public function fetch(string $version, string $path, array $tolerate = []): void
    {
        $this->installer->install($version, $path, $this->packageUrl, $tolerate);
    }
}
