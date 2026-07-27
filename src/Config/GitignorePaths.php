<?php

declare(strict_types=1);

namespace CWM\BuildTools\Config;

/**
 * Derives the generated-file paths `cwm-sync-configs` writes into a consumer's
 * .gitignore.
 *
 * Extracted from scripts/sync-configs.php for #32. Getting these wrong is
 * quiet in both directions: too narrow and build output gets committed, too
 * broad and hand-written files stop being tracked. Proclaim has already been
 * bitten by the neighbouring `OUTPUT_DIR` mismatch — built assets landing
 * somewhere the site did not read from, which presents as stale files rather
 * than an error.
 */
final class GitignorePaths
{
    /**
     * Where the build writes its artifacts.
     *
     * Derived from `build.outputGlob` by taking its directory, because that is
     * already declared and a second setting would be a second thing to keep in
     * step. `gitignore.outputPaths` overrides it for projects whose output does
     * not sit in one place.
     *
     * @param   array<string, mixed>  $config
     *
     * @return  list<string>
     */
    public static function outputPaths(array $config): array
    {
        $explicit = $config['gitignore']['outputPaths'] ?? null;

        if (is_array($explicit)) {
            return array_values(array_map('strval', $explicit));
        }

        $glob = (string) ($config['build']['outputGlob'] ?? '');

        if ($glob === '') {
            return ['/build/dist/'];
        }

        $dir = trim(\dirname($glob), '/.');

        return $dir === '' ? ['/build/dist/'] : ['/' . $dir . '/'];
    }

    /**
     * Minified media output, ignored so built assets are not committed.
     *
     * Auto-derivation is limited to libraries and components, the two types
     * whose name maps cleanly onto a single `/media/<x>/` directory by Joomla
     * convention. A package owns no media directory of its own, and plugins and
     * modules have no generic one, so guessing for those would ignore paths
     * that do not exist — or worse, paths that do and are hand-written.
     *
     * That conservatism has a real cost worth knowing about: `pkg_proclaim` is
     * a package wrapper around a component that *does* own /media/, so it must
     * set `gitignore.mediaPaths` explicitly. The override exists precisely
     * because the default declines to guess.
     *
     * @param   array<string, mixed>  $config
     * @param   string                $name    Extension name, e.g. com_example.
     * @param   string                $type    Extension type from the config.
     *
     * @return  list<string>
     */
    public static function mediaPaths(array $config, string $name, string $type): array
    {
        $explicit = $config['gitignore']['mediaPaths'] ?? null;

        if (is_array($explicit)) {
            return array_values(array_map('strval', $explicit));
        }

        if (!\in_array($type, ['library', 'component'], true)) {
            return [];
        }

        $stripped = preg_replace('/^(lib_|com_)/', '', $name) ?? $name;

        return [
            "/media/{$stripped}/js/*.min.js",
            "/media/{$stripped}/js/*.min.js.map",
            "/media/{$stripped}/css/*.min.css",
            "/media/{$stripped}/css/*.min.css.map",
        ];
    }
}
