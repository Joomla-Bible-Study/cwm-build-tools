<?php

declare(strict_types=1);

namespace CWM\BuildTools\Release;

/**
 * Keeps `build/versions.json` and `package.json` in lockstep with cwm-bump
 * and cwm-release writes to the XML manifests.
 *
 * Background: prior to v0.6, only manifests listed in cwm-build.config.json
 * got bumped at release time. Projects that also track a `versions.json` (for
 * `current` / `next.{patch,minor,major}` / `active_development`) or a
 * `package.json:version` had to hand-edit those after every release, and
 * forgetting was easy. Proclaim shipped three patch releases with
 * `active_development` still pointing at the previous version, which fed
 * incorrect `@since` PHPDoc tags into downstream PRs.
 *
 * The class is intentionally narrow:
 *   - updateForBump:    write the dev-side fields (active_development, package.json)
 *                       and any configured source-file version literals
 *   - updateForRelease: write the post-release fields (current, next.*, _updated),
 *                       and reopen active_development on the next patch
 *
 * Each method touches only files explicitly listed in the project's
 * `versionTracking` block. Absent block → no-op. Missing file on disk →
 * stderr warning + skip (same posture as bump.php's missing-manifest path).
 * Malformed JSON → throw (caller exits non-zero).
 *
 * `sourceFiles` is the exception to the skip-and-warn posture: a pattern that
 * matches nothing throws. A version literal living in source is invisible until
 * a consumer reads it and gets a stale answer — lib_cwmscripture's
 * LibraryVersion::VERSION sat a release behind while satisfies() told downstream
 * extensions the library was too old — so a rewrite that silently does nothing is
 * the exact failure this feature exists to remove.
 */
final class VersionTracker
{
    /**
     * @param  array{versionsJson?: string, packageJson?: string, sourceFiles?: list<array{path: string, pattern: string}>, activeDevelopment?: array{advanceOnRelease?: bool, devSuffix?: string}} $config
     *         The `versionTracking` block from cwm-build.config.json.
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array  $config,
    ) {
    }

    /**
     * Write `active_development.version` and `package.json:version` to $version.
     *
     * Called from cwm-bump when no `--component` filter is in play (subset
     * bumps shouldn't advance the project-wide development pointer).
     *
     * @return list<string> Files actually rewritten (for stdout reporting + tests).
     */
    public function updateForBump(string $version): array
    {
        $touched = [];

        if ($versionsPath = $this->resolvePath('versionsJson')) {
            if ($this->writeVersionsJsonBump($versionsPath, $version)) {
                $touched[] = $versionsPath;
            }
        }

        if ($packagePath = $this->resolvePath('packageJson')) {
            if ($this->writePackageJson($packagePath, $version)) {
                $touched[] = $packagePath;
            }

            if ($lockPath = $this->packageLockBeside($packagePath)) {
                if ($this->writePackageLock($lockPath, $version)) {
                    $touched[] = $lockPath;
                }
            }
        }

        foreach ($this->rewriteSourceFiles($version) as $path) {
            $touched[] = $path;
        }

        return $touched;
    }

    /**
     * Write `current.version`, recompute `next.{patch,minor,major}`, refresh
     * `_updated`, and reopen `active_development` on the new `next.patch`.
     *
     * A pre-release writes nothing. All three describe the stable line —
     * `current` is the last stable release, `next.*` the candidates that follow
     * it, `active_development` the cycle after this one — and a beta advances
     * none of them. See writeVersionsJsonRelease().
     *
     * Called from cwm-release step 7.
     *
     * @return list<string> Files actually rewritten.
     */
    public function updateForRelease(string $version, ?string $date = null): array
    {
        $touched = [];
        $date  ??= date('Y-m-d');

        if ($versionsPath = $this->resolvePath('versionsJson')) {
            if ($this->writeVersionsJsonRelease($versionsPath, $version, $date)) {
                $touched[] = $versionsPath;
            }
        }

        return $touched;
    }

