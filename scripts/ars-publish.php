<?php

/**
 * Publish a release artifact to Akeeba Release System (ARS).
 *
 * Reads the project's cwm-build.config.json for the ARS endpoint and
 * category. Authenticates via the ARS JSON API token (looked up from
 * 1Password CLI when available, environment variable as fallback).
 *
 * Usage:
 *   php ars-publish.php -v <version> -f <path-to-zip>
 *
 * Phase 1: this is a stub. The full ARS API flow needs to:
 *   1. Resolve the ARS category id from the configured category name
 *   2. Create a Release entity (if not present for this version)
 *   3. Upload the zip as an Item attached to the release
 *   4. Mark the release as Published
 *
 * @license GPL-2.0-or-later
 */

$projectRoot = getcwd();
$configFile  = $projectRoot . '/cwm-build.config.json';

$opts    = getopt('v:f:');
$version = $opts['v'] ?? null;
$file    = $opts['f'] ?? null;

if (!$version || !$file) {
    fwrite(STDERR, "Usage: ars-publish.php -v <version> -f <path-to-zip>\n");
    exit(1);
}

if (!is_file($file)) {
    fwrite(STDERR, "Error: artifact not found: $file\n");
    exit(1);
}

$config = is_file($configFile) ? json_decode(file_get_contents($configFile), true) : [];
$ars    = $config['ars'] ?? null;

if (!$ars || empty($ars['endpoint'])) {
    fwrite(STDERR, "Error: ars.endpoint not configured in cwm-build.config.json\n");
    exit(1);
}

echo "ARS publish (Phase 1 stub)\n";
echo "  endpoint: {$ars['endpoint']}\n";
echo "  category: " . ($ars['category'] ?? '(unset)') . "\n";
echo "  version:  $version\n";
echo "  file:     $file (" . filesize($file) . " bytes)\n";
echo "\n";
echo "TODO: implement ARS API upload. Until then, upload manually at\n";
echo "  {$ars['endpoint']}\n";
echo "\n";
echo "Tracked in cwm-build-tools roadmap Phase 1.\n";
