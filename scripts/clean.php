<?php

declare(strict_types=1);

/**
 * Remove every symlink cwm-link would have created.
 *
 * Useful when you want a Joomla install back to a clean state for a fresh
 * install test.
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
cwm-clean — remove every symlink cwm-link would create.

WHAT IT DOES
  Walks each configured install and unlinks anything that is currently a
  symlink at one of the expected target paths. **Only symlinks are touched** —
  real files and directories at those paths are left alone. Use this before
  a clean-install test to make sure you're testing the packaged build, not
  your dev tree.

PREREQUISITES
  - build.properties (run 'composer setup' first). The script also tolerates
    a missing cwm-build.config.json — it will scan every configured install
    using only the explicit link list (which will be empty), and warn.

USAGE
  composer clean
  composer clean -- -v          # print every removed link

OPTIONS
  -v, --verbose    Print every removed link.

RELATED
  composer link          # recreate symlinks afterwards
  composer link-check    # verify symlinks without recreating

HELP;

    exit(0);
}

$verbose = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);

$config = loadConfig($projectRoot);
$reader = new PropertiesReader($projectRoot . '/build.properties');

if (!$reader->exists()) {
    echo "build.properties not found — nothing to clean.\n";

    exit(0);
}

$resolver = new LinkResolver($projectRoot, $config);
$linker   = new Linker($verbose);
$removed  = 0;

foreach ($resolver->internalLinks() as $pair) {
    if ($linker->unlink($pair['target'])) {
        $removed++;

        if ($verbose) {
            echo "Removed: {$pair['target']}\n";
        }
    }
}

foreach ($reader->installs() as $install) {
    if (!is_dir($install->path)) {
        continue;
    }

    $count = 0;

    foreach ($resolver->externalLinks($install->path) as $pair) {
        if ($linker->unlink($pair['target'])) {
            $count++;

            if ($verbose) {
                echo "  Removed: {$pair['target']}\n";
            }
        }
    }

    echo "Cleaned {$install->path} ({$count} link" . ($count === 1 ? '' : 's') . ")\n";
    $removed += $count;
}

echo "\nRemoved {$removed} symlink(s).\n";

/**
 * @return array<string, mixed>
 */
function loadConfig(string $projectRoot): array
{
    $configFile = $projectRoot . '/cwm-build.config.json';

    if (!is_file($configFile)) {
        echo "Note: cwm-build.config.json not found in {$projectRoot} — only configured installs will be scanned.\n";

        return [];
    }

    $config = json_decode((string) file_get_contents($configFile), true);

    if (!is_array($config)) {
        fwrite(STDERR, "Warning: cwm-build.config.json is not valid JSON — proceeding without it.\n");

        return [];
    }

    return $config;
}
