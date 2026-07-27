<?php

declare(strict_types=1);

namespace CWM\BuildTools\Build;

use RuntimeException;

/**
 * Rewrites the version and creation date in a Joomla extension manifest.
 *
 * Lifted out of scripts/bump.php so it can be tested — see issue #32. What this
 * writes ends up in every shipped manifest, and a mistake is not noticed until
 * a site refuses to update or updates to the wrong version, so it is a poor
 * candidate for the "scripts are untestable" status quo.
 *
 * Deliberately string-based rather than DOM-based. Joomla manifests are hand-
 * edited XML carrying comments, ordering and whitespace that maintainers care
 * about, and a DOM round-trip reformats all three. Rewriting the two elements
 * in place changes exactly what was asked for and leaves the rest byte-identical.
 */
final class ManifestVersionWriter
{
    /** Semver, optionally with a pre-release suffix: 1.2.3, 1.2.3-beta1. */
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/';

    /**
     * Whether a string is acceptable as a release version.
     *
     * The bumper refuses anything else rather than writing it, because a
     * malformed version reaches every manifest before anyone notices, and
     * Joomla's update system compares versions rather than validating them.
     */
    public static function isValidVersion(string $version): bool
    {
        return preg_match(self::VERSION_PATTERN, $version) === 1;
    }

    /**
     * Return the manifest content with version and creationDate rewritten.
     *
     * Only the first occurrence of each element is replaced. A Joomla manifest
     * declares its own version once, near the top, and later matches would
     * belong to something else — an update-server block, or a nested package
     * child. Replacing all of them would silently rewrite those too.
     *
     * A null date leaves creationDate alone, for callers that want to bump a
     * version without claiming the manifest was authored today.
     *
     * @return string The rewritten content; identical to the input when neither
     *                element is present.
     */
    public static function rewrite(string $content, string $version, ?string $date = null): string
    {
        $content = preg_replace(
            '~<version>[^<]*</version>~',
            '<version>' . $version . '</version>',
            $content,
            1
        ) ?? $content;

        if ($date === null) {
            return $content;
        }

        return preg_replace(
            '~<creationDate>[^<]*</creationDate>~',
            '<creationDate>' . $date . '</creationDate>',
            $content,
            1
        ) ?? $content;
    }

    /**
     * Rewrite a manifest on disk.
     *
     * Returns false when the content would be unchanged, so the caller can say
     * "no change" rather than reporting a write that did not happen. An
     * unreadable path throws: a manifest listed in the config but missing is a
     * configuration error, and skipping it quietly would ship one extension at
     * the old version.
     *
     * @throws RuntimeException When the file cannot be read or written.
     */
    public static function rewriteFile(string $path, string $version, ?string $date = null): bool
    {
        $original = @file_get_contents($path);

        if ($original === false) {
            throw new RuntimeException("Could not read $path");
        }

        $updated = self::rewrite($original, $version, $date);

        if ($updated === $original) {
            return false;
        }

        if (@file_put_contents($path, $updated) === false) {
            throw new RuntimeException("Could not write $path");
        }

        return true;
    }
}
