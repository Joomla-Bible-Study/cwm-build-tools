<?php

declare(strict_types=1);

/**
 * Scan a consumer's source tree for Joomla-upgrade-blocking patterns and exit
 * non-zero on any finding so CI can gate on a J6/J7-clean tree.
 */

require_once __DIR__ . '/../src/Dev/DeprecationScanner.php';

use CWM\BuildTools\Dev\DeprecationScanner;

$projectRoot = getcwd() ?: '.';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<HELP
cwm-lint-deprecations — flag Joomla 6/7 upgrade blockers in source.

WHAT IT DOES
  Scans the project source tree for patterns that break when upgrading off
  Joomla 5, reporting file:line for each:
    bootstrap.modal       — Bootstrap modal JS asset (removed in J6/J7)
    data-bs-toggle=modal  — Bootstrap modal trigger markup
    iframe modal handler  — legacy {handler: 'iframe'} modal links
    Joomla.Modal JS API   — removed in favour of JoomlaDialog
    jQuery global         — bundled jQuery is going away

  The first-class replacements are ModalSelectField (declarative) or
  `import JoomlaDialog from 'joomla.dialog'` in a type=module asset built
  from a *.es6.mjs source (see templates/rollup.config.js).

  vendor/, node_modules/, build/, dist/, .git/ and *.min.js are skipped.

USAGE
  composer lint-deprecations              # scan, exit 1 on findings
  composer lint-deprecations -- --warn    # report but always exit 0
  composer lint-deprecations -- path/     # scan a specific subtree

OPTIONS
  -w, --warn       Report findings but exit 0 (don't fail CI yet).
  <path>           Scan this directory instead of the current one.

RELATED
  composer link-check     # verify dev symlinks
  composer verify         # reconcile installed extensions vs manifests

EXIT CODE
  0  no findings (or --warn)
  1  one or more deprecation findings

HELP;

    exit(0);
}

$warnOnly = false;
$scanRoot = $projectRoot;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-w' || $arg === '--warn') {
        $warnOnly = true;

        continue;
    }

    if (!str_starts_with($arg, '-')) {
        $scanRoot = $arg;
    }
}

if (!is_dir($scanRoot)) {
    fwrite(STDERR, "Path not found: {$scanRoot}\n");

    exit(1);
}

$findings = (new DeprecationScanner())->scan($scanRoot);

if ($findings === []) {
    echo "No Joomla 6/7 deprecation patterns found.\n";

    exit(0);
}

// Group by file for a readable, grep-friendly report.
$byFile = [];

foreach ($findings as $finding) {
    $byFile[$finding['file']][] = $finding;
}

$rootPrefix = rtrim($scanRoot, '/') . '/';

foreach ($byFile as $file => $fileFindings) {
    $relative = str_starts_with($file, $rootPrefix)
        ? substr($file, strlen($rootPrefix))
        : $file;

    echo "\n{$relative}\n";

    foreach ($fileFindings as $finding) {
        echo "  {$finding['line']}: [{$finding['label']}] {$finding['snippet']}\n";
        echo "      -> {$finding['message']}\n";
    }
}

$count = count($findings);
$files = count($byFile);

echo "\n{$count} finding(s) across {$files} file(s).\n";

if ($warnOnly) {
    echo "Run without --warn to fail CI on these.\n";

    exit(0);
}

exit(1);