    /**
     * Rewrite version literals held in source files.
     *
     * For hardcoded constants that ship alongside the manifest — the shape
     * `public const VERSION = '1.2.3';` — which nothing else in the toolchain
     * writes, so they drift.
     *
     * Configured as literal lines with a `{version}` placeholder rather than
     * regexes:
     *
     *     "sourceFiles": [
     *         { "path": "src/LibraryVersion.php",
     *           "pattern": "public const VERSION = '{version}';" }
     *     ]
     *
     * The pattern is escaped and only the placeholder becomes a capture, so an
     * author writes the line they can see in the file and cannot accidentally
     * turn `$` or `(` into syntax. It also means a pattern is readable in review
     * by someone who does not write regex.
     *
     * @return list<string> Files rewritten.
     *
     * @throws \RuntimeException When a configured pattern matches nothing, or
     *                          matches a version this rewrite cannot pin down.
     */
    private function rewriteSourceFiles(string $version): array
    {
        $entries = $this->config['sourceFiles'] ?? [];

        if (!is_array($entries) || $entries === []) {
            return [];
        }

        $touched = [];

        foreach ($entries as $i => $entry) {
            if (!is_array($entry) || !isset($entry['path'], $entry['pattern'])) {
                throw new \RuntimeException(
                    "versionTracking.sourceFiles[$i] must be an object with `path` and `pattern`"
                );
            }

            $relative = (string) $entry['path'];
            $pattern  = (string) $entry['pattern'];
            $absolute = $this->projectRoot . '/' . $relative;

            if (!is_file($absolute)) {
                // Unlike the JSON trackers this is fatal: the file is named
                // explicitly, so its absence is a broken config, and skipping
                // would leave a stale version shipping.
                throw new \RuntimeException(
                    "versionTracking.sourceFiles[$i] path not found: $relative"
                );
            }

            if (!str_contains($pattern, '{version}')) {
                throw new \RuntimeException(
                    "versionTracking.sourceFiles[$i] pattern must contain the {version} placeholder"
                );
            }

            $contents = (string) file_get_contents($absolute);
            $regex    = $this->patternToRegex($pattern);

            if (preg_match_all($regex, $contents, $matches) === 0) {
                throw new \RuntimeException(
                    "versionTracking.sourceFiles[$i]: pattern did not match anything in $relative\n"
                    . "  pattern: $pattern\n"
                    . '  Nothing was rewritten. Check the literal against the file — a pattern that '
                    . 'silently matches nothing is how a version drifts in the first place.'
                );
            }

            $replacement = str_replace('{version}', $version, $pattern);
            $updated     = preg_replace($regex, $this->escapeReplacement($replacement), $contents);

            if ($updated === null) {
                throw new \RuntimeException("versionTracking.sourceFiles[$i]: rewrite failed for $relative");
            }

            if ($updated === $contents) {
                echo "  $relative (no change)\n";

                continue;
            }

            file_put_contents($absolute, $updated);
            echo "  $relative → version=$version\n";
            $touched[] = $absolute;
        }

        return $touched;
    }

    /**
     * Turn a literal pattern into a regex: everything quoted, `{version}`
     * standing in for a semver-ish token.
     *
     * The token deliberately accepts pre-release and build suffixes
     * (`1.2.3-beta1`, `1.2.3-dev`) because bump.php accepts them, and stays
     * anchored to digits so a pattern cannot drift onto arbitrary text.
     */
    private function patternToRegex(string $pattern): string
    {
        $parts = array_map(
            static fn (string $part): string => preg_quote($part, '/'),
            explode('{version}', $pattern)
        );

        return '/' . implode('\\d+\\.\\d+\\.\\d+[0-9A-Za-z.\\-+]*', $parts) . '/';
    }

