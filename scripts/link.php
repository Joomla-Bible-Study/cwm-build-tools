<?php

declare(strict_types=1);

/**
 * Create symlinks from this project into every configured Joomla install.
 *
 * Reads:
 *   - cwm-build.config.json (project structure → derived links + dev.links[])
 *   - build.properties      (Joomla install paths)
 *
 * All symlinks are relative to the link's parent directory so the project
 * remains portable across machines and CI.
 */

require_once __DIR__ . '/../src/Dev/InstallConfig.php';
require_once __DIR__ . '/../src/Dev/PropertiesReader.php';
require_once __DIR__ . '/../src/Dev/LinkResolver.php';
require_once __DIR__ . '/../src/Dev/Linker.php';

use CWM\BuildTools\Dev\LinkResolver;
use CWM\BuildTools\Dev\Linker;
use CWM\BuildTools\Dev\PropertiesReader;

$projectRoot = getcwd() ?: '.';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<HELP
cwm-link — symlink this project's files into every configured Joomla install.

WHAT IT DOES
  For each install in build.properties, generates the conventional Joomla
  layout symlinks from the project's manifests:
    - components: admin/ + site/ + media/ mirrored
    - libraries:  libraries/<name> + administrator/manifests/libraries/<name>.xml
    - plugins:    plugins/<group>/<element>
    - modules:    modules/[admin]/<name>
  Plus any explicit dev.links[] / dev.internalLinks[] from the config.

  Existing files / directories / stale symlinks at link paths are replaced.
  All symlinks are created with **relative paths** so the dev tree stays
  portable across machines and CI.

PREREQUISITES
  - cwm-build.config.json in the current directory
  - build.properties (run 'composer setup' first, or copy from
    templates/build.properties.tmpl)

USAGE
  composer link
  composer link -- -v           # verbose: print each link

OPTIONS
  -v, --verbose    Print every link as it is created.

RELATED
  composer link-check    # verify symlinks without recreating
  composer clean         # remove every dev symlink
  composer verify        # confirm extensions registered in #__extensions

HELP;

    exit(0);
}

$verbose = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);

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

$resolver = new LinkResolver($projectRoot, $config);
$linker   = new Linker($verbose);

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

    $links = $resolver->externalLinks($install->path);

    if ($links === []) {
        echo "  (no links derived — check dev.links[] / manifests.extensions[])\n";

        continue;
    }

    foreach ($links as $pair) {
        $linker->link($pair['source'], $pair['target']);
    }

    if (!$verbose) {
        echo "  Created " . count($links) . " links.\n";
    }

    $linkedInstalls++;
}

echo "\nDone. Linked into {$linkedInstalls} install(s).\n";

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
