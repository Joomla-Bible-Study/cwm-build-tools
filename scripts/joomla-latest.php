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

WHAT IT DOES
  Queries https://api.github.com/repos/joomla/joomla-cms/releases/latest
  and prints the tag name plus published timestamp. Useful for deciding
  what to pass to cwm-joomla-install or to bump the per-install version
  in build.properties.

PREREQUISITES
  - Network access to api.github.com (no auth required for this endpoint;
    rate-limited to 60/hr unauthenticated, which is plenty for occasional
    interactive use)

USAGE
  composer joomla-latest

OUTPUT
  Latest Joomla: 5.4.2
  Published:     2026-04-15T12:00:00Z

  Install with: composer joomla-install -- 5.4.2

RELATED
  composer joomla-install   # download Joomla into each configured path

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
