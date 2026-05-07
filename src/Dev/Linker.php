<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * Symlink driver. Creates and verifies relative symlinks between a project
 * checkout and a live Joomla installation.
 *
 * Why relative: when an absolute path is baked into a symlink, it travels as
 * a broken pointer the moment the repo or Joomla tree is checked out at a
 * different prefix on a different machine (or in CI). Computing the link
 * target relative to the link's own directory keeps things portable —
 * cwmconnect's PR #88 / #89 had to undo absolute-path symlinks Proclaim's
 * scripts shipped with for the same reason.
 *
 * @phpstan-type LinkResult array{link: string, target: string, status: string, message?: string}
 */
final class Linker
{
    public function __construct(private readonly bool $verbose = false)
    {
    }

    /**
     * Create a symlink from $link → $source. Replaces an existing file,
     * directory, or stale symlink at $link.
     */
    public function link(string $source, string $link): void
    {
        if (!file_exists($source) && !is_link($source)) {
            $this->warn("Target does not exist, symlink will be broken: {$source}");
        }

        clearstatcache(true, $link);

        if (is_link($link)) {
            if (!@unlink($link)) {
                $this->warn("Failed to unlink existing symlink {$link}");
            }
        } elseif (file_exists($link)) {
            if (is_dir($link)) {
                $this->removeDirectory($link);
            } elseif (!@unlink($link)) {
                $this->warn("Failed to unlink existing file {$link}");
            }
        }

        $parent = \dirname($link);

        if (!is_dir($parent) && !mkdir($parent, 0o777, true) && !is_dir($parent)) {
            $this->error("Failed to create parent directory {$parent}");

            return;
        }

        // Normalise both ends so a symlinked prefix on one side (e.g. macOS
        // /tmp → /private/tmp) does not poison the relative-path computation.
        $realSource = realpath($source) ?: $source;
        $realParent = realpath($parent) ?: $parent;
        $relative   = $this->relativePath($realSource, $realParent);

        if ($this->verbose) {
            echo "Linking {$link} -> {$relative}\n";
        }

        if (!@symlink($relative, $link)) {
            $this->error("Failed to create symlink {$link} -> {$relative}");
            $err = error_get_last();

            if ($err !== null) {
                echo '  Details: ' . $err['message'] . "\n";
            }
        }
    }

    /**
     * Verify a single symlink against its expected target. Returns one of:
     *   ok      — link points to the right place
     *   missing — link does not exist
     *   stale   — a real file or directory sits at the link path
     *   wrong   — link points somewhere unexpected
     *   broken  — link points at a non-existent target
     *
     * @return array{link: string, target: string, status: string, message?: string}
     */
    public function check(string $source, string $link): array
    {
        clearstatcache(true, $link);

        if (!is_link($link)) {
            if (file_exists($link)) {
                return ['link' => $link, 'target' => $source, 'status' => 'stale'];
            }

            return ['link' => $link, 'target' => $source, 'status' => 'missing'];
        }

        $actual         = readlink($link);
        $resolvedActual = $actual === false
            ? false
            : (realpath($this->resolveAgainst($actual, \dirname($link))) ?: $actual);
        $resolvedTarget = realpath($source) ?: $source;

        if ($resolvedActual !== $resolvedTarget) {
            return [
                'link'    => $link,
                'target'  => $source,
                'status'  => 'wrong',
                'message' => "points to {$actual}",
            ];
        }

        if (!file_exists($link)) {
            return ['link' => $link, 'target' => $source, 'status' => 'broken'];
        }

        return ['link' => $link, 'target' => $source, 'status' => 'ok'];
    }

    public function unlink(string $link): bool
    {
        clearstatcache(true, $link);

        if (!is_link($link)) {
            return false;
        }

        return @unlink($link);
    }

    /**
     * Compute a relative path from a directory ($from) to a file or
     * directory ($target). Both inputs must resolve to absolute paths;
     * relatives are interpreted against getcwd().
     */
    public function relativePath(string $target, string $from): string
    {
        $target = $this->absolutise($target);
        $from   = $this->absolutise($from);

        $targetParts = explode('/', trim($target, '/'));
        $fromParts   = explode('/', trim($from, '/'));

        while ($targetParts !== [] && $fromParts !== [] && $targetParts[0] === $fromParts[0]) {
            array_shift($targetParts);
            array_shift($fromParts);
        }

        $up   = str_repeat('../', \count($fromParts));
        $rest = implode('/', $targetParts);
        $rel  = $up . $rest;

        return $rel === '' ? '.' : rtrim($rel, '/');
    }

    private function absolutise(string $path): string
    {
        if ($path === '' || $path[0] === '/') {
            return $path;
        }

        return rtrim(getcwd() ?: '', '/') . '/' . $path;
    }

    private function resolveAgainst(string $target, string $base): string
    {
        if ($target === '' || $target[0] === '/') {
            return $target;
        }

        return rtrim($base, '/') . '/' . $target;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($path);
    }

    private function warn(string $msg): void
    {
        echo "WARNING: {$msg}\n";
    }

    private function error(string $msg): void
    {
        fwrite(STDERR, "ERROR: {$msg}\n");
    }
}
