<?php

declare(strict_types=1);

/**
 * cwm-build — generic extension zip builder.
 *
 * Reads `build:` block of cwm-build.config.json from the current working
 * directory and produces an installable Joomla extension zip per the
 * declared sources, excludes, and optional pre-build gate.
 *
 * Phase 1 — covers lib_cwmscripture's build shape (loose `str_contains`
 * exclude matching, optional `ensure-minified` gate, per-source zip
 * prefix). Subsequent PRs add Proclaim's strict-mode filtering, vendor
 * pruning, auto-run pre-build, and the 3-way version prompt.
 */

require_once __DIR__ . '/../src/Build/BuildConfig.php';
require_once __DIR__ . '/../src/Build/ManifestReader.php';
require_once __DIR__ . '/../src/Build/Prompt.php';
require_once __DIR__ . '/../src/Build/PackageBuilder.php';

use CWM\BuildTools\Build\BuildConfig;
use CWM\BuildTools\Build\PackageBuilder;

$projectRoot = getcwd() ?: '.';
$args        = $argv;
array_shift($args);

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo <<<HELP
cwm-build — build an installable extension zip from cwm-build.config.json.

WHAT IT DOES
  Reads the `build:` block of cwm-build.config.json (in the current working
  directory), runs the optional pre-build gate, then walks every configured
  source directory into a zip under build.outputDir. Manifest version is
  read from <version> in build.manifest unless --version overrides.

PREREQUISITES
  - cwm-build.config.json with a `build:` block in the project root
  - The manifest XML referenced by build.manifest

USAGE
  cwm-build                       # full build using config defaults
  cwm-build -v                    # verbose: print every file added
  cwm-build --version 1.2.3       # override version (skip manifest read)
  cwm-build --help

OPTIONS
  -v, --verbose          Print every file as it's added to the zip.
      --version <ver>    Use this version instead of the manifest's <version>.
  -h, --help             Show this help.

EXIT CODE
  0 on success.
  1 on missing config, invalid config, missing manifest, or pre-build gate
  failure (e.g. missing `.min.js` siblings under `ensure-minified`).

RELATED
  cwm-package          # multi-extension package wrapper (planned for #5 PR D)
  cwm-bump             # version bump across manifests
  cwm-release          # full release pipeline; runs build.command from config

HELP;
    exit(0);
}

$verbose         = in_array('-v', $args, true) || in_array('--verbose', $args, true);
$versionOverride = null;

for ($i = 0, $n = count($args); $i < $n; $i++) {
    if ($args[$i] === '--version' && isset($args[$i + 1])) {
        $versionOverride = $args[++$i];
        continue;
    }

    if ($args[$i] === '-v' || $args[$i] === '--verbose') {
        continue;
    }

    fwrite(STDERR, "Error: unrecognized argument '{$args[$i]}'. Run with --help for usage.\n");
    exit(1);
}

$configFile = $projectRoot . '/cwm-build.config.json';

if (!is_file($configFile)) {
    fwrite(STDERR, "Error: cwm-build.config.json not found in $projectRoot\n");
    fwrite(STDERR, "Run 'cwm-init' to scaffold one, or run from your project root.\n");
    exit(1);
}

$rawConfig = json_decode((string) file_get_contents($configFile), true);

if (!is_array($rawConfig)) {
    fwrite(STDERR, "Error: cwm-build.config.json is not valid JSON\n");
    exit(1);
}

if (!isset($rawConfig['build']) || !is_array($rawConfig['build'])) {
    fwrite(STDERR, "Error: cwm-build.config.json has no `build` block.\n");
    fwrite(STDERR, "  See examples/library/cwm-build.config.json for the shape.\n");
    exit(1);
}

try {
    $config = BuildConfig::fromArray($rawConfig['build']);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "Error: invalid build config — " . $e->getMessage() . "\n");
    exit(1);
}

$builder = new PackageBuilder($config, $projectRoot, $verbose);

try {
    $builder->build($versionOverride);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: build failed — " . $e->getMessage() . "\n");
    exit(1);
}
