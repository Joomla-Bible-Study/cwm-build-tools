<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Deletes a site's folder, and refuses anything that is not plainly one this
 * tool created.
 *
 * This is the one place the tool deletes files recursively, so the cost of a
 * mistake is high and the rules are deliberately blunt. The sharpest hazard is
 * specific to linked sites: after `cwm-link`, a dev site holds symlinks into the
 * developer's source tree. A removal that followed them would delete the
 * repository. Links are unlinked and never entered.
 */
final class SafeRemover
{
    /**
     * Files only this tool writes into a site; a folder without them is not
     * one of ours.
     */
    private const FINGERPRINT = ['.ddev/config.yaml', '.ddev/' . DdevConfig::COMPOSE_OVERRIDE];

    /**
     * @param  string  $home  The user's home directory, which must never be removed.
     */
    public function __construct(private readonly string $home = '')
    {
    }

    /**
     * Throw unless $path may be removed.
     *
     * @param  string  $projectRoot  The project the tool is run from; it must not be
     *                               inside, or the same as, the folder being removed.
     *
     * @throws SiteException  naming the rule that refused it
     */
    public function assertRemovable(string $path, string $projectRoot): void
    {
        $resolver = new PathResolver();
        $real     = $resolver->canonical($path);
        $root     = $resolver->canonical($projectRoot);
        $segments = array_values(array_filter(explode('/', $real), static fn (string $s): bool => $s !== ''));

        if (\count($segments) < 3) {
            throw new SiteException(sprintf('Refusing to remove %s: it is too close to the filesystem root to be a site folder.', $real));
        }

        if ($this->home !== '' && $real === $resolver->canonical($this->home)) {
            throw new SiteException(sprintf('Refusing to remove %s: that is your home directory.', $real));
        }

        if ($root === $real || str_starts_with($root . '/', $real . '/')) {
            throw new SiteException(sprintf('Refusing to remove %s: your project (%s) is inside it.', $real, $root));
        }

        if (!is_dir($real)) {
            throw new SiteException(sprintf('%s is not a directory.', $real));
        }

        if (file_exists($real . '/.git')) {
            throw new SiteException(sprintf('Refusing to remove %s: it contains a .git entry, so it is a repository, not a site.', $real));
        }

        foreach (self::FINGERPRINT as $required) {
            if (!is_file($real . '/' . $required)) {
                throw new SiteException(sprintf(
                    'Refusing to remove %s: it has no %s, so cwm-site-create did not make it.',
                    $real,
                    $required
                ));
            }
        }
    }

    /**
     * What removing $path would take, without taking it.
     *
     * @return array{files: int, directories: int, links: int, linkTargets: list<string>}
     */
    public function survey(string $path): array
    {
        $stats = ['files' => 0, 'directories' => 0, 'links' => 0, 'linkTargets' => []];
        $this->walk($path, $stats, false);
        $stats['linkTargets'] = array_values(array_unique($stats['linkTargets']));
        sort($stats['linkTargets']);

        return $stats;
    }

    /**
     * Remove $path and everything in it, unlinking symlinks without following them.
     *
     * Call {@see assertRemovable()} first; this does not repeat those checks.
     *
     * @return array{files: int, directories: int, links: int, linkTargets: list<string>}
     *
     * @throws SiteException  listing what could not be removed
     */
    public function remove(string $path): array
    {
        $stats = ['files' => 0, 'directories' => 0, 'links' => 0, 'linkTargets' => []];
        $this->walk($path, $stats, true);

        if (is_dir($path) && !@rmdir($path)) {
            throw new SiteException(sprintf('Could not remove %s (it may not be empty, or permission was denied).', $path));
        }

        $stats['directories']++;
        $stats['linkTargets'] = array_values(array_unique($stats['linkTargets']));
        sort($stats['linkTargets']);

        return $stats;
    }

    /**
     * @param  array{files: int, directories: int, links: int, linkTargets: list<string>}  $stats
     */
    private function walk(string $dir, array &$stats, bool $delete): void
    {
        $failed = [];

        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $path = $entry->getPathname();

            // A link is checked first, before is_dir(): is_dir() follows it.
            if (is_link($path)) {
                $stats['links']++;
                $stats['linkTargets'][] = (string) readlink($path);

                if ($delete && !@unlink($path)) {
                    $failed[] = $path;
                }

                continue;
            }

            if (is_dir($path)) {
                $this->walk($path, $stats, $delete);

                if ($delete && !@rmdir($path)) {
                    $failed[] = $path;
                }

                $stats['directories']++;

                continue;
            }

            $stats['files']++;

            if ($delete && !@unlink($path)) {
                $failed[] = $path;
            }
        }

        if ($failed !== []) {
            throw new SiteException(sprintf(
                "Could not remove %d item(s), starting with:\n  %s",
                \count($failed),
                implode("\n  ", \array_slice($failed, 0, 5))
            ));
        }
    }
}
