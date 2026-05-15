<?php

declare(strict_types=1);

/**
 * Create symlinks from this project into every configured Joomla install.
 *
 * Reads:
 *   - cwm-build.config.json (project structure → derived links + dev.links[])
 *   - build.properties      (Joomla install paths)
 *   - vendor/composer/installed.json (CWM dep joomlaLinks declarations)
 *
 * All symlinks are relative to the link's parent directory so the project
 * remains portable across machines and CI.
 */

require_once __DIR__ . '/../src/Config/CwmPackage.php';
require_once __DIR__ . '/../src/Config/InstalledPackageReader.php';
require_once __DIR__ . '/../src/Dev/InstallConfig.php';
require_once __DIR__ . '/../src/Dev/PropertiesReader.php';
require_once __DIR__ . '/../src/Dev/LinkResolver.php';
require_once __DIR__ . '/../src/Dev/Linker.php';

use CWM\BuildTools\Config\InstalledPackageReader;
use CWM\BuildTools\Dev\LinkResolver;
use CWM\BuildTools\Dev\Linker;
use CWM\BuildTools\Dev\PropertiesReader;

$projectRoot = getcwd() ?: '.';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<HELP
cwm-link — symlink this project's files into every configured Joomla install.

WHAT IT DOES
  For each install in build.properties, generates the conventional Joomla
  layout symlinks from the project's manifests AND from every Composer-
  installed CWM dep that declared a joomlaLinks block in its own
  composer.json:
    - components: admin/ + site/ + media/ mirrored
    - libraries:  libraries/<name> + administrator/manifests/libraries/<name>.xml
    - plugins:    plugins/<group>/<element>
    - modules:    modules/[admin]/<name>
  Plus any explicit dev.links[] / dev.internalLinks[] from the config.

  Idempotent: links already pointing at the expected source are reported as
  '= ok' and skipped. A symlink already in place but pointing somewhere
  else is reported as a CONFLICT — skipped without overwrite unless
  --force is given. All symlinks are created with **relative paths** so
  the dev tree stays portable across machines and CI.

PREREQUISITES
  - cwm-build.config.json in the current directory
  - build.properties (run 'composer setup' first, or copy from
    templates/build.properties.tmpl)

USAGE
  composer link
  composer link -- -v           # verbose: print each link
  composer link -- --force      # overwrite conflicting symlinks

OPTIONS
  -v, --verbose    Print every link as it is created.
  -f, --force      Overwrite existing symlinks even when they point
                   somewhere other than the expected source.

EXIT CODE
  0 on success (all links ok/created), 1 when a conflict was reported
  and --force was not given.

RELATED
  composer link-check    # verify symlinks without recreating
  composer clean         # remove every dev symlink
  composer verify        # confirm extensions registered in #__extensions

HELP;

    exit(0);
}

$verbose = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);
$force   = in_array('-f', $argv, true) || in_array('--force', $argv, true);

$config = loadConfig($projectRoot);
$reader = new PropertiesReader($projectRoot . '/build.properties');

if (!$reader->exists()) {
    fwrite(STDERR, "build.properties not found. Run 'composer setup' first.\n");

    exit(1);
}

$installs = $reader->installs();

if ($installs === []) {
    fwrite(STDERR, "No Joomla installs configured in build.properties.\n");

    exit(1);
}

$resolver       = new LinkResolver($projectRoot, $config);
$linker         = new Linker($verbose);
$packageReader  = new InstalledPackageReader($projectRoot);
$cwmPackages    = $packageReader->cwmPackages();
$totalConflicts = 0;

foreach ($resolver->internalLinks() as $pair) {
    $linker->link($pair['source'], $pair['target']);
}

$linkedInstalls = 0;

