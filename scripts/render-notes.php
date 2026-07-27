<?php

declare(strict_types=1);

/**
 * Render Markdown release notes as the HTML fragment ARS expects.
 *
 * Reads Markdown on stdin, writes HTML on stdout, so ars-publish.sh can pipe
 * the GitHub release body (or a hand-written notes file) straight through.
 *
 * Usage:
 *   printf '%s' "$RELEASE_NOTES" | php scripts/render-notes.php
 */

use CWM\BuildTools\Release\ReleaseNotesFormatter;

$autoloaders = [
    __DIR__ . '/../vendor/autoload.php',       // standalone checkout
    __DIR__ . '/../../../autoload.php',        // installed as a dependency
];

foreach ($autoloaders as $autoloader) {
    if (is_file($autoloader)) {
        require $autoloader;
        break;
    }
}

if (!class_exists(ReleaseNotesFormatter::class)) {
    fwrite(STDERR, "render-notes.php: could not locate the Composer autoloader.\n");
    exit(1);
}

$markdown = stream_get_contents(STDIN);

if ($markdown === false) {
    fwrite(STDERR, "render-notes.php: could not read stdin.\n");
    exit(1);
}

echo (new ReleaseNotesFormatter())->toHtml($markdown);