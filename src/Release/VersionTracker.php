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
 *   - updateForRelease: write the post-release fields (current, next.*, _updated)
 *
 * Each method touches only files explicitly listed in the project's
 * `versionTracking` block. Absent block → no-op. Missing file on disk →
 * stderr warning + skip (same posture as bump.php's missing-manifest path).
 * Malformed JSON → throw (caller exits non-zero).
 */
final class VersionTracker
{
    /**
     * @param  array{versionsJson?: string, packageJson?: string} $config
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
        }

        return $touched;
    }

    /**
     * Write `current.version`, recompute `next.{patch,minor,major}`, refresh
     * `_updated`. Leaves `active_development` alone — that's the dev-side
     * field, updated by the next cwm-bump.
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

        $nexts = $this->computeNexts($version);

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

        if (($data['_updated'] ?? null) !== $date) {
            $data['_updated'] = $date;
            $needsWrite = true;
        }

        if (!$needsWrite) {
            echo "  $path (no change)\n";
            return false;
        }

        $this->writeJson($path, $data);
        echo "  $path → current=$version, next.patch={$nexts['patch']}\n";

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