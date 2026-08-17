<?php

declare(strict_types=1);

/**
 * Flag issue references written inside code comments and exit non-zero on any
 * finding so CI can gate on it.
 */

require_once __DIR__ . '/../src/Dev/CommentScanner.php';

use CWM\BuildTools\Dev\CommentScanner;

$projectRoot = getcwd() ?: '.';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<CWM_HELP
cwm-lint-comments — keep issue references out of code comments.

WHAT IT DOES
  Scans the project's configured source paths for issue numbers written in
  comments and reports file:line for each.

  A comment carries what a maintainer needs at the line. An issue number is a
  pointer to the investigation instead, and git log / git blame already connect
  any line to its commit, its pull request and its issue — so the citation adds
  nothing to the file and goes stale the moment the numbering context does.

  Only the comment part of a line is searched, so an inline comment after code
  is covered while a `#` inside a string literal or a hex colour is not.

  `owner/repo#123` names another project's tracker, which blame cannot lead
  anyone to. That earns its place and is never flagged — which is also how to
  fix a cross-repository citation rather than delete it.

PREREQUISITES
  None. With no `lint.paths` configured it scans the conventional Joomla
  source roots that exist in the project.

USAGE
  composer lint-comments               # scan, exit 1 on findings
  composer lint-comments -- --warn     # report but always exit 0
  composer lint-comments -- path/      # scan a specific subtree

OPTIONS
  -w, --warn         Report findings but exit 0 (don't fail CI yet).
  --digits <n>       How many digits make a token an issue number. Default 4.
  <path>             Scan this directory instead of the configured paths.

CONFIGURATION
  lint.paths[]         Source roots to scan. Defaults to the conventional
                       Joomla layout, filtered to those that exist.
  lint.skip[]          Extra path fragments to skip, on top of vendor/,
                       node_modules/, .git/, tests/, libraries/ and .min.
  lint.issueDigits     Same as --digits.

  Tests are skipped by default and that is deliberate: a regression test's
  docblock naming the issue it guards is the one place the number is the
  subject rather than a pointer away from it.

  Four digits is the default because three collide with CSS hex colours. A
  younger repository numbering in the hundreds must lower it or it gets no
  coverage at all.

RELATED
  composer lint-queries        # \$db->createQuery() over \$db->getQuery(true)
  composer lint-deprecations   # Joomla 6/7 upgrade blockers

EXIT CODE
  0  no findings (or --warn)
  1  one or more findings

CWM_HELP;

    exit(0);
}

$warnOnly  = false;
$scanRoots = [];
$digits    = null;
$args      = array_slice($argv, 1);

for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];

    if ($arg === '-w' || $arg === '--warn') {
        $warnOnly = true;

        continue;
    }

    if ($arg === '--digits') {
        $digits = (int) ($args[++$i] ?? 0);

        continue;
    }

    if (!str_starts_with($arg, '-')) {
        $scanRoots[] = $arg;
    }
}

/**
 * Read a dotted key out of the project's cwm-build.config.json.
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

if ($digits === null) {
    $configured = $readConfig('lint.issueDigits');
    $digits     = is_int($configured) ? $configured : 4;
}

if ($digits < 1) {
    fwrite(STDERR, "--digits must be at least 1.\n");

    exit(1);
}

$skip = $readConfig('lint.skip');
$skip = is_array($skip) ? array_values(array_filter($skip, 'is_string')) : [];
$skip = $skip === [] ? CommentScanner::DEFAULT_SKIP : array_merge(CommentScanner::DEFAULT_SKIP, $skip);

if ($scanRoots === []) {
    $configured = $readConfig('lint.paths');

    if (is_array($configured) && $configured !== []) {
        $scanRoots = array_values(array_filter($configured, 'is_string'));
    } else {
        // Only the conventional roots that actually exist. Reporting "no
        // findings" for a tree that was never opened is the failure mode a
        // linter must not have, so the run names what it scanned either way.
        $scanRoots = array_values(array_filter(
            CommentScanner::DEFAULT_PATHS,
            static fn (string $path): bool => is_dir($projectRoot . '/' . $path)
        ));
    }
}

if ($scanRoots === []) {
    fwrite(STDERR, "No source paths to scan.\n");
    fwrite(STDERR, "  Set lint.paths[] in cwm-build.config.json, or pass a path.\n");

    exit(1);
}

foreach ($scanRoots as $root) {
    $absolute = str_starts_with($root, '/') ? $root : $projectRoot . '/' . $root;

    if (!is_dir($absolute)) {
        fwrite(STDERR, "Path not found: {$root}\n");

        exit(1);
    }
}

$offenders = (new CommentScanner($digits))->scan($projectRoot, $scanRoots, $skip);

echo 'Scanned: ' . implode(', ', $scanRoots) . "\n";

if ($offenders === []) {
    echo "No issue references in comments.\n";

    exit(0);
}

echo 'Issue references found in comments (' . count($offenders) . "):\n\n";

foreach ($offenders as $offender) {
    echo "  {$offender['file']}:{$offender['line']}\n";
    echo '      ' . (\strlen($offender['snippet']) > 96 ? substr($offender['snippet'], 0, 96) . '…' : $offender['snippet']) . "\n";
}

echo "\nMove the reference to the commit body or the pull request, and leave the\n";
echo "comment saying what the code does. `owner/repo#123` for another project's\n";
echo "tracker is allowed; so is a hex colour.\n";

exit($warnOnly ? 0 : 1);
