<?php

declare(strict_types=1);

/**
 * Post-release check: can the published update stream actually reach sites?
 */

require_once __DIR__ . '/../src/Release/UpdateStreamVerifier.php';

use CWM\BuildTools\Release\UpdateStreamVerifier;

$projectRoot = getcwd() ?: '.';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<CWM_HELP
cwm-verify-update-stream — check that a published release is reachable.

WHAT IT DOES
  Fetches the update stream declared by the package manifest and asserts that
  the released version is there and usable:

    - an <update> entry exists at that exact version;
    - it carries <php_minimum>;
    - it carries <targetplatform version="...">.

  Every one of those fails silently in production. The release publishes, the
  feed stays valid, and some population of sites is simply never offered the
  update — which is how null ARS environments mis-shipped three Proclaim
  releases in a row.

  Runs AFTER publish, because everything else in the release pipeline runs
  before it and cannot see what the stream serves.

PREREQUISITES
  - manifests.package in cwm-build.config.json, declaring <updateservers>
  - the release already published to ARS

USAGE
  composer cwm-verify-update-stream            # version from the manifest
  composer cwm-verify-update-stream -- 1.3.0
  composer cwm-verify-update-stream -- --url https://staging.test/updates.xml

OPTIONS
  --url <url>    Check this stream instead of the manifest's. For a staging
                 stream, or for exercising the check against a fixture —
                 without it the assertions could only ever run against
                 production, which is no way to know they can fail.
  -h, --help     Show this and exit.

PROJECT-SPECIFIC ASSERTIONS
  A feed can carry entries only one extension cares about — a legacy component
  entry, a required sort order. That is the project's knowledge, so it is not
  checked here. Use CWM\\BuildTools\\Release\\UpdateStreamVerifier::entriesFor()
  to get the parsed rows, in document order with their positions, and assert on
  top rather than forking the fetcher.

EXIT CODE
  0  the released version is served and usable
  1  it is not, or the stream could not be fetched or parsed

  cwm-release runs this after publishing and REPORTS the result without
  failing: nothing it finds can be undone by exiting non-zero, and a release
  that already published to ARS and GitHub should not end in a red pipeline
  that implies otherwise.

RELATED
  composer cwm-ars-publish    # publish the release to ARS
  composer cwm-ars-list       # inspect ARS categories and streams

CWM_HELP;

    exit(0);
}

$version = null;
$url     = null;
$args    = array_slice($argv, 1);

for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--url') {
        $url = $args[++$i] ?? null;

        continue;
    }

    if (!str_starts_with($args[$i], '-')) {
        $version = $args[$i];
    }
}

$configFile = $projectRoot . '/cwm-build.config.json';
$config     = is_file($configFile) ? json_decode((string) file_get_contents($configFile), true) : null;
$manifest   = is_array($config) ? ($config['manifests']['package'] ?? null) : null;

if ($version === null && is_string($manifest) && is_file($projectRoot . '/' . $manifest)) {
    $doc = new DOMDocument();

    if (@$doc->load($projectRoot . '/' . $manifest)) {
        $node    = (new DOMXPath($doc))->query('/extension/version')?->item(0);
        $version = $node === null ? null : trim($node->textContent);
    }
}

if ($version === null || $version === '') {
    fwrite(STDERR, "Could not determine which version to check. Pass one as an argument.\n");

    exit(1);
}

if ($url === null) {
    if (!is_string($manifest)) {
        fwrite(STDERR, "No manifests.package in cwm-build.config.json, and no --url given.\n");

        exit(1);
    }

    $url = UpdateStreamVerifier::streamUrlFromManifest($projectRoot . '/' . $manifest);

    if ($url === null) {
        fwrite(STDERR, "No <updateservers><server> in {$manifest} — the package cannot self-update.\n");

        exit(1);
    }
}

echo "=== update stream check — {$version} ===\n";
echo "  stream: {$url}\n";

$context = stream_context_create(['http' => ['timeout' => 30], 'https' => ['timeout' => 30]]);
$body    = @file_get_contents($url, false, $context);

if ($body === false || $body === '') {
    fwrite(STDERR, "  FAIL: could not fetch the update stream.\n");

    exit(1);
}

$entries  = UpdateStreamVerifier::entriesFor($body, $version);
$findings = UpdateStreamVerifier::findings($entries, $version);

echo '  entries at ' . $version . ': ' . count($entries) . "\n";

foreach ($entries as $entry) {
    printf(
        "    #%-3d %-20s php_minimum=%-8s targetplatform=%s\n",
        $entry['position'],
        $entry['element'] === '' ? '(no element)' : $entry['element'],
        $entry['php_minimum'] === '' ? '(none)' : $entry['php_minimum'],
        $entry['targetplatform'] === '' ? '(none)' : $entry['targetplatform']
    );
}

if ($findings === []) {
    echo "  OK: the released version is served and usable.\n";

    exit(0);
}

foreach ($findings as $finding) {
    fwrite(STDERR, '  ' . strtoupper($finding['level']) . ': ' . $finding['message'] . "\n");
}

exit(1);
