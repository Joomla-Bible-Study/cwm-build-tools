<?php

declare(strict_types=1);

/**
 * Verify each configured Joomla install has every project sub-extension
 * registered in its #__extensions table. Optionally fix drift.
 *
 *   composer verify              # report only
 *   composer verify -- --fix     # report + reconcile
 *   composer verify -- -v        # also list OK rows
 */

require_once __DIR__ . '/../src/Dev/InstallConfig.php';
require_once __DIR__ . '/../src/Dev/PropertiesReader.php';
require_once __DIR__ . '/../src/Dev/ExtensionVerifier.php';

use CWM\BuildTools\Dev\ExtensionVerifier;
use CWM\BuildTools\Dev\PropertiesReader;

$projectRoot = getcwd() ?: '.';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<HELP
cwm-verify — confirm each Joomla install has the project's sub-extensions
registered in #__extensions.

WHAT IT DOES
  Reads cwm-build.config.json (manifests.extensions[] + extension.*) and
  build.properties (install paths). For each install:
    1. Reads configuration.php at the install path to discover DB creds
    2. Connects to the DB via PDO (no Joomla bootstrap required)
    3. Looks up each expected extension by (type, element[, folder])
    4. Reports per row:
         OK     — registered, state matches manifest
         DRIFT  — registered but enabled/locked drifted from manifest
         MISS   — no row in #__extensions

  With --fix, drift is reconciled (UPDATE state) and missing libraries /
  plugins are INSERTed (libraries also run their install SQL). Components
  are flagged but never auto-inserted — install via the Extension Manager
  so the rest of the install lifecycle (params, schema version) runs.

PREREQUISITES
  - cwm-build.config.json in the current directory
  - build.properties (run 'composer setup' first)
  - Each install's configuration.php must be readable (Joomla must be
    installed at that path)

USAGE
  composer verify                   # report only
  composer verify -- --fix          # report + reconcile drift
  composer verify -- -v             # also list OK rows
  composer verify -- --fix -v       # full reconcile, verbose output

OPTIONS
      --fix         Reconcile drift (UPDATE state, INSERT missing libs/plugins)
  -v, --verbose     Print OK rows in addition to drift

EXIT CODE
  Exits 1 if any install reports an error (missing path, DB unreachable,
  unreconciled drift), 0 otherwise. Suitable for CI gating.

RELATED
  composer link          # before verify: ensure files are in place
  composer link-check    # after verify: confirm symlinks are healthy

HELP;

    exit(0);
}

$verbose   = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);
$reconcile = in_array('--fix', $argv, true);

$config = loadConfig($projectRoot);
$reader = new PropertiesReader($projectRoot . '/build.properties');

if (!$reader->exists()) {
    fwrite(STDERR, "build.properties not found. Run 'composer setup' first.\n");

    exit(1);
}

$installs = $reader->installs();

if ($installs === []) {
    fwrite(STDERR, "No Joomla installs configured in build.properties.\n");

    exit(1);
}

$verifier = new ExtensionVerifier($projectRoot, $config, $verbose);
$totals   = ['ok' => 0, 'fixed' => 0, 'errors' => 0];

foreach ($installs as $install) {
    $result = $verifier->verify($install, $reconcile);

    foreach ($totals as $k => $_) {
        $totals[$k] += $result[$k];
    }
}

echo "\nTotal: {$totals['ok']} OK, {$totals['fixed']} fixed, {$totals['errors']} error(s).\n";

exit($totals['errors'] > 0 ? 1 : 0);

/**
 * @return array<string, mixed>
 */
function loadConfig(string $projectRoot): array
{
    $configFile = $projectRoot . '/cwm-build.config.json';

    if (!is_file($configFile)) {
        fwrite(STDERR, "cwm-build.config.json not found in {$projectRoot}\n");

        exit(1);
    }

    $config = json_decode((string) file_get_contents($configFile), true);

    if (!is_array($config)) {
        fwrite(STDERR, "cwm-build.config.json is not valid JSON.\n");

        exit(1);
    }

    return $config;
}
