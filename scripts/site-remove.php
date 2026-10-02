<?php

declare(strict_types=1);

/**
 * Remove a site that cwm-site-create made.
 *
 *   composer site-remove -- j6            # shows the plan, removes nothing
 *   composer site-remove -- j6 --yes      # does it
 */

require_once __DIR__ . '/../src/Cli/Flags.php';
require_once __DIR__ . '/../src/Config/ManagedBlock.php';
require_once __DIR__ . '/../src/Dev/InstallConfig.php';
require_once __DIR__ . '/../src/Dev/PropertiesReader.php';
require_once __DIR__ . '/../src/Site/PathResolver.php';
require_once __DIR__ . '/../src/Site/SiteException.php';
require_once __DIR__ . '/../src/Site/DdevConfig.php';
require_once __DIR__ . '/../src/Site/CommandResult.php';
require_once __DIR__ . '/../src/Site/CommandRunner.php';
require_once __DIR__ . '/../src/Site/ProcessRunner.php';
require_once __DIR__ . '/../src/Site/RegisteredSite.php';
require_once __DIR__ . '/../src/Site/SiteRegistrar.php';
require_once __DIR__ . '/../src/Site/SiteLookup.php';
require_once __DIR__ . '/../src/Site/SafeRemover.php';
require_once __DIR__ . '/../src/Site/RemoveStage.php';

use CWM\BuildTools\Cli\Flags;
use CWM\BuildTools\Site\ProcessRunner;
use CWM\BuildTools\Site\RemoveStage;
use CWM\BuildTools\Site\SafeRemover;
use CWM\BuildTools\Site\SiteException;
use CWM\BuildTools\Site\SiteLookup;
use CWM\BuildTools\Site\SiteRegistrar;

if (Flags::has($argv, ['--help', '-h'])) {
    echo <<<HELP
cwm-site-remove — remove a site that cwm-site-create made.

WHAT IT DOES
  For the site recorded in build.properties under <id>:
    1. deletes its DDEV project (containers and database)
    2. deletes its folder
    3. removes its record from build.properties

  Without --yes it prints this plan, with a count of what will go, and removes
  nothing. In that order, so that a failure part-way leaves the site still
  recorded and the command can be run again.

SAFETY
  - Only a site cwm-site-create recorded: one written by hand, or by another
    tool, is refused.
  - The folder must carry the files only cwm-site-create writes, must not be a
    git repository, must not be your home directory, and must not contain the
    project you run this from.
  - Symlinks in the folder (a linked dev site has them, pointing into your
    source) are unlinked. They are never followed, so your source is not touched.

USAGE
  composer site-remove -- <id>            # show the plan
  composer site-remove -- <id> --yes      # remove it

OPTIONS
      --yes      Do it. Without this nothing is removed.
      --dry-run  Show the plan and exit 0 (the default exits 1, so a script that
                 forgot --yes does not read the plan as success).
  -h, --help     Show this.

HELP;

    exit(0);
}

$id = null;

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg !== '' && $arg[0] !== '-') {
        $id = $arg;

        break;
    }
}

if ($id === null) {
    fwrite(STDERR, "Usage: cwm-site-remove <id> [--yes]. Run with --help.\n");

    exit(1);
}

$projectRoot = realpath(getcwd() ?: '.') ?: '.';
$propsFile   = $projectRoot . '/build.properties';
$yes         = Flags::has($argv, ['--yes']);
$dryRun      = Flags::has($argv, ['--dry-run']);

try {
    $install = (new SiteLookup())->find($propsFile, $id);
    $stage   = new RemoveStage(new ProcessRunner(), new SafeRemover(getenv('HOME') ?: ''), new SiteRegistrar());
    $plan    = $stage->plan($install, $projectRoot);

    echo ($yes && !$dryRun ? 'Removing' : 'Would remove') . " site \"{$id}\" (role {$install->role}):\n";

    $n = 0;

    foreach ($plan['lines'] as $line) {
        echo str_starts_with($line, '    ') ? $line . "\n" : sprintf("  %d. %s\n", ++$n, $line);
    }

    if (!$yes || $dryRun) {
        if ($dryRun) {
            exit(0);
        }

        echo "\nNothing was removed. Run again with --yes to do it.\n";

        exit(1);
    }

    echo "\n";

    $stage->run($install, $projectRoot, $propsFile, static function (string $line): void {
        echo $line . "\n";
    });
} catch (SiteException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");

    exit(1);
}

echo "\nRemoved site \"{$id}\".\n";
