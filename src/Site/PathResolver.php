<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Resolves a path that may not exist yet to the form `Linker` will see.
 *
 * `Linker::link()` writes relative links from `realpath()`ed ends. A site path
 * typed through a symlink (macOS `/var` is `/private/var`, and a Sites folder
 * is often linked onto another volume) would otherwise share no ancestor with
 * the real source path, and the mounts would be planned for a layout that does
 * not exist.
 */
final class PathResolver
{
    /**
     * Absolute, symlink-free form of $path. Segments that do not exist yet are
     * kept as written and appended to the resolved deepest parent that does.
     */
    public function canonical(string $path, ?string $cwd = null): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Path must not be empty.');
        }

        if ($path[0] !== '/') {
            $path = rtrim($cwd ?? (getcwd() ?: '/'), '/') . '/' . $path;
        }

        $missing = [];
        $probe   = rtrim($path, '/');

        while ($probe !== '' && !file_exists($probe)) {
            array_unshift($missing, basename($probe));
            $probe = \dirname($probe);

            if ($probe === '/') {
                break;
            }
        }

        $real = $probe === '' || $probe === '/' ? '/' : realpath($probe);

        if ($real === false) {
            throw new \InvalidArgumentException(\sprintf('Cannot resolve "%s".', $path));
        }

        $segments = array_filter(
            array_merge(explode('/', $real), $missing),
            static fn (string $s): bool => $s !== '' && $s !== '.'
        );

        $out = [];

        foreach ($segments as $segment) {
            if ($segment === '..') {
                array_pop($out);

                continue;
            }

            $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }
}
