<?php

declare(strict_types=1);

/**
 * Provision a disposable Joomla dev/test site.
 *
 *   composer site-create -- j6 --path /abs/path/Sites/j6
 *   composer site-create -- j6 --dry-run
 */

require_once __DIR__ . '/../src/Cli/Flags.php';
require_once __DIR__ . '/../src/Site/Mount.php';
require_once __DIR__ . '/../src/Site/MountPlanner.php';
require_once __DIR__ . '/../src/Site/DdevConfig.php';
require_once __DIR__ . '/../src/Site/SiteException.php';
require_once __DIR__ . '/../src/Site/SiteSpec.php';
require_once __DIR__ . '/../src/Site/CommandResult.php';
require_once __DIR__ . '/../src/Site/CommandRunner.php';
require_once __DIR__ . '/../src/Site/ProcessRunner.php';
require_once __DIR__ . '/../src/Site/PathResolver.php';
require_once __DIR__ . '/../src/Site/PortFinder.php';
require_once __DIR__ . '/../src/Site/DdevEnvironment.php';

use CWM\BuildTools\Cli\Flags;
use CWM\BuildTools\Site\DdevConfig;
use CWM\BuildTools\Site\DdevEnvironment;
use CWM\BuildTools\Site\MountPlanner;
use CWM\BuildTools\Site\PathResolver;
use CWM\BuildTools\Site\PortFinder;
use CWM\BuildTools\Site\ProcessRunner;
use CWM\BuildTools\Site\SiteException;
use CWM\BuildTools\Site\SiteSpec;

if (Flags::has($argv, ['--help', '-h'])) {
    echo <<<HELP
cwm-site-create — provision a disposable Joomla dev/test site.

WHAT IT DOES
  Creates the web, PHP and database stack for a site with DDEV, with the
  project's source tree mounted so the symlinks cwm-link writes resolve inside
  the container. This stage stops once the stack is running; Joomla and the
  project's extensions are installed by the stages that follow.

PREREQUISITES
  - Docker (Docker Desktop, OrbStack or Colima) running
  - ddev installed (macOS: brew install ddev/ddev/ddev)
  - DDEV's usage-statistics question answered once:
      ddev config global --instrumentation-opt-in=false   (or =true)
    This command will not answer it for you.

USAGE
  composer site-create -- <id> [options]

OPTIONS
      --path <dir>      Where the site lives. Default: a folder named <id> beside
                        the project.
      --php <x.y>       PHP version. Default 8.3.
      --db-port <n>     Host port for the database. Default: first free port from
                        33061, so several sites can run together.
      --source <dir>    Source tree to mount. Default: the current project.
      --force           Reconfigure a site that already exists, in place.
      --dry-run         Print what would happen and change nothing.
  -h, --help            Show this.

HELP;

    exit(0);
}

// The first argument that is neither a flag nor the value of one is the site id.
$valueFlags = ['--path', '--php', '--db-port', '--source'];
$id         = null;

foreach (\array_slice($argv, 1) as $i => $arg) {
    $previous = $argv[$i] ?? '';

    if ($arg !== '' && $arg[0] !== '-' && !\in_array($previous, $valueFlags, true)) {
        $id = $arg;

        break;
    }
}

if ($id === null) {
    fwrite(STDERR, "Usage: cwm-site-create <id> [options]. Run with --help.\n");

    exit(1);
}

$projectRoot = realpath(getcwd() ?: '.') ?: '.';
$source      = realpath(Flags::value($argv, '--source') ?? $projectRoot);

if ($source === false) {
    fwrite(STDERR, "--source does not exist.\n");

    exit(1);
}

$path = (new PathResolver())->canonical(Flags::value($argv, '--path') ?? \dirname($projectRoot) . '/' . $id);

try {
    $port = Flags::value($argv, '--db-port');
    $spec = new SiteSpec(
        $id,
        $path,
        Flags::value($argv, '--php') ?? '8.3',
        $port !== null ? (int) $port : (new PortFinder())->firstFree(33061),
        [$source],
        Flags::has($argv, ['--force']),
    );

    $environment = new DdevEnvironment(
        new ProcessRunner(),
        new DdevConfig(),
        new MountPlanner(),
        (getenv('CWM_DDEV_GLOBAL_CONFIG') ?: (getenv('HOME') ?: '') . '/.ddev/global_config.yaml')
    );

    if (Flags::has($argv, ['--dry-run'])) {
        echo "Would do, in order:\n";

        $n = 0;

        foreach ($environment->plan($spec) as $step) {
            echo str_starts_with($step, '    ') ? $step . "\n" : \sprintf("  %d. %s\n", ++$n, $step);
        }

        exit(0);
    }

    $environment->provision($spec, static function (string $line): void {
        echo $line . "\n";
    });
} catch (SiteException | \InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");

    exit(1);
}

echo "\nSite stack is running. The next stages install Joomla and link the project.\n";
