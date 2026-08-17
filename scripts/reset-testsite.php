<?php

declare(strict_types=1);

/**
 * Tear one extension family off every `role = test` install so the next
 * install or upgrade starts from a genuine clean slate.
 */

require_once __DIR__ . '/../src/Dev/PropertiesReader.php';
require_once __DIR__ . '/../src/Dev/InstallConfig.php';
require_once __DIR__ . '/../src/Dev/TestSite.php';
require_once __DIR__ . '/../src/Dev/ExtensionQuery.php';
require_once __DIR__ . '/../src/Dev/TestSiteReset.php';

use CWM\BuildTools\Dev\PropertiesReader;
use CWM\BuildTools\Dev\TestSite;
use CWM\BuildTools\Dev\TestSiteReset;

$projectRoot = getcwd() ?: '.';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<CWM_HELP
cwm-reset-testsite — remove an extension family from every test install.

WHAT IT DOES
  Deletes the project's extensions from #__extensions and #__schemas, drops the
  tables its migrations created, clears its #__assets / #__categories / #__menu
  rows, and removes its installed directories — on every install marked
  `role = test` in build.properties.

  A repeatable install test has to begin from a known state. A stale
  #__extensions row makes Joomla route a fresh install to update(), so the
  "clean install" phase quietly becomes an upgrade; stale tables mask
  CREATE TABLE and ADD COLUMN failures.

  Every run prints the family it matched AND the retained set, because what a
  reset leaves behind is the load-bearing part and the part nobody can see.

PREREQUISITES
  - build.properties with at least one `role = test` install
  - a `testSite.reset` block in cwm-build.config.json

USAGE
  composer cwm-reset-testsite                # reset every role=test install
  composer cwm-reset-testsite -- --dry-run   # print the plan, change nothing
  composer cwm-reset-testsite -- --install j6

OPTIONS
  -n, --dry-run      Print what would be removed and stop.
  --install <id>     Only this install id, which must still be role = test.
  -h, --help         Show this and exit.

CONFIGURATION  (testSite.reset)
  elements[]          Exact element names in the family.
  elementPatterns[]   SQL LIKE patterns, e.g. "%proclaim%".
  retain[]            Elements a family match must NOT remove. Overrides the
                      above, and is printed and re-checked after the run.
  tablePrefixes[]     Table-name prefixes to drop, after the site prefix —
                      "bsms_" drops jos_bsms_*.
  components[]        Component names for #__assets / #__categories cleanup.
  menuLinkPatterns[]  LIKE patterns matched against #__menu.link.
  modulePatterns[]    LIKE patterns for #__modules.module. Instances are not
                      the extension row, and their #__modules_menu assignments
                      go with them.
  updateSiteNamePatterns[]
                      LIKE patterns for #__update_sites.name. A stale one keeps
                      Joomla polling for something no longer installed, and a
                      reinstall will not re-add it.
  actionLogPatterns[] LIKE patterns for the action-log registrations.
  directories[]       Install directories to remove, relative to the site root.
  fileGlobs[]         Loose files to remove, as globs relative to the site
                      root. Install manifests are the load-bearing case: their
                      presence makes a fresh install register as an UPDATE and
                      run ALTER SQL against tables that do not exist yet.

SAFETY
  Only `role = test` installs are touched, ever. Never point a development or
  production install at role = test — this drops tables and deletes files.

EXIT CODE
  0  every targeted install was reset (or --dry-run)
  1  an error: no config block, unreadable install, failed reset
  2  a retained extension did not survive — the family matched more than its
     author intended, and the next run would start from an undescribed state

RELATED
  composer cwm-baseline     # fetch the release an upgrade test starts from
  composer cwm-verify       # reconcile installed extensions vs manifests

CWM_HELP;

    exit(0);
}

$dryRun    = false;
$onlyId    = null;
$arguments = array_slice($argv, 1);

for ($i = 0; $i < count($arguments); $i++) {
    $arg = $arguments[$i];

    if ($arg === '-n' || $arg === '--dry-run') {
        $dryRun = true;

        continue;
    }

    if ($arg === '--install') {
        $onlyId = $arguments[++$i] ?? null;

        continue;
    }

    fwrite(STDERR, "Error: unknown option '{$arg}'.\n");

    exit(1);
}

