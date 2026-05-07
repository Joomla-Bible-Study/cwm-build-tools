<?php

declare(strict_types=1);

/**
 * Interactive setup wizard for build.properties.
 *
 * Prompts for one or more Joomla install paths and per-install URL / version
 * / DB / admin credentials, then writes the result via PropertiesReader.
 *
 * Reads cwm-build.config.json from the current working directory only to
 * surface a sensible default install id list when none exist yet.
 *
 * Usage (from a consuming project):
 *   composer setup
 *   composer setup -- --help
 */

require_once __DIR__ . '/../src/Dev/InstallConfig.php';
require_once __DIR__ . '/../src/Dev/PropertiesReader.php';

use CWM\BuildTools\Dev\InstallConfig;
use CWM\BuildTools\Dev\PropertiesReader;

$projectRoot = getcwd();

if ($projectRoot === false) {
    fwrite(STDERR, "Could not resolve current working directory.\n");

    exit(1);
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<HELP
cwm-setup — populate build.properties for the local dev environment.

Walks you through:
  - One or more Joomla install paths (J5, J6, future J7)
  - Per-install URL, target version, DB credentials, admin credentials

Writes build.properties in the current working directory. The file is
gitignored — secrets stay on your machine.

Re-running re-prompts with the existing values as defaults, so it doubles
as an editor.

HELP;

    exit(0);
}

$reader        = new PropertiesReader($projectRoot . '/build.properties');
$existingList  = $reader->exists() ? $reader->installs() : [];
$existingById  = [];

foreach ($existingList as $cfg) {
    $existingById[$cfg->id] = $cfg;
}

echo "=== cwm-build-tools dev setup ===\n\n";
echo "Configure one or more Joomla installs to develop against.\n";
echo "Press Enter on a blank install id to finish.\n\n";

$installs   = [];
$defaultIds = $existingList !== []
    ? array_values(array_map(static fn (InstallConfig $c): string => $c->id, $existingList))
    : ['j5', 'j6'];
$index      = 0;

while (true) {
    $defaultId = $defaultIds[$index] ?? '';
    $id        = ask("Install id #" . ($index + 1) . " (e.g. j5, j6, j7)", $defaultId === '' ? null : $defaultId);

    if ($id === null || $id === '') {
        break;
    }

    if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $id)) {
        echo "  Invalid id — use letters, digits, dash or underscore.\n";

        continue;
    }

    $existing = $existingById[$id] ?? null;

    $path = ask("  Joomla path", $existing?->path ?? '');

    if ($path === null || $path === '') {
        echo "  A path is required — skipping this install.\n";

        continue;
    }

    $url     = ask("  Dev URL (optional)", $existing?->url ?? "https://{$id}-dev.local");
    $version = ask("  Default Joomla version", $existing?->version ?? '5.4.2');

    $dbHost = ask("  DB host", $existing?->dbHost() ?? 'localhost');
    $dbUser = ask("  DB user", $existing?->dbUser() ?? '');
    $dbPass = ask("  DB password", $existing?->dbPass() ?? '');
    $dbName = ask("  DB name", $existing?->dbName() ?? '');

    $adminUser  = ask("  Admin user", $existing?->adminUser() ?? 'admin');
    $adminPass  = ask("  Admin password", $existing?->adminPass() ?? 'admin');
    $adminEmail = ask("  Admin email", $existing?->adminEmail() ?? 'admin@example.com');

    $installs[] = new InstallConfig(
        id:      $id,
        path:    $path,
        url:     $url ?: null,
        version: $version ?: null,
        db:      [
            'host' => $dbHost ?: 'localhost',
            'user' => $dbUser ?? '',
            'pass' => $dbPass ?? '',
            'name' => $dbName ?? '',
        ],
        admin:   [
            'user'  => $adminUser ?: 'admin',
            'pass'  => $adminPass ?: 'admin',
            'email' => $adminEmail ?: 'admin@example.com',
        ],
    );

    $index++;
    echo "\n";
}

if ($installs === []) {
    echo "No installs configured. Nothing written.\n";

    exit(0);
}

$reader->write($installs);

echo "Wrote " . count($installs) . " install(s) to {$reader->path()}.\n";
echo "  Ids: " . implode(', ', array_map(static fn (InstallConfig $c): string => $c->id, $installs)) . "\n\n";

echo "Make sure 'build.properties' is gitignored — never commit it.\n";

function ask(string $question, ?string $default = null): ?string
{
    $prompt = $question . ($default !== null ? " [{$default}]" : '') . ': ';
    echo $prompt;

    $handle = fopen('php://stdin', 'rb');

    if ($handle === false) {
        return $default;
    }

    $line = fgets($handle);
    fclose($handle);

    if ($line === false) {
        return $default;
    }

    $line = trim($line);

    return $line === '' ? $default : $line;
}
