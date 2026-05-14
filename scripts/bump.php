<?php

/**
 * Generic multi-manifest version bumper.
 *
 * Reads cwm-build.config.json from the current working directory and writes
 * the new version into every manifest path under manifests.extensions[]
 * plus manifests.package (when present).
 *
 * Usage:
 *   php bump.php -v 1.2.3
 *   php bump.php -v 1.2.3 --component plugin    # bump only one component type
 *   php bump.php -v 1.2.3 -d "2026-05-15"       # override creationDate
 *
 * Each manifest's <version> element is rewritten in place. <creationDate>,
 * if present, is updated to today (or the value of -d).
 *
 * For the package wrapper, only manifests.package is touched.
 * For sub-extensions, ONLY manifests with the same `cadence` are bumped:
 *   - "package": only bumps when wrapping is rebuilt (default for the package manifest itself)
 *   - "library", "plugin", "module", "component", etc.: only bumps when that
 *     specific extension's code changes — drives whether bump.php touches it.
 *
 * In v1, bump.php bumps every manifest listed unless --component is specified.
 * Future versions may consult a "cadence" field per manifest to keep
 * components versioned independently as a default.
 *
 * @license GPL-2.0-or-later
 */

require_once __DIR__ . '/../src/Release/VersionTracker.php';
require_once __DIR__ . '/../src/Config/ProfileResolver.php';

$projectRoot = getcwd();
$configFile  = $projectRoot . '/cwm-build.config.json';

if (!is_file($configFile)) {
    fwrite(STDERR, "Error: cwm-build.config.json not found in $projectRoot\n");
    exit(1);
}

$opts    = getopt('v:c:d:', ['component:']);
$version = $opts['v'] ?? null;
$only    = $opts['component'] ?? $opts['c'] ?? null;
$date    = $opts['d'] ?? date('Y-m-d');

if (!$version) {
    fwrite(STDERR, "Usage: bump.php -v <version> [--component <type>] [-d <date>]\n");
    exit(1);
}

if (!preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/', $version)) {
    fwrite(STDERR, "Error: Version '$version' does not look like semver (e.g., 1.2.3 or 1.2.3-beta1)\n");
    exit(1);
}

$config = json_decode(file_get_contents($configFile), true);

if (!$config) {
    fwrite(STDERR, "Error: cwm-build.config.json is not valid JSON\n");
    exit(1);
}

$bumped = 0;

// Package wrapper manifest
if (!empty($config['manifests']['package'])) {
    $path = $projectRoot . '/' . $config['manifests']['package'];

    if ($only === null || $only === 'package') {
        if (!is_file($path)) {
            fwrite(STDERR, "Warning: package manifest not found: $path (skipped)\n");
        } else {
            bumpManifest($path, $version, $date);
            $bumped++;
        }
    }
}

// Sub-extension manifests
foreach ($config['manifests']['extensions'] ?? [] as $ext) {
    $type = $ext['type'] ?? '';
    $path = $projectRoot . '/' . ($ext['path'] ?? '');

    if (!$path || !is_file($path)) {
        fwrite(STDERR, "Warning: manifest not found: $path (skipped)\n");
        continue;
    }

    if ($only !== null && $only !== $type) {
        continue;
    }

    bumpManifest($path, $version, $date);
    $bumped++;
}

echo "Bumped $bumped manifest(s) to $version (date: $date)\n";

// Sync version-tracking files (versions.json, package.json) when configured.
// Skipped for --component subset bumps, which advance a single extension type
// without touching the project-wide development pointer.
$tracking = CWM\BuildTools\Config\ProfileResolver::resolve($config);

if ($only === null && $tracking !== null) {
    $tracker = new CWM\BuildTools\Release\VersionTracker($projectRoot, $tracking);
    $touched = $tracker->updateForBump($version);

    if ($touched !== []) {
        echo "Updated " . count($touched) . " version-tracking file(s)\n";
    }
}

/**
 * Rewrite <version> and (if present) <creationDate> in a Joomla extension manifest.
 */
function bumpManifest(string $path, string $version, string $date): void
{
    $content = file_get_contents($path);

    if ($content === false) {
        throw new RuntimeException("Could not read $path");
    }

    $original = $content;

    $content = preg_replace(
        '~<version>[^<]*</version>~',
        '<version>' . $version . '</version>',
        $content,
        1
    );

    $content = preg_replace(
        '~<creationDate>[^<]*</creationDate>~',
        '<creationDate>' . $date . '</creationDate>',
        $content,
        1
    );

    if ($content !== $original) {
        file_put_contents($path, $content);
        echo "  $path → $version\n";
    } else {
        echo "  $path (no change)\n";
    }
}
