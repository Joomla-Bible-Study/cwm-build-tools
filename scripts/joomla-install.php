<?php

declare(strict_types=1);

/**
 * Download and extract a Joomla full-package release into each configured
 * install path. Skips paths that already contain files unless --force.
 *
 *   composer joomla-install              # use each install's configured version
 *   composer joomla-install -- 5.4.2     # override version
 *   composer joomla-install -- --force   # overwrite existing directory
 */

require_once __DIR__ . '/../src/Dev/InstallConfig.php';
require_once __DIR__ . '/../src/Dev/PropertiesReader.php';
require_once __DIR__ . '/../src/Dev/JoomlaInstaller.php';

use CWM\BuildTools\Dev\JoomlaInstaller;
use CWM\BuildTools\Dev\PropertiesReader;

$projectRoot = getcwd() ?: '.';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<HELP
cwm-joomla-install — download a Joomla release into every configured install.

Reads build.properties for install paths and per-install version. Each
non-empty positional argument that is not a flag is treated as a version
override applied to all installs.

Options:
      --force       Wipe the install path before extracting

HELP;

    exit(0);
}

$force = in_array('--force', $argv, true);

$override = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg !== '' && $arg[0] !== '-') {
        $override = $arg;

        break;
    }
}

$reader = new PropertiesReader($projectRoot . '/build.properties');

if (!$reader->exists()) {
    fwrite(STDERR, "build.properties not found. Run 'composer setup' first.\n");

    exit(1);
}

$installs  = $reader->installs();
$installer = new JoomlaInstaller();

if ($installs === []) {
    fwrite(STDERR, "No Joomla installs configured in build.properties.\n");

    exit(1);
}

foreach ($installs as $install) {
    $version = $override ?? $install->version;

    if ($version === null || $version === '') {
        echo "Skipping {$install->path}: no version configured (set [{$install->id}] version=...).\n";

        continue;
    }

    if (is_dir($install->path) && (new \FilesystemIterator($install->path))->valid()) {
        if (!$force) {
            echo "Skipping {$install->path}: directory not empty (pass --force to wipe).\n";

            continue;
        }

        emptyDirectory($install->path);
    }

    try {
        $installer->install($version, $install->path);
    } catch (\Throwable $e) {
        echo "ERROR ({$install->path}): " . $e->getMessage() . "\n";
    }
}

function emptyDirectory(string $path): void
{
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
}
