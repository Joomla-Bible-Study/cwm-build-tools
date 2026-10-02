<?php

declare(strict_types=1);

/**
 * Apply or remove a project's seed data on a site.
 *
 *   composer seed -- --list
 *   composer seed -- test                      # every role=test site
 *   composer seed -- test --install j6         # one named site, dev or test
 *   composer seed -- test --install j6 --remove
 */

require_once __DIR__ . '/../src/Cli/Flags.php';
require_once __DIR__ . '/../src/Dev/InstallConfig.php';
require_once __DIR__ . '/../src/Dev/PropertiesReader.php';
require_once __DIR__ . '/../src/Site/CommandResult.php';
require_once __DIR__ . '/../src/Site/CommandRunner.php';
require_once __DIR__ . '/../src/Site/ProcessRunner.php';
require_once __DIR__ . '/../src/Seed/SeedException.php';
require_once __DIR__ . '/../src/Seed/SeedLayer.php';
require_once __DIR__ . '/../src/Seed/SeedConfig.php';
require_once __DIR__ . '/../src/Seed/SeedTarget.php';
require_once __DIR__ . '/../src/Seed/SeedRunner.php';

use CWM\BuildTools\Cli\Flags;
use CWM\BuildTools\Dev\PropertiesReader;
use CWM\BuildTools\Seed\SeedConfig;
use CWM\BuildTools\Seed\SeedException;
use CWM\BuildTools\Seed\SeedRunner;
use CWM\BuildTools\Seed\SeedTarget;
use CWM\BuildTools\Site\ProcessRunner;

if (Flags::has($argv, ['--help', '-h'])) {
    echo <<<HELP
cwm-seed — apply or remove a project's seed data on a site.

WHAT IT DOES
  Runs the project's seed layers against a site. A layer is a PHP script the
  project owns, declared in the "seed" block of cwm-build.config.json; this tool
  owns none of the data. Each layer is run as `php <script> apply` (or `remove`)
  from the project root, and is told which site it is working on through
  environment variables:

    CWM_SEED_ACTION     apply | remove
    CWM_SEED_LAYER      the layer's name
    CWM_SEED_MARKER     the prefix every seeded row must carry, so --remove
                        can find exactly what was written and nothing else
    CWM_SEED_SITE_ID    the install's id in build.properties
    CWM_SEED_SITE_PATH  the Joomla folder
    CWM_SEED_SITE_ROLE  dev | test
    CWM_SEED_DB_HOST    where the host reaches the database (when recorded)

  A layer script gets its site with TestSite::fromSeedEnvironment(). Applying
  stops at the first failing layer. Removing runs the layers in reverse and
  carries on past a failure, so cleanup tries everything.

  Seed data is not demo data. Demo data is small, clean and meant for users;
  seed data is for developers and CI, and is deliberately awkward.

WHERE IT RUNS
  Only on an install listed in your build.properties. With no --install, that is
  every role=test install. A role=dev install is somebody's working copy, so it
  is seeded only when you name it.

USAGE
  composer seed -- --list
  composer seed -- <profile>
  composer seed -- --layer <name> [--layer <name>]
  composer seed -- <profile> --install <id>
  composer seed -- <profile> --install <id> --remove

OPTIONS
      --layer <name>  A layer to run; repeatable. Run in the order the project
                      declared them, however they are typed here.
      --install <id>  Seed this install only. It may be role=dev.
      --remove        Remove the seeded data instead of applying it.
      --dry-run       Show what would run, and run nothing.
      --list          Show the project's marker, layers and profiles.
  -h, --help          Show this.

HELP;

    exit(0);
}

$projectRoot = realpath(getcwd() ?: '.') ?: '.';

// Repeatable --layer, and the first bare argument as the profile.
$layerNames  = [];
$profile     = null;
$valueFlags  = ['--layer', '--install'];

foreach (\array_slice($argv, 1) as $i => $arg) {
    $previous = $argv[$i] ?? '';

    if ($previous === '--layer' && $arg !== '' && $arg[0] !== '-') {
        $layerNames[] = $arg;

        continue;
    }

    if (str_starts_with($arg, '--layer=')) {
        $layerNames[] = substr($arg, \strlen('--layer='));

        continue;
    }

    if ($arg !== '' && $arg[0] !== '-' && !\in_array($previous, $valueFlags, true) && $profile === null) {
        $profile = $arg;
    }
}

try {
    $configFile = $projectRoot . '/cwm-build.config.json';
    $decoded    = is_file($configFile) ? json_decode((string) file_get_contents($configFile), true) : null;

    if (!\is_array($decoded)) {
        throw new SeedException('cwm-build.config.json not found or not valid JSON in ' . $projectRoot . '.');
    }

    $config = SeedConfig::fromProjectConfig($decoded, $projectRoot);

    if (Flags::has($argv, ['--list'])) {
        echo "Marker:   {$config->marker}\n\nLayers:\n";

        foreach ($config->layers as $layer) {
            echo sprintf("  %-12s %s%s\n", $layer->name, $layer->script, $layer->description !== '' ? '  — ' . $layer->description : '');
        }

        echo "\nProfiles:\n";

        foreach ($config->profiles as $name => $names) {
            echo sprintf("  %-12s %s%s\n", $name, implode(', ', $names), $name === $config->defaultProfile ? '  (default)' : '');
        }

        exit(0);
    }

    $layers  = $config->select($profile, $layerNames);
    $targets = SeedTarget::select(new PropertiesReader($projectRoot . '/build.properties'), Flags::value($argv, '--install'));
    $action  = Flags::has($argv, ['--remove']) ? SeedRunner::REMOVE : SeedRunner::APPLY;
    $dryRun  = Flags::has($argv, ['--dry-run']);
    $runner  = new SeedRunner(new ProcessRunner());
    $names   = implode(', ', array_map(static fn ($l): string => $l->name, $layers));
    $ordered = $action === SeedRunner::REMOVE ? implode(', ', array_reverse(array_map(static fn ($l): string => $l->name, $layers))) : $names;

    foreach ($targets as $install) {
        echo sprintf("%s %s (role %s): %s\n", $dryRun ? 'Would ' . $action : ucfirst($action) . 'ing', $install->id, $install->role, $ordered);

        if ($dryRun) {
            continue;
        }

        $runner->run($config, $layers, $action, $install, $projectRoot, static function (string $line): void {
            echo '  ' . $line . "\n";
        });
    }
} catch (SeedException | \InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");

    exit(1);
}

echo $dryRun ? "\nDry run: nothing was run.\n" : "\nDone.\n";
