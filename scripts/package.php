<?php

declare(strict_types=1);

/**
 * cwm-package — generic Joomla multi-extension package zip assembler.
 *
 * Reads `package:` block of cwm-build.config.json from the current working
 * directory and assembles an installable Joomla package zip wrapping one
 * or more child extension zips. Supports four `includes[]` entry types
 * (`self`, `subBuild`, `prebuilt`, `inline`), two inner layouts (`root`
 * and `packages-prefix`), an optional installer scriptfile, optional
 * language files, and an opt-in self-verify step.
 */

require_once __DIR__ . '/../src/Build/BuildConfig.php';
require_once __DIR__ . '/../src/Build/ManifestReader.php';
require_once __DIR__ . '/../src/Build/PackageBuilder.php';
require_once __DIR__ . '/../src/Build/PackageConfig.php';
require_once __DIR__ . '/../src/Build/Packager.php';

use CWM\BuildTools\Build\BuildConfig;
use CWM\BuildTools\Build\PackageConfig;
use CWM\BuildTools\Build\Packager;

$projectRoot = getcwd() ?: '.';
$args        = $argv;
array_shift($args);

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo <<<HELP
cwm-package — assemble a multi-extension Joomla package zip from cwm-build.config.json.

WHAT IT DOES
  Reads the `package:` block of cwm-build.config.json (in the current working
  directory). For each `includes[]` entry, resolves a child extension zip
  via one of four mechanisms (`self`, `subBuild`, `prebuilt`, `inline`),
  then assembles the outer zip with the package manifest, optional installer
  scriptfile, optional language files, and the staged child zips at either
  outer root or under `packages/` (per `innerLayout`).

PREREQUISITES
  - cwm-build.config.json with a `package:` block in the project root
  - The package manifest XML referenced by package.manifest
  - For `self` includes: a parsable `build:` block in the same config

USAGE
  cwm-package                       # assemble using config defaults
  cwm-package -v                    # verbose: print every entry added to the outer zip
  cwm-package --version 1.2.3       # override version (skip manifest read)
  cwm-package --help

OPTIONS
  -v, --verbose          Print every file as it's added to the outer zip.
      --version <ver>    Use this version instead of the package manifest's <version>.
  -h, --help             Show this help.

EXIT CODE
  0 on success.
  1 on missing config, invalid config, missing manifest, missing
  sub-extension build artifact, sub-build script failure, or `verify`
  step failure.

`includes[]` ENTRY TYPES
  self      — invoke cwm-build on the project's own `build:` block, then
              bundle the result. Outer-zip entry name = `outputName`.
  subBuild  — `php <buildScript> [args...]` inside `path`, then glob
              `distGlob` (relative to `path`) for the produced zip.
  prebuilt  — assume already on disk; glob `distGlob` (project-relative).
  inline    — nested BuildConfig; cwm-build runs in-process on it.

RELATED
  cwm-build           # build a single extension zip; used by `self` and `inline`
  cwm-bump            # version bump across manifests
  cwm-release         # full release pipeline; runs build.command from config

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

if (!isset($rawConfig['package']) || !is_array($rawConfig['package'])) {
    fwrite(STDERR, "Error: cwm-build.config.json has no `package` block.\n");
    fwrite(STDERR, "  See examples/ for the shape, or use cwm-build for single-extension builds.\n");
    exit(1);
}

try {
    $packageConfig = PackageConfig::fromArray($rawConfig['package']);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "Error: invalid package config — " . $e->getMessage() . "\n");
    exit(1);
}

$parentBuild = null;

if (isset($rawConfig['build']) && is_array($rawConfig['build'])) {
    try {
        $parentBuild = BuildConfig::fromArray($rawConfig['build']);
    } catch (\InvalidArgumentException $e) {
        // The build: block is invalid — only an issue if `self` is used in
        // includes[]. Defer the error to packaging time so projects that
        // don't use `self` aren't blocked.
        $parentBuild = null;
    }
}

$packager = new Packager($packageConfig, $parentBuild, $projectRoot, $verbose);

try {
    $packager->package($versionOverride);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: package failed — " . $e->getMessage() . "\n");
    exit(1);
}
