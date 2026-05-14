<?php

/**
 * CLI entry for VersionTracker — used by cwm-bump and cwm-release.
 *
 * Reads cwm-build.config.json's `versionTracking` block and applies the
 * requested update mode to `versions.json` / `package.json`.
 *
 * Usage:
 *   php version-tracker.php --mode=bump    -v 1.2.3
 *   php version-tracker.php --mode=release -v 1.2.3
 *   php version-tracker.php --mode=release -v 1.2.3 -d 2026-05-15
 *
 * Exits 0 even when nothing was touched (no `versionTracking` block
 * configured is the common case for projects that don't opt in).
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Release/VersionTracker.php';
require_once __DIR__ . '/../src/Config/ProfileResolver.php';

$projectRoot = getcwd();
$configFile  = $projectRoot . '/cwm-build.config.json';

if (!is_file($configFile)) {
    fwrite(STDERR, "Error: cwm-build.config.json not found in $projectRoot\n");
    exit(1);
}

$opts    = getopt('v:d:', ['mode:']);
$version = $opts['v']       ?? null;
$mode    = $opts['mode']    ?? null;
$date    = $opts['d']       ?? null;

if (!$version || !$mode) {
    fwrite(STDERR, "Usage: version-tracker.php --mode=<bump|release> -v <version> [-d <date>]\n");
    exit(1);
}

if (!in_array($mode, ['bump', 'release'], true)) {
    fwrite(STDERR, "Error: --mode must be 'bump' or 'release'\n");
    exit(1);
}

$config = json_decode((string) file_get_contents($configFile), true);

if (!is_array($config)) {
    fwrite(STDERR, "Error: cwm-build.config.json is not valid JSON\n");
    exit(1);
}

$tracking = CWM\BuildTools\Config\ProfileResolver::resolve($config);

if ($tracking === null) {
    // Not opted in — silent no-op. Callers (bump.php, release.sh) expect this.
    exit(0);
}

$tracker = new CWM\BuildTools\Release\VersionTracker($projectRoot, $tracking);

try {
    $touched = $mode === 'bump'
        ? $tracker->updateForBump($version)
        : $tracker->updateForRelease($version, $date);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

if ($touched === []) {
    echo "  (no version-tracking files touched)\n";
}