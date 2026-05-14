<?php

/**
 * Print a single resolved value from the project's versionTracking block.
 *
 * Used by release.sh as a gate check (e.g. "is versionsJson configured?").
 * Goes through ProfileResolver so consumers that declare a profile get the
 * same answer as consumers that hand-author the block — release.sh can't
 * tell whether a value comes from the profile or an inline override, and
 * shouldn't need to.
 *
 * Usage:
 *   php resolve-tracking.php versionsJson
 *   php resolve-tracking.php substituteTokens.token
 *
 * Prints the resolved value to stdout (empty when unset), exits 0 always.
 * Bash gate checks read the output and branch on `-z`.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/ProfileResolver.php';

$key = $argv[1] ?? null;

if ($key === null) {
    fwrite(STDERR, "Usage: resolve-tracking.php <dotted-key>\n");
    exit(1);
}

$configFile = getcwd() . '/cwm-build.config.json';

if (!is_file($configFile)) {
    exit(0);
}

$config = json_decode((string) file_get_contents($configFile), true);

if (!is_array($config)) {
    exit(0);
}

$tracking = CWM\BuildTools\Config\ProfileResolver::resolve($config);

if ($tracking === null) {
    exit(0);
}

$value = $tracking;

foreach (explode('.', $key) as $segment) {
    if (!is_array($value) || !array_key_exists($segment, $value)) {
        exit(0);
    }

    $value = $value[$segment];
}

if (is_scalar($value)) {
    echo $value;
}
