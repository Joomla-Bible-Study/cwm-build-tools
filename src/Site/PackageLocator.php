<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

use CWM\BuildTools\Build\DistZipResolver;

/**
 * Chooses the built zip a site command should install.
 *
 * Shared by `cwm-site-create` and `cwm-site-reset` so "which zip" is answered
 * the same way in both. It runs before anything is changed, so a bad path fails
 * at once and not after minutes of work.
 */
final class PackageLocator
{
    public function __construct(private readonly DistZipResolver $resolver = new DistZipResolver())
    {
    }

    /**
     * @param  string  $mode         "auto" (newest zip matching build.outputGlob), "none", or a path.
     * @param  string  $projectRoot  Base for a relative path.
     * @param  string  $source       Where cwm-build.config.json is read for `auto`.
     *
     * @return array{path: string|null, note: string|null}  A path, or null with the reason
     *                                                      when `auto` found nothing.
     *
     * @throws SiteException  when an explicit path does not exist
     */
    public function locate(string $mode, string $projectRoot, string $source): array
    {
        if ($mode === 'none') {
            return ['path' => null, 'note' => null];
        }

        try {
            if ($mode !== 'auto') {
                return ['path' => $this->resolver->resolveExplicit($projectRoot, $mode), 'note' => null];
            }

            $configFile = $source . '/cwm-build.config.json';
            $config     = is_file($configFile) ? json_decode((string) file_get_contents($configFile), true) : null;
            $glob       = (string) (\is_array($config) ? ($config['build']['outputGlob'] ?? '') : '');

            return ['path' => $this->resolver->resolveFromGlob($source, $glob), 'note' => null];
        } catch (\RuntimeException $e) {
            if ($mode !== 'auto') {
                throw new SiteException(str_replace('--zip path', '--package path', $e->getMessage()));
            }

            return ['path' => null, 'note' => $e->getMessage()];
        }
    }
}
