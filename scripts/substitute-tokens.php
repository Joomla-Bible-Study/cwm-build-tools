<?php

/**
 * CLI entry for TokenSubstituter — used by cwm-release.
 *
 * Reads cwm-build.config.json's `versionTracking.substituteTokens` block
 * and replaces the configured token with the release version across the
 * configured source paths.
 *
 * Usage:
 *   php substitute-tokens.php -v 1.2.3
 *
 * Exits 0 with no output when the project hasn't opted in (no
 * substituteTokens block), so release.sh can call this unconditionally.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Release/TokenSubstituter.php';
require_once __DIR__ . '/../src/Config/ProfileResolver.php';

$projectRoot = getcwd();
$configFile  = $projectRoot . '/cwm-build.config.json';

if (!is_file($configFile)) {
    fwrite(STDERR, "Error: cwm-build.config.json not found in $projectRoot\n");
    exit(1);
}

$opts    = getopt('v:');
$version = $opts['v'] ?? null;

if (!$version) {
    fwrite(STDERR, "Usage: substitute-tokens.php -v <version>\n");
    exit(1);
}

$config = json_decode((string) file_get_contents($configFile), true);

if (!is_array($config)) {
    fwrite(STDERR, "Error: cwm-build.config.json is not valid JSON\n");
    exit(1);
}

$tracking         = CWM\BuildTools\Config\ProfileResolver::resolve($config);
$substituteConfig = $tracking['substituteTokens'] ?? null;

if (!is_array($substituteConfig)) {
    // Not opted in — silent no-op.
    exit(0);
}

$substituter = new CWM\BuildTools\Release\TokenSubstituter($projectRoot, $substituteConfig);

try {
    $touched = $substituter->substitute($version);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

if ($touched === []) {
    echo "  (no files contained the token)\n";
} else {
    echo "Substituted token in " . count($touched) . " file(s)\n";
}
