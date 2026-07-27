<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * Best-effort guess at where a CWM sibling checkout lives, extracted from
 * scripts/setup.php so the candidate logic can be tested (#32).
 *
 * Matches the common ~/GitHub/<repo> layout: siblings sit next to the
 * project under one parent directory. The composer package name's basename
 * usually matches the directory, but CWM/Joomla naming mixes dashes and
 * underscores (composer "lib-cwmscripture", directory "lib_cwmscripture"),
 * so both variants are tried.
 */
final class SiblingPathGuesser
{
    /**
     * @param  string  $parentDir    Directory the siblings would live in
     *                               (normally dirname(projectRoot)).
     * @param  string  $packageName  Composer name, e.g. "cwm/lib-cwmscripture".
     *
     * @return string|null  An existing directory, or null when no candidate
     *                      is on disk.
     */
    public function guess(string $parentDir, string $packageName): ?string
    {
        $base = basename($packageName);

        $candidates = array_unique([
            $base,
            str_replace('-', '_', $base),
            str_replace('_', '-', $base),
            strtolower($base),
        ]);

        foreach ($candidates as $name) {
            $candidate = $parentDir . '/' . $name;

            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
