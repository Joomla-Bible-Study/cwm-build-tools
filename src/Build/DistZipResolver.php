<?php

declare(strict_types=1);

namespace CWM\BuildTools\Build;

/**
 * Resolve which dist zip `cwm-install-zip` deploys.
 *
 * Extracted from scripts/install-zip.php so the selection can be tested
 * (#32). Two paths: an explicit --zip argument, or the newest match of
 * `build.outputGlob`.
 *
 * Deliberate contrast with the release pipeline: lib/artifacts.sh selects
 * by VERSION and refuses ambiguity, because a release publishes to the
 * world. This resolver selects by MODIFICATION TIME, because its job is
 * iterative local testing — "install the zip I just built" — where several
 * versions legitimately sit in build/dist/ and the newest is almost always
 * the one meant. A caller that must be exact (the release harness) passes
 * --zip explicitly.
 */
final class DistZipResolver
{
    /**
     * Resolve an explicit --zip argument against the project root.
     *
     * @throws \RuntimeException when the path does not exist.
     */
    public function resolveExplicit(string $projectRoot, string $rawPath): string
    {
        $candidate = str_starts_with($rawPath, '/')
            ? $rawPath
            : $projectRoot . '/' . ltrim($rawPath, '/');

        $resolved = realpath($candidate);

        if ($resolved === false || !is_file($resolved)) {
            throw new \RuntimeException("--zip path not found: {$candidate}");
        }

        return $resolved;
    }

    /**
     * Resolve the newest zip matching `build.outputGlob`.
     *
     * @throws \RuntimeException when the glob is unset or matches nothing,
     *                           with a message that says what to do next.
     */
    public function resolveFromGlob(string $projectRoot, string $glob): string
    {
        if ($glob === '') {
            throw new \RuntimeException(
                "build.outputGlob is not set in cwm-build.config.json — cannot locate the dist zip.\n"
                . 'Either set it (e.g. "build/dist/lib_x-*.zip") or pass --zip explicitly.'
            );
        }

        $pattern = $projectRoot . '/' . ltrim($glob, '/');
        $matches = glob($pattern) ?: [];

        if ($matches === []) {
            throw new \RuntimeException(
                "No zip matched build.outputGlob '{$glob}'.\n"
                . "Run 'composer cwm-build' first, or pass --zip <path>."
            );
        }

        // Newest modification time wins — see the class docblock for why
        // this is mtime and not version.
        usort($matches, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $matches[0];
    }
}