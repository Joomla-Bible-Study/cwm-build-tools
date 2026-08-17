<?php

declare(strict_types=1);

/**
 * Flag `$db->getQuery(true)` in favour of `$db->createQuery()` and exit
 * non-zero on any finding so CI can gate on it.
 */

require_once __DIR__ . '/../src/Dev/DeprecationScanner.php';
require_once __DIR__ . '/../src/Dev/QueryStyleRules.php';

use CWM\BuildTools\Dev\DeprecationScanner;
use CWM\BuildTools\Dev\QueryStyleRules;

$projectRoot = getcwd() ?: '.';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<CWM_HELP
cwm-lint-queries — enforce \$db->createQuery() over \$db->getQuery(true).

WHAT IT DOES
  Scans the project's configured source paths for `\$db->getQuery(true)` and
  reports file:line for each.

  Both spellings return the same DatabaseQuery and neither is deprecated in
  Joomla 5 or 6, so this is a consistency guard rather than a correctness one.
  It exists because consistency does not hold on its own: Proclaim reached 666
  uses of the old spelling against 9 of the documented one, simply because new
  code copies what it finds nearby.

  The no-argument `\$db->getQuery()` returns the *current* query rather than a
  new one. That is a different operation and is never flagged.

PREREQUISITES
  None. With no `lint.paths` configured it scans the conventional Joomla
  source roots that exist in the project.

USAGE
  composer lint-queries               # scan, exit 1 on findings
  composer lint-queries -- --warn     # report but always exit 0
  composer lint-queries -- path/      # scan a specific subtree

OPTIONS
  -w, --warn       Report findings but exit 0 (don't fail CI yet).
  <path>           Scan this directory instead of the configured paths.

CONFIGURATION
  lint.paths[]        Source roots to scan. Defaults to the conventional
                      Joomla layout, filtered to those that exist.
  lint.excludeDirs[]  Extra directory basenames to skip, on top of vendor/,
                      node_modules/, build/, dist/ and the VCS directories.
                      A git submodule is a separate repository and not this
                      project's standard to enforce — name it here.

RELATED
  composer lint-deprecations   # Joomla 6/7 upgrade blockers
  composer link-check          # verify dev symlinks

EXIT CODE
  0  no findings (or --warn)
  1  one or more findings

CWM_HELP;

    exit(0);
}

$warnOnly  = false;
$scanRoots = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-w' || $arg === '--warn') {
        $warnOnly = true;

        continue;
    }

    if (!str_starts_with($arg, '-')) {
        $scanRoots[] = $arg;
    }
}

/**
 * Read a dotted key out of the project's cwm-build.config.json.
 *
 * Absent config is not an error — a project that has never configured a lint
 * block still gets the conventional roots.
 *
 * @return mixed The value, or null when the file or the key is absent.
 */
$readConfig = static function (string $dotted) use ($projectRoot) {
    $file = $projectRoot . '/cwm-build.config.json';

    if (!is_file($file)) {
        return null;
    }

    $config = json_decode((string) file_get_contents($file), true);

    if (!is_array($config)) {
        return null;
    }

    $value = $config;

    foreach (explode('.', $dotted) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return null;
        }

        $value = $value[$key];
    }

    return $value;
};

$excludeDirs = $readConfig('lint.excludeDirs');
$excludeDirs = is_array($excludeDirs) ? array_values(array_filter($excludeDirs, 'is_string')) : [];

if ($scanRoots === []) {
    $configured = $readConfig('lint.paths');

    if (is_array($configured) && $configured !== []) {
        $scanRoots = array_values(array_filter($configured, 'is_string'));
    } else {
        // Only the conventional roots that actually exist. Scanning a path that
        // is not there is not an error, but reporting "0 findings" for a tree
        // that was never opened is the failure mode a linter must not have.
        $scanRoots = array_values(array_filter(
            QueryStyleRules::DEFAULT_PATHS,
            static fn (string $path): bool => is_dir($projectRoot . '/' . $path)
        ));
    }
}

if ($scanRoots === []) {
    fwrite(STDERR, "No source paths to scan.\n");
    fwrite(STDERR, "  Set lint.paths[] in cwm-build.config.json, or pass a path.\n");

    exit(1);
}

$scanner  = new DeprecationScanner(QueryStyleRules::rules());
$findings = [];
$scanned  = [];

foreach ($scanRoots as $root) {
    $absolute = str_starts_with($root, '/') ? $root : $projectRoot . '/' . $root;

    if (!is_dir($absolute)) {
        fwrite(STDERR, "Path not found: {$root}\n");

        exit(1);
    }

    $scanned[] = $root;
    $findings  = array_merge($findings, $scanner->scan($absolute, $excludeDirs === [] ? null : array_merge(
        ['vendor', 'node_modules', '.git', '.github', '.idea', 'build', 'dist'],
        $excludeDirs
    )));
}

// Name what was scanned even on success. A linter reporting "no findings" over
// a path list that silently resolved to nothing reads identically to one that
// did the work — the same shape as a test phase that skips itself and passes.
echo 'Scanned: ' . implode(', ', $scanned) . "\n";

if ($findings === []) {
    echo "No \$db->getQuery(true) found.\n";

    exit(0);
}

foreach ($findings as $finding) {
    printf("%s:%d\n    %s\n", $finding['file'], $finding['line'], $finding['snippet']);
}

$count = count($findings);

fwrite(STDERR, "\nFound {$count} use(s) of \$db->getQuery(true).\n");
fwrite(STDERR, $findings[0]['message'] . "\n");

exit($warnOnly ? 0 : 1);
