<?php

declare(strict_types=1);

/**
 * Replay an extension's migration files against a scratch schema, so the files
 * a real upgrade runs are executed by something other than a user's site.
 */

require_once __DIR__ . '/../src/Cli/Flags.php';
require_once __DIR__ . '/../src/Dev/SqlSplitter.php';
require_once __DIR__ . '/../src/Dev/MigrationSequence.php';
require_once __DIR__ . '/../src/Dev/SchemaReplay.php';

use CWM\BuildTools\Cli\Flags;
use CWM\BuildTools\Dev\MigrationSequence;
use CWM\BuildTools\Dev\SchemaReplay;

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<CWM_HELP
cwm-schema-replay — run every migration file against a scratch database.

WHAT IT DOES
  Applies a committed baseline schema to an empty set of prefixed tables, then
  executes each migration a site at that version would run, in the order and
  with the skip rule Joomla's installer uses. Any statement that fails — other
  than one marked /** CAN FAIL **/, which the installer tolerates — fails the
  command and names the file and statement.

  Nothing else in the release gate executes these files. A fresh install runs
  install.sql and Joomla stamps #__schemas at the newest version WITHOUT running
  a single update file, so every check that starts from a fresh site proves the
  destination schema is right while the files that get a real site there have
  never run. A migration can ship with a syntax error, or referencing a table a
  later version dropped, and stay green until a user upgrades.

  Tables are created under a prefix inside the database the DSN names, and
  dropped afterwards. No database is created or dropped.

PREREQUISITES
  - CWM_TEST_MYSQL_DSN pointing at a MySQL or MariaDB server, with
    CWM_TEST_MYSQL_USER / CWM_TEST_MYSQL_PASSWORD as needed.
    `docker compose -f docker-compose.databases.yml up -d` provides one.
  - a `schemaReplay.targets[]` block in cwm-build.config.json
  - a committed baseline .sql per target

USAGE
  vendor/bin/cwm-schema-replay                       # every configured target
  vendor/bin/cwm-schema-replay --target com_proclaim
  vendor/bin/cwm-schema-replay --keep                # leave the tables to inspect

  Shown as vendor/bin/ because that always works. A composer alias exists only
  if the project declares one -- cwm-init wires `composer schema-replay` into a
  new consumer, but projects name their own scripts and an older one may have
  none.

OPTIONS
  --target <name>    Only this target from schemaReplay.targets[].
  --keep             Do not drop the replayed tables when finished.
  -v, --verbose      Print every migration, not just failures.
  -h, --help         Show this and exit.

CONFIGURATION  (schemaReplay)
  prefix       Table prefix to replay under. Default "cwmreplay_".
  targets[]    One entry per extension that ships a <schemapath>:
                 name      Label for output.
                 manifest  Path to the manifest XML, relative to the project.
                 baseline  Path, or list of paths, to the starting schema.
                 from      Version the baseline represents, e.g. "10.0.0".

  `from` must match what the baseline actually contains. Set it too low and
  files that are already applied get replayed, failing with "duplicate column"
  against a migration when the fault is this setting.

THE BASELINE
  The schema of a real site running the oldest release you still support
  upgrades from — not the extension's install SQL alone. Migrations write to
  core tables no extension manifest creates: Proclaim's touch #__assets,
  #__schemas, #__action_log_config and #__action_logs_extensions. Replaying
  onto the extension's own tables fails on the first of those, for a reason
  that says nothing about the migration.

  So `baseline` takes a list, applied in order — the core schema, then the
  extension's:

      "baseline": [
          "build/sql-baselines/joomla-core.sql",
          "build/sql-baselines/com_proclaim-10.0.0.sql"
      ]

  Produce the core half once, from a site at that version:

      mysqldump --no-data --skip-add-drop-table <db> > core.sql
      # then rewrite the site's prefix (jos_, etc.) back to #__

  Commit both. A baseline pulled out of git history at run time is not
  reviewable in a pull request, and this file decides what "every migration
  passes" means.

EXIT CODE
  0  every target replayed cleanly
  1  a statement failed, or nothing was configured to replay

  Exits 1 rather than 0 when no target is configured: a replay with no target
  replayed nothing, and a green exit there would let the release gate pass
  while testing an empty set.

RELATED
  composer cwm-reset-testsite   # clean an install so a fresh install is fresh
  composer cwm-verify           # confirm extensions are registered and enabled

CWM_HELP;
    exit(0);
}

/**
 * Resolve a configured path against the project root, leaving absolute paths alone.
 *
 * Config paths are meant to be project-relative, but concatenating a root onto
 * an absolute one produces a mangled path and an error message that blames the
 * wrong thing.
 */
function cwmResolvePath(string $projectRoot, string $path): string
{
    return str_starts_with($path, '/') ? $path : $projectRoot . '/' . $path;
}

$projectRoot = getcwd() ?: '.';
$verbose     = Flags::has($argv, ['-v', '--verbose']);
$keep        = Flags::has($argv, ['--keep']);
$only        = Flags::value($argv, '--target');

$configFile = $projectRoot . '/cwm-build.config.json';

if (!is_file($configFile)) {
    fwrite(STDERR, "Error: cwm-build.config.json not found in {$projectRoot}\n");
    exit(1);
}

$config = json_decode((string) file_get_contents($configFile), true);