$configFile = $projectRoot . '/cwm-build.config.json';

if (!is_file($configFile)) {
    fwrite(STDERR, "Error: cwm-build.config.json not found.\n");

    exit(1);
}

$config = json_decode((string) file_get_contents($configFile), true);
$plan   = is_array($config) ? ($config['testSite']['reset'] ?? null) : null;

if (!is_array($plan) || $plan === []) {
    fwrite(STDERR, "Error: no testSite.reset block in cwm-build.config.json.\n");
    fwrite(STDERR, "  Without one there is no family to remove, and an empty family would\n");
    fwrite(STDERR, "  either do nothing or — read the other way — match everything.\n");

    exit(1);
}

$reader   = new PropertiesReader($projectRoot . '/build.properties');
$installs = $reader->installsFor('test');

if ($onlyId !== null) {
    $installs = array_values(array_filter($installs, static fn ($i): bool => $i->id === $onlyId));

    if ($installs === []) {
        fwrite(STDERR, "Error: no role=test install with id '{$onlyId}' in build.properties.\n");

        exit(1);
    }
}

if ($installs === []) {
    echo "No role=test install in build.properties — nothing to reset.\n";

    exit(0);
}

$exit = 0;

foreach ($installs as $install) {
    echo "\n=== reset {$install->id} ({$install->path}) ===\n";

    try {
        $site = TestSite::fromPath($install->path);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, '  ERROR: ' . $e->getMessage() . "\n");
        $exit = 1;

        continue;
    }

    $reset  = new TestSiteReset($site, $plan);
    $family = $reset->familyRows();
    $tables = $reset->familyTables();

    echo '  driver: ' . $site->driver() . ", prefix: " . $site->prefix() . "\n";

    // The family, named. "removed 7 rows" is what the copies this replaces
    // printed, and it is not enough to tell a correct run from an over-broad one.
    echo '  family (' . count($family) . " extension row(s)):\n";

    foreach ($family as $row) {
        $group = $row['folder'] !== '' ? " [{$row['folder']}]" : '';
        echo "    - {$row['type']} {$row['element']}{$group}\n";
    }

    echo '  tables (' . count($tables) . "):\n";

    foreach ($tables as $table) {
        echo "    - {$table}\n";
    }

    // The retained set, named, every run — including when it is empty, so
    // "nothing is retained" is a statement rather than an absence.
    $retain = $plan['retain'] ?? [];
    echo '  retained (' . count($retain) . "): " . ($retain === [] ? '(none declared)' : implode(', ', $retain)) . "\n";

    if ($dryRun) {
        echo "  [dry-run] nothing removed.\n";

        continue;
    }

    $counts = $reset->purgeDatabase();
    $dirs   = $reset->purgeDirectories();
    $files  = $reset->purgeFiles();

    echo "  removed: {$counts['extensions']} extension row(s), {$counts['schemas']} schema row(s), "
        . count($counts['tables']) . ' table(s), '
        . "{$counts['assets']} asset(s), {$counts['categories']} category(ies), {$counts['menus']} menu item(s),\n";
    echo "           {$counts['modules']} module instance(s), {$counts['updateSites']} update site(s), "
        . "{$counts['actionLogs']} action-log row(s)\n";
    echo '  directories: ' . count($dirs['removed']) . ' removed, ' . count($dirs['unlinked']) . " symlink(s) unlinked\n";
    echo '  loose files: ' . count($files) . " removed\n";

    foreach ($reset->retainedStatus() as $element => $survived) {
        if ($survived) {
            echo "  retained OK: {$element}\n";

            continue;
        }

        fwrite(STDERR, "  RETAINED MISSING: {$element} was declared retained but is no longer registered.\n");
        fwrite(STDERR, "    The family matched more than intended. The next run starts from a state\n");
        fwrite(STDERR, "    nobody described, which is what the retained set exists to prevent.\n");
        $exit = 2;
    }
}

exit($exit);