foreach ($installs as $install) {
    if (!is_dir($install->path)) {
        echo "WARNING: Path not found, skipping: {$install->path}\n";

        continue;
    }

    echo "\nLinking against: {$install->path}\n";

    $selfLinks = $resolver->externalLinks($install->path);
    $depLinks  = $resolver->externalLinksForPackages($install->path, $cwmPackages);

    if ($selfLinks === [] && $depLinks === []) {
        echo "  (no links derived — check dev.links[] / manifests.extensions[] / vendor cwm deps)\n";

        continue;
    }

    $totalConflicts += applyLinks($linker, 'Self (' . ($config['extension']['name'] ?? 'this project') . ')', $selfLinks, $force, $verbose, null);

    if ($depLinks !== []) {
        $byPackage = [];

        foreach ($depLinks as $link) {
            $byPackage[$link['package']][] = $link;
        }

        echo "\n  CWM dependencies (" . count($byPackage) . ")\n";

        foreach ($byPackage as $pkgName => $links) {
            $package = null;

            foreach ($cwmPackages as $p) {
                if ($p->name === $pkgName) {
                    $package = $p;
                    break;
                }
            }

            $tag = $package === null
                ? ''
                : " @ {$package->version} (" . ($package->isPathRepo ? 'path' : 'registry') . ')';

            echo "    {$pkgName}{$tag}\n";

            $totalConflicts += applyLinks($linker, '      ', $links, $force, $verbose, $pkgName);
        }
    }

    $linkedInstalls++;
}

if ($totalConflicts > 0 && !$force) {
    echo "\n{$totalConflicts} conflict(s) skipped. Re-run with --force to overwrite.\n";

    exit(1);
}

echo "\nDone. Linked into {$linkedInstalls} install(s).\n";

exit(0);

/**
 * Apply a list of link pairs through the Linker with conflict detection,
 * returning the number of conflicts (links skipped because existing target
 * differs from expected and --force was not given).
 *
 * @param  list<array{source: string, target: string}> $links
 */
function applyLinks(Linker $linker, string $heading, array $links, bool $force, bool $verbose, ?string $packageName): int
{
    if ($links === []) {
        return 0;
    }

    if ($packageName === null && $heading !== '') {
        echo "  {$heading}\n";
    }

    $created   = 0;
    $okCount   = 0;
    $conflicts = 0;
    $indent    = $packageName === null ? '    ' : '        ';

    foreach ($links as $pair) {
        $check = $linker->check($pair['source'], $pair['target']);

        switch ($check['status']) {
            case 'ok':
                $okCount++;

                if ($verbose) {
                    echo "{$indent}= ok       {$pair['target']}\n";
                }
                break;

            case 'wrong':
                $existing = $check['existingRealpath'] ?? '(unknown)';

                if ($force) {
                    $linker->link($pair['source'], $pair['target']);
                    $created++;
                    echo "{$indent}~ replaced {$pair['target']}  (was -> {$existing})\n";
                } else {
                    $conflicts++;
                    echo "{$indent}! conflict {$pair['target']}\n";
                    echo "{$indent}           expected: {$pair['source']}\n";
                    echo "{$indent}           found:    {$existing}\n";
                }
                break;

            case 'broken':
                $linker->link($pair['source'], $pair['target']);
                $created++;
                echo "{$indent}~ relinked {$pair['target']}  (was broken)\n";
                break;

            case 'stale':
            case 'missing':
            default:
                $linker->link($pair['source'], $pair['target']);
                $created++;

                if ($verbose) {
                    echo "{$indent}+ created  {$pair['target']}\n";
                }
                break;
        }
    }

    if (!$verbose) {
        echo "{$indent}{$created} created, {$okCount} ok"
            . ($conflicts > 0 ? ", {$conflicts} conflict(s)" : '')
            . "\n";
    }

    return $conflicts;
}

/**
 * @return array<string, mixed>
 */
function loadConfig(string $projectRoot): array
{
    $configFile = $projectRoot . '/cwm-build.config.json';

    if (!is_file($configFile)) {
        fwrite(STDERR, "cwm-build.config.json not found in {$projectRoot}\n");

        exit(1);
    }

    $config = json_decode((string) file_get_contents($configFile), true);

    if (!is_array($config)) {
        fwrite(STDERR, "cwm-build.config.json is not valid JSON.\n");

        exit(1);
    }

    return $config;
}
