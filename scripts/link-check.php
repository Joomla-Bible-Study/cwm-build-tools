<?php

declare(strict_types=1);

/**
 * Verify that every symlink cwm-link would create still resolves correctly.
 *
 * Exits non-zero on any drift so CI can gate on a known-good link state.
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
cwm-link-check — verify symlinks without recreating them.

WHAT IT DOES
  Walks the symlinks cwm-link would have created and reports each as one of:
    OK       — link points to the right place
    MISSING  — no link exists at the expected path
    STALE    — a real file or directory sits at the link path
    WRONG    — link exists but points somewhere unexpected
    BROKEN   — link exists but its target does not

  Exits 1 if any non-OK link is found, so CI can gate on a known-good link
  state. Run 'composer link' to recreate any drifted links.

PREREQUISITES
  - cwm-build.config.json in the current directory
  - build.properties (run 'composer setup' first)

USAGE
  composer link-check           # report only drifted links
  composer link-check -- -v     # also print healthy links

OPTIONS
  -v, --verbose    Also print OK links (otherwise only issues print).

RELATED
  composer link          # recreate symlinks
  composer clean         # remove every dev symlink

HELP;

    exit(0);
}

$verbose = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);

$config = loadConfig($projectRoot);
$reader = new PropertiesReader($projectRoot . '/build.properties');

if (!$reader->exists()) {
    fwrite(STDERR, "build.properties not found. Run 'composer setup' first.\n");

    exit(1);
}

$resolver = new LinkResolver($projectRoot, $config);
$linker   = new Linker($verbose);
$issues   = 0;

echo "Internal links:\n";

foreach ($resolver->internalLinks() as $pair) {
    $issues += reportLink($linker->check($pair['source'], $pair['target']), $verbose);
}

foreach ($reader->installs() as $install) {
    echo "\nInstall: {$install->path}\n";

    if (!is_dir($install->path)) {
        echo "  MISSING: install path does not exist\n";
        $issues++;

        continue;
    }

    foreach ($resolver->externalLinks($install->path) as $pair) {
        $issues += reportLink($linker->check($pair['source'], $pair['target']), $verbose);
    }
}

echo "\n";

if ($issues === 0) {
    echo "All symlinks are healthy.\n";

    exit(0);
}

echo "{$issues} issue(s) found. Run 'composer link' to recreate.\n";

exit(1);

/**
 * Print one line per link result and return 1 for any non-OK status, 0
 * otherwise. The caller sums the return values to produce the script's
 * exit code so CI can gate on a known-good link state.
 *
 * @param  array{link: string, target: string, status: string, message?: string}  $result
 */
function reportLink(array $result, bool $verbose): int
{
    if ($result['status'] === 'ok') {
        if ($verbose) {
            echo "  OK:      {$result['link']}\n";
        }

        return 0;
    }

    $label = match ($result['status']) {
        'missing' => 'MISSING',
        'stale'   => 'STALE',
        'broken'  => 'BROKEN',
        'wrong'   => 'WRONG',
        default   => strtoupper($result['status']),
    };

    $extra = isset($result['message']) ? " ({$result['message']})" : '';
    echo "  {$label}: {$result['link']}{$extra}\n";

    return 1;
}

/**
 * @return array<string, mixed>
 */
function loadConfig(string $projectRoot): array
{
    $configFile = $projectRoot . '/cwm-build.config.json';

    if (!is_file($configFile)) {
        fwrite(STDERR, "cwm-build.config.json not found in {$projectRoot}\n");

        exit(1);
    }

    $config = json_decode((string) file_get_contents($configFile), true);

    if (!is_array($config)) {
        fwrite(STDERR, "cwm-build.config.json is not valid JSON.\n");

        exit(1);
    }

    return $config;
}
