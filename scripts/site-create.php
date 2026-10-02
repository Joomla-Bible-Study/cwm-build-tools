<?php

declare(strict_types=1);

/**
 * Provision a disposable Joomla dev/test site.
 *
 *   composer site-create -- j6 --path /abs/path/Sites/j6
 *   composer site-create -- j6 --dry-run
 */

require_once __DIR__ . '/../src/Cli/Flags.php';
require_once __DIR__ . '/../src/Build/DistZipResolver.php';
require_once __DIR__ . '/../src/Dev/JoomlaInstaller.php';
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
require_once __DIR__ . '/../src/Site/JoomlaSource.php';
require_once __DIR__ . '/../src/Site/GithubJoomlaSource.php';
require_once __DIR__ . '/../src/Site/JoomlaSettings.php';
require_once __DIR__ . '/../src/Site/JoomlaInstallStage.php';
require_once __DIR__ . '/../src/Site/InstallReport.php';
require_once __DIR__ . '/../src/Site/ExtensionInstallStage.php';

use CWM\BuildTools\Build\DistZipResolver;
use CWM\BuildTools\Cli\Flags;
use CWM\BuildTools\Site\DdevConfig;
use CWM\BuildTools\Site\DdevEnvironment;
use CWM\BuildTools\Site\ExtensionInstallStage;
use CWM\BuildTools\Site\GithubJoomlaSource;
use CWM\BuildTools\Site\JoomlaInstallStage;
use CWM\BuildTools\Site\JoomlaSettings;
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
  1. Creates the web, PHP and database stack for a site with DDEV, with the
     project's source tree mounted so the symlinks cwm-link writes resolve
     inside the container.
  2. Downloads Joomla and runs its headless installer inside the container, in
     place of the web installer.

  3. Installs the project's built package into the site through Joomla's
     installer, and shows the installer's own messages, including any warning
     an extension's install script raises.

  Build the package first (composer package / composer build). Linking the
  source tree into the site, and registering the site, are the next stages.

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
      --joomla <x.y.z>  Joomla release to install. Default: the latest stable.
      --site-name <s>   Joomla site name. Default: the site id.
      --admin-user <s>  Super User login. Default: admin.
      --admin-password  Super User password, 12+ characters. Default: a random
                        one, printed once at the end.
      --admin-email <s> Super User email. Default: admin@example.com.
      --package <p>     The built zip to install: a path, "auto" (the newest zip
                        matching build.outputGlob in cwm-build.config.json; the
                        default, skipped with a note if there is none) or "none".
      --stack-only      Stop after the DDEV stack; do not install Joomla.
      --force           Reconfigure a site that already exists, in place.
      --dry-run         Print what would happen and change nothing.
  -h, --help            Show this.

ENVIRONMENT
  CWM_JOOMLA_PACKAGE_URL   Fetch the Joomla package from here (a mirror, or a
                           file:// zip) instead of GitHub.

HELP;

    exit(0);
}

// The first argument that is neither a flag nor the value of one is the site id.
$valueFlags = ['--path', '--php', '--db-port', '--source', '--joomla', '--site-name', '--admin-user', '--admin-password', '--admin-email', '--package'];
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

    $installJoomla = !Flags::has($argv, ['--stack-only']);

    // Decided before anything starts, so a bad --package fails now and not after
    // several minutes of provisioning.
    $zip       = null;
    $packageOn = Flags::value($argv, '--package') ?? 'auto';

    if ($installJoomla && $packageOn !== 'none') {
        $resolver = new DistZipResolver();

        try {
            if ($packageOn === 'auto') {
                $configFile = $source . '/cwm-build.config.json';
                $config     = is_file($configFile) ? json_decode((string) file_get_contents($configFile), true) : null;
                $zip        = $resolver->resolveFromGlob($source, (string) (is_array($config) ? ($config['build']['outputGlob'] ?? '') : ''));
            } else {
                $zip = $resolver->resolveExplicit($projectRoot, $packageOn);
            }
        } catch (\RuntimeException $e) {
            if ($packageOn !== 'auto') {
                throw new SiteException(str_replace('--zip path', '--package path', $e->getMessage()));
            }

            echo "Note: no built package to install, so only Joomla will be set up.\n  "
                . str_replace("\n", "\n  ", $e->getMessage()) . "\n\n";
        }
    }
    $generated     = Flags::value($argv, '--admin-password') === null;
    $settings      = new JoomlaSettings(
        Flags::value($argv, '--joomla'),
        Flags::value($argv, '--site-name') ?? $id,
        Flags::value($argv, '--admin-user') ?? 'admin',
        Flags::value($argv, '--admin-password') ?? JoomlaSettings::randomPassword(),
        Flags::value($argv, '--admin-email') ?? 'admin@example.com',
        JoomlaSettings::randomPrefix()
    );
    $stage = new JoomlaInstallStage(
        $environment,
        new GithubJoomlaSource(new CWM\BuildTools\Dev\JoomlaInstaller(), getenv('CWM_JOOMLA_PACKAGE_URL') ?: null)
    );

    $extensions = new ExtensionInstallStage($environment);

    if (Flags::has($argv, ['--dry-run'])) {
        echo "Would do, in order:\n";

        $n = 0;

        foreach ($environment->plan($spec) as $step) {
            echo str_starts_with($step, '    ') ? $step . "\n" : \sprintf("  %d. %s\n", ++$n, $step);
        }

        if ($installJoomla) {
            foreach ($stage->plan($spec, $settings) as $step) {
                echo \sprintf("  %d. %s\n", ++$n, $step);
            }
        }

        if ($zip !== null) {
            foreach ($extensions->plan($spec, $zip) as $step) {
                echo \sprintf("  %d. %s\n", ++$n, $step);
            }
        }

        exit(0);
    }

    $log = static function (string $line): void {
        echo $line . "\n";
    };

    $environment->provision($spec, $log);

    $version = $installJoomla ? $stage->run($spec, $settings, $log) : null;
    $report  = null;

    if ($zip !== null) {
        $age = max(0, time() - (int) filemtime($zip));

        $log(\sprintf('Using %s (built %s ago)', $zip, $age < 120 ? $age . ' seconds' : ($age < 7200 ? intdiv($age, 60) . ' minutes' : intdiv($age, 3600) . ' hours')));
        $report = $extensions->run($spec, $zip, $log);
    }
} catch (SiteException | \InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");

    exit(1);
}

if ($version === null) {
    echo "\nSite stack is running. Re-run without --stack-only to install Joomla.\n";

    exit(0);
}

echo "\nJoomla {$version} is installed.\n";

if ($report !== null) {
    echo '  ' . ($report->name ?? basename((string) $zip)) . ($report->version ? ' ' . $report->version : '') . " is installed.\n";

    if ($report->warnings() !== []) {
        echo "\n  The installer reported " . \count($report->warnings()) . " warning(s). The install went through, but check them:\n";

        foreach ($report->warnings() as $warning) {
            echo "    - {$warning}\n";
        }

        echo "\n";
    }
}

echo '  Site:   ' . $environment->url($spec) . "/\n";
echo '  Admin:  ' . $environment->url($spec) . "/administrator/\n";
echo "  Login:  {$settings->adminUsername}\n";
echo $generated
    ? "  Password (generated, shown once): {$settings->adminPassword}\n"
    : "  Password: the one you passed.\n";
echo "  Browsers warn about the certificate until you run: mkcert -install\n";