    /**
     * Escape a literal replacement so `$1`-style sequences in the pattern are
     * not read as backreferences by preg_replace.
     */
    private function escapeReplacement(string $replacement): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $replacement);
    }

    /**
     * Resolve a configured file's absolute path, or null when the key isn't
     * set or the file doesn't exist. Missing-file emits a stderr warning so
     * a typo in cwm-build.config.json is visible.
     */
    private function resolvePath(string $key): ?string
    {
        $relative = $this->config[$key] ?? null;

        if (!is_string($relative) || $relative === '') {
            return null;
        }

        $absolute = $this->projectRoot . '/' . $relative;

        if (!is_file($absolute)) {
            fwrite(STDERR, "Warning: versionTracking.$key path not found: $absolute (skipped)\n");
            return null;
        }

        return $absolute;
    }

    private function writeVersionsJsonBump(string $path, string $version): bool
    {
        $data = $this->readJson($path);

        $current = $data['active_development']['version'] ?? null;

        if ($current === $version) {
            echo "  $path (no change)\n";
            return false;
        }

        $data['active_development'] ??= [];
        $data['active_development']['version'] = $version;
        $data['active_development']['description'] ??= 'Use this for @since tags and migrations';

        $this->writeJson($path, $data);
        echo "  $path → active_development=$version\n";

        return true;
    }

    private function writeVersionsJsonRelease(string $path, string $version, string $date): bool
    {
        $data = $this->readJson($path);

        // Computed before the pre-release check so a malformed version still
        // throws rather than being waved through as "not stable, nothing to do".
        $nexts = $this->computeNexts($version);

        /*
         * A pre-release advances neither pointer, so it writes nothing.
         *
         * `current` is documented as the last stable release, and consumers
         * read it that way — Proclaim's upgrade harness takes it as the
         * artifact "users are on" and installs it as the baseline to upgrade
         * from. Recording 10.4.0-beta1 there hands the next upgrade test a beta
         * as the state of the world (#103).
         *
         * `next.*` follows for the same reason. It is derived from the last
         * stable release, and after 10.3.2-beta1 the next release is 10.3.2
         * itself — which is what next.patch already holds, computed from
         * 10.3.1. Advancing it to 10.3.3 would name a version that skips the
         * one being stabilised.
         *
         * `_updated` stamps when these pointers last moved, so it stays put
         * too. The GitHub release, the tag and the changelog all still record
         * that the pre-release happened; this file is not that record.
         */
        if (preg_match('/^\d+\.\d+\.\d+-/', $version) === 1) {
            echo "  $path (unchanged — $version is a pre-release; current/next track stable releases)\n";

            return false;
        }

        $needsWrite = false;

        if (($data['current']['version'] ?? null) !== $version) {
            $data['current'] ??= [];
            $data['current']['version'] = $version;
            $data['current']['description'] ??= 'Last stable release (from GitHub releases)';
            $needsWrite = true;
        }

        foreach ($nexts as $key => $value) {
            if (($data['next'][$key] ?? null) !== $value) {
                $data['next'] ??= [];
                $data['next'][$key] = $value;
                $needsWrite = true;
            }
        }

        $opened = $this->advanceActiveDevelopment($data, $version, $nexts['patch']);

        if ($opened !== null) {
            $needsWrite = true;
        }

        if (($data['_updated'] ?? null) !== $date) {
            $data['_updated'] = $date;
            $needsWrite = true;
        }

        if (!$needsWrite) {
            echo "  $path (no change)\n";
            return false;
        }

        $this->writeJson($path, $data);
        echo "  $path → current=$version, next.patch={$nexts['patch']}"
            . ($opened === null ? '' : ", active_development=$opened") . "\n";

        return true;
    }

    /**
     * Reopen `active_development` on the next patch, so the pointer devs read
     * for `@since` tags names an unreleased version again.
     *
     * Left to a human, this step is missed. `active_development` is the field
     * this class was written to keep honest, and it still went stale for four
     * Proclaim releases — most recently 10.5.10, which shipped with the pointer
     * left on 10.5.10 — because release-time only moved `current`/`next.*` and
     * the dev-side field waited on the next hand-run `cwm-bump`. Nothing fails
     * when it is stale: the build succeeds, the release publishes, and every
     * `@since` written afterwards silently names a version that is already out
     * (#153).
     *
     * Three cases leave the field alone:
     *
     *   - **Opted out** via `versionTracking.activeDevelopment.advanceOnRelease:
     *     false`, for projects that open cycles by hand. A warning still fires
     *     when the pointer is stale, so opting out of the fix is not opting out
     *     of knowing.
     *   - **Already ahead** — a minor or major cycle is open (10.6.0-dev while
     *     10.5.10 ships), and moving it back to 10.5.11 would be the same wrong
     *     `@since`, pointing the other way. Silent: this is the normal state of
     *     a project doing feature work, not a problem.
     *   - **Unparseable** — warned about and skipped rather than overwritten.
     *
     * The suffix follows the project. Proclaim runs cycles as `10.5.11-dev` and
     * that convention is worth keeping, but it is a convention rather than a
     * rule, so `devSuffix` defaults to empty and a project that wants one says
     * so. Either way `cwm-bump` clears it: the bump writes the plain release
     * version into the same field on the way out.
     *
     * Never throws. Step 8 runs after the GitHub release and the ARS publish,
     * so a fatal here would fail the script with the release already out.
     *
     * @param  array<string, mixed> $data      versions.json contents, modified in place.
     * @param  string               $released  The version just released (stable).
     * @param  string               $nextPatch The recomputed `next.patch`.
     * @return string|null The value written, or null when nothing moved.
     */
    private function advanceActiveDevelopment(array &$data, string $released, string $nextPatch): ?string
    {
        $settings = $this->config['activeDevelopment'] ?? [];
        $settings = is_array($settings) ? $settings : [];
        $existing = $data['active_development']['version'] ?? null;

        if (($settings['advanceOnRelease'] ?? true) !== true) {
            $this->warnIfActiveDevelopmentStale($existing, $released);

            return null;
        }

        if ($existing !== null) {
            $base = $this->baseVersion($existing);

            if ($base === null) {
                fwrite(
                    STDERR,
                    "Warning: active_development.version is not a version ("
                    . var_export($existing, true) . ") — left alone.\n"
                    . "  Set it by hand, or run cwm-bump, so @since tags name the cycle in progress.\n"
                );

                return null;
            }

            // A cycle is already open ahead of this release. Nothing to do,
            // and nothing to say — this is a project mid-feature-work.
            if (version_compare($base, $nextPatch, '>=')) {
                return null;
            }
        }

        $value = $nextPatch . (string) ($settings['devSuffix'] ?? '');

        $data['active_development'] ??= [];
        $data['active_development']['version'] = $value;
        $data['active_development']['description'] ??= 'Use this for @since tags and migrations';

        return $value;
    }

    /**
     * Warn when `active_development` is not ahead of the release just cut.
     *
     * The opt-out half of #153: a project that opens cycles by hand keeps that
     * workflow, but a pointer left on the released version stops being silent.
     * All four occurrences would have printed here.
     */
    private function warnIfActiveDevelopmentStale(mixed $existing, string $released): void
    {
        // No field, nothing to be stale: the project tracks current/next.* and
        // never adopted the dev-side pointer.
        if ($existing === null) {
            return;
        }

        $base = $this->baseVersion($existing);

        if ($base !== null && version_compare($base, $released, '>')) {
            return;
        }

        $shown = is_string($existing) ? $existing : var_export($existing, true);

        fwrite(
            STDERR,
            "Warning: active_development.version ($shown) is not ahead of the released $released.\n"
            . "  Every @since written from it now names a version that is already out.\n"
            . "  Open the next cycle (cwm-bump), or drop versionTracking.activeDevelopment"
            . ".advanceOnRelease to let cwm-release do it.\n"
        );
    }

    /**
     * The `X.Y.Z` head of a version, with any pre-release or build suffix
     * dropped, or null when the string does not start with one.
     *
     * `10.5.11-dev` and `10.5.11` describe the same cycle, so comparisons that
     * decide whether a cycle is already open have to see them as equal.
     */
    private function baseVersion(mixed $version): ?string
    {
        if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+/', $version, $m) !== 1) {
            return null;
        }

        return $m[0];
    }

    /**
     * The package-lock.json sitting beside a package.json, when there is one.
     *
     * @return string|null absolute path, or null when the project has no lock
     */
    private function packageLockBeside(string $packageJsonPath): ?string
    {
        $lock = \dirname($packageJsonPath) . '/package-lock.json';

        return is_file($lock) ? $lock : null;
    }

    /**
     * Carry the version into package-lock.json.
     *
     * npm records the version in two places in the lock, and nothing here used
     * to write either — so the lock drifted from package.json release after
     * release. At the v1.1.10 tag of lib_cwmscripture the manifest said 1.1.10
     * and the lock still said 1.1.8, two releases behind, having last moved in
     * an unrelated Dependabot commit (#76).
     *
     * The cost is not untidiness. npm rewrites that field the next time anyone
     * runs `npm install`, which lands as an unexplained modification in a clean
     * tree and stops the *next* release at the "working tree is not clean"
     * pre-check — a failure created by an omission several releases earlier, in
     * a file nobody edited.
     *
     * Only the two version fields are touched. The dependency tree is left
     * exactly as npm wrote it, which is the same thing `npm version` does.
     */
    private function writePackageLock(string $path, string $version): bool
    {
        $data = $this->readJson($path);

        $rootMatches = ($data['packages'][''] ?? null) === null
            || ($data['packages']['']['version'] ?? null) === $version;

        if (($data['version'] ?? null) === $version && $rootMatches) {
            echo "  $path (no change)\n";

            return false;
        }

        $data['version'] = $version;

        // lockfileVersion 2 and 3 carry it again under packages[""]; version 1
        // has no packages block at all, so only write what is already there.
        if (isset($data['packages']) && \is_array($data['packages']) && \array_key_exists('', $data['packages'])) {
            $data['packages']['']['version'] = $version;
        }

        $this->writeJson($path, $data, indent: 2);
        echo "  $path → version=$version\n";

        return true;
    }

    private function writePackageJson(string $path, string $version): bool
    {
        $data = $this->readJson($path);

        if (($data['version'] ?? null) === $version) {
            echo "  $path (no change)\n";
            return false;
        }

        $data['version'] = $version;
        $this->writeJson($path, $data, indent: 2);
        echo "  $path → version=$version\n";

        return true;
    }

    /**
     * Compute the next patch, minor, and major versions from a base semver.
     * Prerelease suffixes (`-alpha`, `-beta1`, etc.) are stripped before
     * computing — the "next" versions are always plain semver, since they
     * describe what comes after this release stabilises.
     *
     * @return array{patch: string, minor: string, major: string}
     */
    private function computeNexts(string $version): array
    {
        $base = preg_replace('/-.*$/', '', $version);

        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', (string) $base, $m)) {
            throw new \RuntimeException("Cannot compute next versions from '$version' (not semver)");
        }

        [$_, $major, $minor, $patch] = $m;

        return [
            'patch' => "$major.$minor." . ((int) $patch + 1),
            'minor' => "$major." . ((int) $minor + 1) . ".0",
            'major' => ((int) $major + 1) . ".0.0",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $raw  = file_get_contents($path);
        $data = json_decode((string) $raw, true);

        if (!is_array($data)) {
            throw new \RuntimeException("Could not parse JSON in $path");
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data, int $indent = 4): void
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
        $json  = json_encode($data, $flags);

        if ($json === false) {
            throw new \RuntimeException("Could not encode JSON for $path");
        }

        // PHP's JSON_PRETTY_PRINT is hardcoded to 4-space indent. package.json
        // convention is 2-space, so we re-indent when caller asks for it.
        if ($indent !== 4) {
            $pad  = str_repeat(' ', $indent);
            $json = preg_replace_callback(
                '/^( +)/m',
                static fn (array $m) => str_repeat($pad, strlen($m[1]) / 4),
                $json,
            );
        }

        file_put_contents($path, $json . "\n");
    }
}