if (!is_array($config)) {
    fwrite(STDERR, "Error: cwm-build.config.json is not valid JSON.\n");
    exit(1);
}

$block   = $config['schemaReplay'] ?? [];
$targets = is_array($block['targets'] ?? null) ? $block['targets'] : [];
$prefix  = is_string($block['prefix'] ?? null) && $block['prefix'] !== '' ? $block['prefix'] : 'cwmreplay_';

if ($only !== null) {
    $targets = array_values(array_filter(
        $targets,
        static fn(array $t): bool => ($t['name'] ?? null) === $only,
    ));

    if ($targets === []) {
        fwrite(STDERR, "Error: no schemaReplay target named \"{$only}\".\n");
        exit(1);
    }
}

if ($targets === []) {
    fwrite(STDERR, "No schemaReplay.targets[] in cwm-build.config.json — nothing to replay.\n");
    fwrite(STDERR, "Declare one so this check has something to run. See --help.\n");

    // Not exit(0): see EXIT CODE in --help.
    exit(1);
}

$dsn = getenv('CWM_TEST_MYSQL_DSN');

if ($dsn === false || $dsn === '') {
    fwrite(STDERR, "Error: CWM_TEST_MYSQL_DSN is not set.\n");
    fwrite(STDERR, "  docker compose -f docker-compose.databases.yml up -d\n");
    fwrite(STDERR, "  CWM_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3307;dbname=cwm_test' \\\n");
    fwrite(STDERR, "    CWM_TEST_MYSQL_USER=root CWM_TEST_MYSQL_PASSWORD=root composer cwm-schema-replay\n");
    exit(1);
}

try {
    $pdo = new PDO(
        $dsn,
        getenv('CWM_TEST_MYSQL_USER') ?: 'root',
        getenv('CWM_TEST_MYSQL_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Error: cannot connect — {$e->getMessage()}\n");
    exit(1);
}

echo "========================================================================\n";
echo " SCHEMA REPLAY — every migration executed, not just its effects checked\n";
echo "========================================================================\n";
echo " prefix: {$prefix}\n\n";

$failed = 0;

foreach ($targets as $target) {
    $name     = (string) ($target['name'] ?? 'unnamed');
    $manifest = cwmResolvePath($projectRoot, (string) ($target['manifest'] ?? ''));
    $from     = (string) ($target['from'] ?? '');

    // A single path is the common case; a list is what a real site needs, since
    // migrations touch core tables no extension manifest creates. See --help.
    $declared  = $target['baseline'] ?? [];
    $declared  = is_array($declared) ? $declared : [$declared];
    $baselines = array_map(
        static fn($p): string => cwmResolvePath($projectRoot, (string) $p),
        array_values($declared),
    );

    echo "--- {$name} " . str_repeat('-', max(1, 68 - strlen($name))) . "\n";

    if ($from === '') {
        fwrite(STDERR, "  ✗ target \"{$name}\" has no `from` version — cannot know which files a site at the baseline would run.\n");
        $failed++;

        continue;
    }

    if ($baselines === []) {
        fwrite(STDERR, "  ✗ target \"{$name}\" declares no baseline — there is nothing to replay onto.\n");
        $failed++;

        continue;
    }

    $missing = array_values(array_filter($baselines, static fn(string $p): bool => !is_file($p)));

    if ($missing !== []) {
        foreach ($missing as $path) {
            fwrite(STDERR, "  ✗ baseline not found: {$path}\n");
        }

        $failed++;

        continue;
    }

    try {
        $sequence = MigrationSequence::fromManifest($manifest);
        $replay   = new SchemaReplay($pdo, $prefix);
    } catch (RuntimeException $e) {
        fwrite(STDERR, "  ✗ {$e->getMessage()}\n");
        $failed++;

        continue;
    }

    // Anything left from an interrupted run would make a CREATE TABLE fail for
    // a reason that has nothing to do with the migration.
    $replay->teardown();

    $pending = $sequence->after($from);
    echo "  baseline {$from}, " . count($pending) . " of " . count($sequence->versions()) . " migrations to replay\n";

    $results  = $replay->replay($baselines, $sequence, $from);
    $error    = null;
    $ran      = 0;
    $tolerant = 0;

    foreach ($results as $result) {
        $ran      += $result['statements'];
        $tolerant += $result['tolerated'];

        if ($result['error'] !== null) {
            $error = $result;

            break;
        }

        if ($verbose) {
            printf("    ok  %-24s %3d statements%s\n", $result['label'], $result['statements'], $result['tolerated'] > 0 ? sprintf(' (%d tolerated)', $result['tolerated']) : '');
        }
    }

    if ($error !== null) {
        $failed++;
        echo "  ✗ FAILED in {$error['label']}\n";
        echo "      statement: {$error['error']['statement']}\n";
        echo "      error:     {$error['error']['message']}\n";
    } else {
        printf("  ✓ %d statements applied%s\n", $ran, $tolerant > 0 ? sprintf(", %d CAN FAIL tolerated", $tolerant) : '');
    }

    if ($keep) {
        echo "  tables kept under {$prefix} (--keep)\n";
    } else {
        $replay->teardown();
    }

    echo "\n";
}

if ($failed > 0) {
    echo "RESULT: {$failed} target(s) failed.\n";
    exit(1);
}

echo "RESULT: all targets replayed cleanly.\n";
exit(0);
