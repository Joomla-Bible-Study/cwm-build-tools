<?php

/**
 * CLI entry for ArtifactTokenScanner — used by cwm-release between build and push.
 *
 * Asserts the built artifact carries no unsubstituted placeholder tokens,
 * recursing into nested zips. This is the check that does not depend on
 * `substituteTokens.paths` being maintained correctly, on the vendored copy of
 * these tools being current, or on anyone remembering a new sub-extension.
 *
 * Usage:
 *   php verify-artifact-tokens.php -f build/dist/pkg_thing-1.2.3.zip
 *   php verify-artifact-tokens.php -f <artifact> --warn-only
 *
 * Exit codes:
 *   0  clean, not opted in, or --warn-only
 *   1  tokens found, or the artifact could not be read
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Release/ArtifactTokenScanner.php';
require_once __DIR__ . '/../src/Config/ProfileResolver.php';

$projectRoot = getcwd();
$configFile  = $projectRoot . '/cwm-build.config.json';

$opts     = getopt('f:', ['warn-only']);
$artifact = $opts['f'] ?? null;
$warnOnly = isset($opts['warn-only']);

if (!is_string($artifact) || $artifact === '') {
    fwrite(STDERR, "Usage: verify-artifact-tokens.php -f <artifact.zip> [--warn-only]\n");
    exit(1);
}

if (!is_file($configFile)) {
    fwrite(STDERR, "Error: cwm-build.config.json not found in $projectRoot\n");
    exit(1);
}

$config = json_decode((string) file_get_contents($configFile), true);

if (!is_array($config)) {
    fwrite(STDERR, "Error: cwm-build.config.json is not valid JSON\n");
    exit(1);
}

$tracking         = CWM\BuildTools\Config\ProfileResolver::resolve($config);
$substituteConfig = $tracking['substituteTokens'] ?? null;

// Gated on the same opt-in as substitution itself, for consistency with the
// rest of the pipeline — but said out loud rather than exiting silently. A
// project with no substituteTokens block can still ship the token if someone
// typed it, and that is precisely the case this check would catch.
if (!is_array($substituteConfig)) {
    echo "  Skipped: no versionTracking.substituteTokens configured.\n";
    exit(0);
}

if (!is_file($artifact)) {
    fwrite(STDERR, "Error: artifact not found: $artifact\n");
    exit(1);
}

$token   = $substituteConfig['token'] ?? '__DEPLOY_VERSION__';
$scanner = new CWM\BuildTools\Release\ArtifactTokenScanner((string) $token);

try {
    $findings = $scanner->scan($artifact);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($findings === []) {
    echo '  No unsubstituted ' . $token . ' in ' . basename($artifact) . ".\n";
    exit(0);
}

// Report every hit, not the first. All three failures that motivated this were
// multi-file, and one run should tell you the whole scope rather than making
// you re-cut to discover the next one.
$label = $warnOnly ? 'WARNING' : 'Error';

fwrite(STDERR, "\n$label: " . count($findings) . ' unsubstituted ' . $token
    . ' occurrence(s) in ' . basename($artifact) . ":\n\n");

foreach ($findings as $finding) {
    fwrite(STDERR, sprintf(
        "  %s:%d\n      %s\n",
        $finding['path'],
        $finding['line'],
        $finding['text'],
    ));
}

if ($warnOnly) {
    fwrite(STDERR, "\nContinuing anyway (--warn-only).\n");
    exit(0);
}

fwrite(STDERR, <<<TEXT

This artifact would ship the literal placeholder to sites. Nothing has been
pushed, tagged or published yet, so fixing it now costs only a re-run.

Usual causes:
  - a shipped directory missing from versionTracking.substituteTokens.paths
    (paths resolve from the repo root, so a sub-extension's own src/ is NOT
    covered by a bare "src/" entry)
  - a vendored cwm/build-tools older than the fix for the file in question
    (composer.lock is not evidence the installed tree is current)

TEXT);

exit(1);
