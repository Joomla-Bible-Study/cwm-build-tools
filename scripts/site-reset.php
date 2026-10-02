<?php

declare(strict_types=1);

/**
 * Put a role=test site that cwm-site-create made back to a known state, and
 * reinstall the built package.
 *
 *   composer site-reset -- j6
 */

require_once __DIR__ . '/../src/Build/DistZipResolver.php';
require_once __DIR__ . '/../src/Cli/Flags.php';
require_once __DIR__ . '/../src/Config/ManagedBlock.php';
require_once __DIR__ . '/../src/Dev/InstallConfig.php';
require_once __DIR__ . '/../src/Dev/PropertiesReader.php';
require_once __DIR__ . '/../src/Site/SiteException.php';
require_once __DIR__ . '/../src/Site/SiteSpec.php';
require_once __DIR__ . '/../src/Site/Mount.php';
require_once __DIR__ . '/../src/Site/MountPlanner.php';
require_once __DIR__ . '/../src/Site/DdevConfig.php';
require_once __DIR__ . '/../src/Site/CommandResult.php';
require_once __DIR__ . '/../src/Site/CommandRunner.php';
require_once __DIR__ . '/../src/Site/ProcessRunner.php';
require_once __DIR__ . '/../src/Site/DdevEnvironment.php';
require_once __DIR__ . '/../src/Site/InstallReport.php';
require_once __DIR__ . '/../src/Site/ExtensionInstallStage.php';
require_once __DIR__ . '/../src/Site/PackageLocator.php';
require_once __DIR__ . '/../src/Site/RegisteredSite.php';
require_once __DIR__ . '/../src/Site/SiteRegistrar.php';
require_once __DIR__ . '/../src/Site/SiteLookup.php';
require_once __DIR__ . '/../src/Site/ResetStage.php';

use CWM\BuildTools\Cli\Flags;
use CWM\BuildTools\Site\DdevConfig;
use CWM\BuildTools\Site\DdevEnvironment;
use CWM\BuildTools\Site\ExtensionInstallStage;
use CWM\BuildTools\Site\MountPlanner;
use CWM\BuildTools\Site\PackageLocator;
use CWM\BuildTools\Site\ProcessRunner;
use CWM\BuildTools\Site\ResetStage;
use CWM\BuildTools\Site\SiteException;
use CWM\BuildTools\Site\SiteLookup;
use CWM\BuildTools\Site\SiteSpec;

if (Flags::has($argv, ['--help', '-h'])) {
    echo <<<HELP
cwm-site-reset — put a test site back to a known state.

WHAT IT DOES
  For a role=test site recorded in build.properties under <id>:
    1. starts its DDEV project if it is stopped
    2. runs cwm-reset-testsite for it: removes the project's extensions, tables
       and files, keeping what the project's testSite.reset block says to retain
    3. installs the project's built package again, with the installer's messages

  The point is a repeatable start: a stale extension row makes Joomla route a
  fresh install to an update, so a "clean install" quietly becomes an upgrade.

  Joomla itself and its database are kept. To start from nothing, use
  cwm-site-remove and cwm-site-create.

  A role=dev site is refused: it is linked to your source, and what you want
  there is to edit and reload, not to reset.

PREREQUISITES
  - a site made by cwm-site-create with --role test
  - a testSite.reset block in cwm-build.config.json (see cwm-reset-testsite)

USAGE
  composer site-reset -- <id>
  composer site-reset -- <id> --dry-run     # show what would be removed, remove nothing

OPTIONS
      --package <p>  The built zip to install afterwards: a path, "auto" (the
                     default: newest zip matching build.outputGlob, skipped with a
                     note if there is none) or "none" to only reset.
      --dry-run      Preview the reset against the live database; install nothing.
  -h, --help         Show this.

HELP;

    exit(0);
}

$valueFlags = ['--package'];
$id         = null;

foreach (\array_slice($argv, 1) as $i => $arg) {
    $previous = $argv[$i] ?? '';

    if ($arg !== '' && $arg[0] !== '-' && !\in_array($previous, $valueFlags, true)) {
        $id = $arg;

        break;
    }
}

if ($id === null) {
    fwrite(STDERR, "Usage: cwm-site-reset <id> [options]. Run with --help.\n");

    exit(1);
}

$projectRoot = realpath(getcwd() ?: '.') ?: '.';
$dryRun      = Flags::has($argv, ['--dry-run']);

try {
    $install = (new SiteLookup())->find($projectRoot . '/build.properties', $id);

    if ($install->role !== 'test') {
        throw new SiteException(sprintf(
            'Site "%s" is role=%s. Only a test site is reset: a dev site is linked to your source and is meant to be edited, '
            . 'not reset. To start over, run cwm-site-remove and cwm-site-create.',
            $id,
            $install->role
        ));
    }

    // Decided before anything runs, so a bad --package fails now.
    $located = (new PackageLocator())->locate(Flags::value($argv, '--package') ?? 'auto', $projectRoot, $projectRoot);
    $zip     = $located['path'];

    if ($located['note'] !== null) {
        echo "Note: no built package to install, so the site will only be reset.\n  "
            . str_replace("\n", "\n  ", $located['note']) . "\n\n";
    }

    $runner      = new ProcessRunner();
    $environment = new DdevEnvironment(
        $runner,
        new DdevConfig(),
        new MountPlanner(),
        (getenv('CWM_DDEV_GLOBAL_CONFIG') ?: (getenv('HOME') ?: '') . '/.ddev/global_config.yaml')
    );
    $stage = new ResetStage($runner, new ExtensionInstallStage($environment));

    // Only the path matters to a stage that acts on a site that already exists.
    $spec = new SiteSpec($id, $install->path, '8.3', 33061, []);

    echo "Will, in order:\n";

    foreach ($stage->plan($id, $dryRun ? null : $zip) as $n => $step) {
        echo sprintf("  %d. %s\n", $n + 1, $step);
    }

    echo "\n";

    $report = $stage->run($spec, $id, $projectRoot, $zip, static function (string $line): void {
        echo $line . "\n";
    }, $dryRun);
} catch (SiteException | \InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");

    exit(1);
}

if ($dryRun) {
    echo "\nDry run: nothing was removed or installed.\n";

    exit(0);
}

echo "\nSite \"{$id}\" was reset.\n";

if ($report !== null) {
    echo '  ' . ($report->name ?? basename((string) $zip)) . ($report->version ? ' ' . $report->version : '') . " is installed again.\n";

    if ($report->warnings() !== []) {
        echo "\n  The installer reported " . \count($report->warnings()) . " warning(s). The install went through, but check them:\n";

        foreach ($report->warnings() as $warning) {
            echo "    - {$warning}\n";
        }
    }
}
