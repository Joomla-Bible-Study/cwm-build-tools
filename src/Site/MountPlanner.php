<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Decides where a project's source trees must be mounted inside a site
 * container so the symlinks `cwm-link` writes on the host still resolve there.
 *
 * `Linker::link()` writes relative links, resolved against the host layout. A
 * relative link climbs a fixed number of directories and then descends, so it
 * only resolves in the container when the container's directories line up with
 * the host's at the point where the link turns around: the nearest common
 * ancestor of the site and the source.
 *
 * The site is always served from {@see self::DOCROOT}. Climbing as many levels
 * as the site sits below that ancestor on the host lands on a single container
 * directory; the source belongs beneath it, at the same relative path. With the
 * site at `<root>/Sites/x` and the source at `<root>/GitHub/y`, the site is two
 * levels below `<root>`, two levels above DOCROOT is `/var`, and the source is
 * mounted at `/var/GitHub/y`.
 *
 * Each site and source is also mounted at its own host path. Scripts that
 * hardcode a host path, and absolute links, then work unchanged in the
 * container.
 */
final class MountPlanner
{
    /** Where the container serves the site from. */
    public const DOCROOT = '/var/www/html';

    /**
     * @param  string        $sitePath     Absolute host path of the Joomla site.
     * @param  list<string>  $sourceRoots  Absolute host paths of the source trees.
     *
     * @return list<Mount>
     *
     * @throws \InvalidArgumentException  when a path is not absolute, a source
     *                                    contains the site, or the site sits too
     *                                    deep below its common ancestor with a
     *                                    source for a container path to exist.
     */
    public function plan(string $sitePath, array $sourceRoots): array
    {
        $site      = $this->segments($sitePath);
        $docroot   = $this->segments(self::DOCROOT);
        $mounts    = [];
        $seen      = [];

        $add = static function (Mount $mount) use (&$mounts, &$seen): void {
            $key = $mount->host . "\0" . $mount->container;

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $mounts[]   = $mount;
            }
        };

        $add(new Mount($this->join($site), $this->join($site), 'site at its host path, for scripts that hardcode it'));

        foreach ($sourceRoots as $sourceRoot) {
            $source   = $this->segments($sourceRoot);
            $ancestor = $this->commonPrefix($site, $source);

            if (\count($ancestor) === \count($source)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Source "%s" contains the site "%s". Keep the site outside the source tree.',
                    $this->join($source),
                    $this->join($site)
                ));
            }

            $add(new Mount($this->join($source), $this->join($source), 'source at its host path, for absolute links and hardcoded paths'));

            // A source inside the site is already visible through the site mount.
            if (\count($ancestor) === \count($site)) {
                continue;
            }

            $climb = \count($site) - \count($ancestor);

            if ($climb > \count($docroot)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Site "%s" sits %d levels below its common ancestor with "%s", but the container '
                    . 'serves it only %d levels from the root. Move the site closer to the source.',
                    $this->join($site),
                    $climb,
                    $this->join($source),
                    \count($docroot)
                ));
            }

            $anchor   = \array_slice($docroot, 0, \count($docroot) - $climb);
            $relative = \array_slice($source, \count($ancestor));

            $add(new Mount(
                $this->join($source),
                $this->join(array_merge($anchor, $relative)),
                'source where the relative symlinks written on the host resolve'
            ));
        }

        return $mounts;
    }

    /**
     * @return list<string>
     */
    private function segments(string $path): array
    {
        if ($path === '' || $path[0] !== '/') {
            throw new \InvalidArgumentException(\sprintf('Path "%s" must be absolute.', $path));
        }

        return array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @param  list<string>  $segments
     */
    private function join(array $segments): string
    {
        return '/' . implode('/', $segments);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     *
     * @return list<string>
     */
    private function commonPrefix(array $a, array $b): array
    {
        $common = [];

        foreach ($a as $i => $segment) {
            if (!isset($b[$i]) || $b[$i] !== $segment) {
                break;
            }

            $common[] = $segment;
        }

        return $common;
    }
}
