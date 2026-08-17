<?php

declare(strict_types=1);

/**
 * Flag workflow path filters that no longer match anything, and exit non-zero
 * so CI can gate on it.
 */

require_once __DIR__ . '/../src/Dev/WorkflowPathScanner.php';

use CWM\BuildTools\Dev\WorkflowPathScanner;

$projectRoot = getcwd() ?: '.';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<CWM_HELP
cwm-lint-workflows — find CI path filters that guard nothing.

WHAT IT DOES
  Reads every `paths:` and `paths-ignore:` entry in .github/workflows and
  reports the ones that match no tracked file.

  A path filter decides whether a job runs. When an entry names a file that has
  moved or been deleted, the entry silently guards nothing — and in review it
  is indistinguishable from one that works. The job does not run, the pull
  request is green, and nothing says a check was skipped.

  That has happened five times across the CWM repositories. The most recent:
  a `paths:` entry for build/reset-testsite.php outlived the file by a day,
  after the shared cwm-reset-testsite command replaced it.

PREREQUISITES
  A git repository — the file list comes from `git ls-files`, so a pattern
  matching only ignored build output is correctly reported as matching nothing.

USAGE
  composer lint-workflows             # scan, exit 1 on findings
  composer lint-workflows -- --warn   # report but always exit 0
  composer lint-workflows -- path/    # scan a different workflows directory

OPTIONS
  -w, --warn       Report findings but exit 0.
  <path>           Workflows directory. Defaults to .github/workflows.

WHAT IT DOES NOT CATCH
  A filter that is too NARROW — the job that should have run and did not
  because nobody listed the path. That is the expensive half and no static
  check can see it: nothing here knows that test-install.sh reaches into
  cwm/build-tools, or that an API suite runs admin models.

  Two habits cover that better than a linter can. Treat the filter as part of
  any change that gives a job a new dependency, the same reflex as adding a
  require_once when a class gains a collaborator. And comment WHY each path is
  listed, so the next reader can tell a deliberate entry from an accident.

PATHS VS PATHS-IGNORE
  Only a stale `paths` entry fails the run. It fails CLOSED: the job never
  triggers and the pull request is green anyway.

  A stale `paths-ignore` is reported as a notice and does not fail. It fails
  OPEN — it excludes nothing, so the job merely runs more often — and it is
  not reliably wrong: excluding a gitignored directory like .claude/** is
  deliberate, and invisible to a check that reads tracked files.

EXIT CODE
  0  no stale `paths` entry (or --warn). Notices may still be printed.
  1  one or more `paths` entries match nothing

RELATED
  composer lint-queries        # \$db->createQuery() over \$db->getQuery(true)
  composer lint-comments       # issue references in code comments
  composer lint-deprecations   # Joomla 6/7 upgrade blockers

CWM_HELP;

    exit(0);
}

$warnOnly = false;
$dir      = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-w' || $arg === '--warn') {
        $warnOnly = true;

        continue;
    }

    if (!str_starts_with($arg, '-')) {
        $dir = $arg;
    }
}

$dir ??= '.github/workflows';
$absolute = str_starts_with($dir, '/') ? $dir : $projectRoot . '/' . $dir;

if (!is_dir($absolute)) {
    echo "No workflows directory at {$dir} — nothing to check.\n";

    exit(0);
}

// Tracked files, not the filesystem: a pattern matching only gitignored build
// output is not coverage, and reporting it as fine would be the same false
// reassurance this command exists to remove.
exec('git -C ' . escapeshellarg($projectRoot) . ' ls-files 2>/dev/null', $files, $status);

if ($status !== 0 || $files === []) {
    fwrite(STDERR, "Error: could not list tracked files (is this a git repository?).\n");
    fwrite(STDERR, "  Without the file list every pattern would look stale, which is worse\n");
    fwrite(STDERR, "  than not checking.\n");

    exit(1);
}

$workflows = glob($absolute . '/*.{yml,yaml}', GLOB_BRACE) ?: [];

if ($workflows === []) {
    echo "No workflow files in {$dir} — nothing to check.\n";

    exit(0);
}

$findings = 0;
$notices  = 0;
$checked  = 0;

foreach ($workflows as $workflow) {
    $yaml    = (string) file_get_contents($workflow);
    $entries = WorkflowPathScanner::extractPatterns($yaml);

    if ($entries === []) {
        continue;
    }

    $checked++;
    $stale = WorkflowPathScanner::stalePatterns($yaml, $files);

    if ($stale === []) {
        continue;
    }

    $relative = str_replace($projectRoot . '/', '', $workflow);

    foreach ($stale as $entry) {
        /*
         * Only a stale `paths` entry fails the run. It fails CLOSED — the job
         * never triggers and the pull request is green anyway. A stale
         * `paths-ignore` fails OPEN: it excludes nothing, so the job merely
         * runs more often, and it is not reliably wrong either, because
         * excluding a gitignored directory is both deliberate and invisible to
         * a tracked-file check.
         */
        if ($entry['key'] === 'paths-ignore') {
            printf("%s:%d\n    notice: paths-ignore '%s' matches no tracked file — harmless, and expected if the path is gitignored\n", $relative, $entry['line'], $entry['pattern']);
            $notices++;

            continue;
        }

        printf("%s:%d\n    %s: '%s' matches no tracked file\n", $relative, $entry['line'], $entry['key'], $entry['pattern']);
        $findings++;
    }
}

// Name what was examined even on success: a linter reporting "no findings"
// over a directory whose filters it never parsed reads identically to one that
// did the work.
echo "Checked {$checked} workflow(s) with path filters against " . count($files) . " tracked files.\n";

if ($findings === 0) {
    echo $notices === 0
        ? "Every filter entry matches something.\n"
        : "No stale `paths` entry. {$notices} paths-ignore notice(s) above, which do not fail the run.\n";

    exit(0);
}

fwrite(STDERR, "\nFound {$findings} filter entr" . ($findings === 1 ? 'y' : 'ies') . " matching nothing.\n");
fwrite(STDERR, "Either the file moved and the filter should follow, or it was deleted and\n");
fwrite(STDERR, "the entry should go. An entry that matches nothing guards nothing, and it\n");
fwrite(STDERR, "reads in review exactly like one that works.\n");

exit($warnOnly ? 0 : 1);
