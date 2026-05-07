<?php

declare(strict_types=1);

/**
 * Print the latest stable Joomla version from the joomla-cms releases feed.
 *
 *   composer joomla-latest
 */

require_once __DIR__ . '/../src/Dev/JoomlaInstaller.php';

use CWM\BuildTools\Dev\JoomlaInstaller;

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<HELP
cwm-joomla-latest — print the latest stable Joomla version.

Queries the joomla/joomla-cms GitHub releases API and prints the tag name
plus published timestamp. Use the version with cwm-joomla-install.

HELP;

    exit(0);
}

try {
    $latest = (new JoomlaInstaller())->latest();
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");

    exit(1);
}

echo "Latest Joomla: {$latest['tag']}\n";

if ($latest['publishedAt'] !== '') {
    echo "Published:     {$latest['publishedAt']}\n";
}

echo "\nInstall with: composer joomla-install -- {$latest['tag']}\n";
