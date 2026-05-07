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

Walks each configured install and unlinks anything that is currently a
symlink at the expected target path. Real files / directories at those
paths are left alone.

Options:
  -v, --verbose    Print every removed link.

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
        return [];
    }

    $config = json_decode((string) file_get_contents($configFile), true);

    return is_array($config) ? $config : [];
}
