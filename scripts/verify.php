<?php

declare(strict_types=1);

/**
 * Verify each configured Joomla install matches what the project (and its
 * CWM Composer deps) expects. Routes by install role:
 *
 *   role = dev   → DevTargetVerifier (symlinks + version constraint + path-repo cleanliness)
 *   role = test  → ExtensionVerifier (queries #__extensions for installed artifacts)
 *
 *   composer cwm-verify                      # verify every install per its role
 *   composer cwm-verify -- --target dev      # only role=dev installs
 *   composer cwm-verify -- --target test     # only role=test installs
 *   composer cwm-verify -- --fix             # reconcile drift (test target only)
 *   composer cwm-verify -- -v                # also list OK rows
 */

require_once __DIR__ . '/../src/Config/CwmPackage.php';
require_once __DIR__ . '/../src/Config/InstalledPackageReader.php';
require_once __DIR__ . '/../src/Dev/InstallConfig.php';
require_once __DIR__ . '/../src/Dev/PropertiesReader.php';
require_once __DIR__ . '/../src/Dev/ExtensionVerifier.php';
require_once __DIR__ . '/../src/Dev/Linker.php';
require_once __DIR__ . '/../src/Dev/LinkResolver.php';
require_once __DIR__ . '/../src/Dev/DevTargetVerifier.php';

use CWM\BuildTools\Config\InstalledPackageReader;
use CWM\BuildTools\Dev\DevTargetVerifier;
use CWM\BuildTools\Dev\ExtensionVerifier;
use CWM\BuildTools\Dev\InstallConfig;
use CWM\BuildTools\Dev\PropertiesReader;

$projectRoot = getcwd() ?: '.';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<HELP
cwm-verify — confirm each Joomla install matches what the project + its
CWM Composer deps expect.

WHAT IT DOES
  Walks every install in build.properties and verifies it according to
  its role:

    role = dev    Filesystem-only check: every expected symlink is in
                  place, every CWM dep's declared joomlaLinks resolve
                  correctly, every dep's installed version satisfies the
                  composer.json constraint, and path-repo deps have a
                  clean working tree.

    role = test   Database check (existing behaviour): reads each
                  install's configuration.php, connects to the DB, and
                  confirms each declared extension (self + every CWM
                  dep's joomlaLinks) is registered in #__extensions
                  at the expected version with the right enabled/locked
                  state. --fix reconciles drift.

  CWM deps are discovered from vendor/composer/installed.json — each
  installed package whose composer.json declares an
  extra.cwm-build-tools.joomlaLinks block is included automatically.

PREREQUISITES
  - cwm-build.config.json in the current directory
  - build.properties (run 'composer setup' first)
  - For role=test: each install's configuration.php must be readable
    and the DB must be reachable

USAGE
  composer cwm-verify                       # verify every install per role
  composer cwm-verify -- --target dev       # only role=dev installs
  composer cwm-verify -- --target test      # only role=test installs
  composer cwm-verify -- --fix              # reconcile DB drift (test target)
  composer cwm-verify -- -v                 # also list OK rows
  composer cwm-verify -- --fix -v           # full reconcile, verbose

OPTIONS
      --target <role>  Filter installs by role (`dev` or `test`). Omit
                       to verify every install per its declared role.
      --fix            Reconcile drift on role=test installs (UPDATE
                       state, INSERT missing libs/plugins). Ignored
                       for role=dev installs.
  -v, --verbose        Print OK rows in addition to drift/conflict rows.

EXIT CODE
  0 when every install passes.
  1 when any install reports an error (missing link, broken link,
    conflicting link, DB unreachable, unreconciled drift, version
    constraint failure).

RELATED
  composer cwm-link         # create the symlinks that --target dev checks
  composer cwm-install-zip  # deploy the zip that --target test checks

HELP;

    exit(0);
}

$verbose   = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);
$reconcile = in_array('--fix', $argv, true);
$target    = extractFlagValue($argv, '--target');

if ($target !== null && !in_array($target, [InstallConfig::ROLE_DEV, InstallConfig::ROLE_TEST], true)) {
    fwrite(\STDERR, "--target must be one of: dev, test\n");

    exit(1);
}

$config = loadConfig($projectRoot);
$reader = new PropertiesReader($projectRoot . '/build.properties');

if (!$reader->exists()) {
    fwrite(\STDERR, "build.properties not found. Run 'composer setup' first.\n");

    exit(1);
}

$installs = $target === null
    ? $reader->installs()
    : $reader->installsFor($target);

if ($installs === []) {
    if ($target !== null) {
        fwrite(\STDERR, "No install with role={$target} configured in build.properties.\n");
    } else {
        fwrite(\STDERR, "No Joomla installs configured in build.properties.\n");
    }

    exit(1);
}

$packageReader = new InstalledPackageReader($projectRoot);
$cwmPackages   = $packageReader->cwmPackages();
$devVerifier   = new DevTargetVerifier($projectRoot, $config, $verbose);
$testVerifier  = new ExtensionVerifier($projectRoot, $config, $verbose);

$totals = ['ok' => 0, 'fixed' => 0, 'errors' => 0, 'warnings' => 0];

foreach ($installs as $install) {
    if ($install->role === InstallConfig::ROLE_TEST) {
        $r = $testVerifier->verify($install, $reconcile, $cwmPackages);
        $totals['ok']     += $r['ok'];
        $totals['fixed']  += $r['fixed'];
        $totals['errors'] += $r['errors'];

        continue;
    }

    $r = $devVerifier->verify($install, $cwmPackages);
    $totals['ok']       += $r['ok'];
    $totals['errors']   += $r['errors'];
    $totals['warnings'] += $r['warnings'];
}

$tags = ["{$totals['ok']} ok"];

if ($totals['fixed'] > 0) {
    $tags[] = "{$totals['fixed']} fixed";
}

$tags[] = "{$totals['errors']} error(s)";

if ($totals['warnings'] > 0) {
    $tags[] = "{$totals['warnings']} warning(s)";
}

echo "\nTotal: " . implode(', ', $tags) . ".\n";

exit($totals['errors'] > 0 ? 1 : 0);

/**
 * @return array<string, mixed>
 */
function loadConfig(string $projectRoot): array
{
    $configFile = $projectRoot . '/cwm-build.config.json';

    if (!is_file($configFile)) {
        fwrite(\STDERR, "cwm-build.config.json not found in {$projectRoot}\n");

        exit(1);
    }

    $config = json_decode((string) file_get_contents($configFile), true);

    if (!is_array($config)) {
        fwrite(\STDERR, "cwm-build.config.json is not valid JSON.\n");

        exit(1);
    }

    return $config;
}

/**
 * @param  list<string> $argv
 */
function extractFlagValue(array $argv, string $flag): ?string
{
    foreach ($argv as $i => $arg) {
        if ($arg === $flag) {
            return $argv[$i + 1] ?? null;
        }

        if (str_starts_with($arg, $flag . '=')) {
            return substr($arg, strlen($flag) + 1);
        }
    }

    return null;
}
