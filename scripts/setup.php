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

require_once __DIR__ . '/../src/Config/ComposerPathRepoSync.php';
require_once __DIR__ . '/../src/Dev/InstallConfig.php';
require_once __DIR__ . '/../src/Dev/InstallIntake.php';
require_once __DIR__ . '/../src/Dev/PropertiesReader.php';
require_once __DIR__ . '/../src/Dev/SiblingPathGuesser.php';

use CWM\BuildTools\Config\ComposerPathRepoSync;
use CWM\BuildTools\Dev\InstallConfig;
use CWM\BuildTools\Dev\InstallIntake;
use CWM\BuildTools\Dev\PropertiesReader;
use CWM\BuildTools\Dev\SiblingPathGuesser;

$projectRoot = getcwd();

if ($projectRoot === false) {
    fwrite(STDERR, "Could not resolve current working directory.\n");

    exit(1);
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<HELP
cwm-setup — populate build.properties for the local dev environment.

WHAT IT DOES
  Walks you through one or more Joomla install paths (J5, J6, future J7)
  and captures, per install:
    - filesystem path
    - role: `dev` (cwm-link symlinks the working tree here) or `test`
      (cwm-install-zip deploys the built artifact here; reset/teardown
      tooling may wipe it — never point it at a working install)
    - dev URL (informational; not required)
    - target Joomla version (used by cwm-joomla-install)
    - admin credentials (used by cwm-joomla-install when it bootstraps a
      fresh Joomla checkout)

  It no longer asks for database credentials, and removes any it finds. Every
  command that touches a database resolves them from the install's own
  configuration.php, which is what the site actually connects with; a
  db_pass in build.properties was a password at rest that nothing read.

  Writes build.properties (INI sections) in the current working directory.
  This file is gitignored — secrets stay on your machine.

PREREQUISITES
  - cwm-build.config.json must exist in the current directory. The wizard
    refuses to run without it, since build.properties is per-developer
    state for a build-tools-consuming project.

USAGE
  composer setup                # interactive wizard
  composer setup -- --help      # this help

NOTES
  - Re-running re-prompts with existing values as defaults; it doubles as
    an editor. To finish *without* dropping any installs, press Enter
    through every prompt rather than Enter on a blank install id.
  - In non-interactive contexts (CI, scripted shells), the wizard exits
    with an error rather than blocking on stdin.

NEXT STEPS (after setup)
  composer joomla-install       # download Joomla into each path (optional)
  composer link                 # symlink the project into each install
  composer verify               # confirm extensions are registered

HELP;

    exit(0);
}

if (!is_file($projectRoot . '/cwm-build.config.json')) {
    fwrite(STDERR, "Error: cwm-build.config.json not found in {$projectRoot}.\n");
    fwrite(STDERR, "build.properties is per-developer state for a build-tools-consuming project.\n");
    fwrite(STDERR, "If this is a new project, run 'cwm-init' first or copy from examples/.\n");

    exit(1);
}

if (!isInteractive()) {
    fwrite(STDERR, "Error: cwm-setup is interactive and stdin is not a TTY.\n");
    fwrite(STDERR, "Run from a terminal, or copy templates/build.properties.tmpl to build.properties and edit by hand.\n");

    exit(1);
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

// Every judgement about the answers lives in InstallIntake so it can be
// tested (#32) — including preserving an install's existing role, which
// this loop used to drop on every re-run.
$intake = new InstallIntake();

while (true) {
    $defaultId = $defaultIds[$index] ?? '';
    $rawId     = ask("Install id #" . ($index + 1) . " (e.g. j5, j6, j7)", $defaultId === '' ? null : $defaultId);

    if ($rawId === null || $rawId === '') {
        break;
    }

    $id = $intake->normaliseId($rawId);

    if ($id === null) {
        echo "  Invalid id — use letters, digits, dash or underscore.\n";

        continue;
    }

    $existing = $existingById[$id] ?? null;

    $path = ask("  Joomla path", $existing?->path ?? '');

    if ($path === null || $path === '') {
        echo "  A path is required — skipping this install.\n";

        continue;
    }

    if (!is_dir($path)) {
        echo "  WARNING: '{$path}' does not exist yet. You can run 'composer joomla-install' later to populate it.\n";
    }

    $rawRole = ask("  Role (dev = symlink target, test = zip-install target)", $existing?->role ?? InstallConfig::ROLE_DEV);
    $role    = $intake->normaliseRole((string) $rawRole);

    if ($role === null) {
        echo "  Unrecognised role '{$rawRole}' — keeping '" . ($existing?->role ?? InstallConfig::ROLE_DEV) . "'.\n";
        $role = null; // let assemble() fall back to existing/dev
    }

    $url     = ask("  Dev URL (optional)", $existing?->url ?? "https://{$id}-dev.local");
    $version = ask("  Default Joomla version", $existing?->version ?? '5.4.2');

    $adminUser  = ask("  Admin user", $existing?->adminUser() ?? 'admin');
    $adminPass  = ask("  Admin password", $existing?->adminPass() ?? 'admin');
    $adminEmail = ask("  Admin email", $existing?->adminEmail() ?? 'admin@example.com');

    $installs[] = $intake->assemble($id, [
        'path'       => $path,
        'url'        => $url,
        'version'    => $version,
        'role'       => $role,
        'adminUser'  => $adminUser,
        'adminPass'  => $adminPass,
        'adminEmail' => $adminEmail,
    ], $existing);

    $index++;
    echo "\n";
}

if ($installs === []) {
    echo "No installs configured. Nothing written.\n";

    exit(0);
}

// ⚠️ Say what just happened to the credential block, once, at the point the
// developer can act on it. cwm-setup no longer prompts for db_host/user/pass/
// name and no longer writes them: nothing has read them since Dev\TestSite
// took over, which reads the site's own configuration.php instead. Silently
// dropping a stored password would be worse than leaving it -- the point is
// that it stops existing on disk, and that only happens if the developer
// knows to look.
$carriedCredentials = array_values(array_filter(
    $installs,
    static fn (InstallConfig $i): bool => $i->dbPass() !== '' || $i->dbUser() !== '',
));

$reader->write($installs);

echo "Wrote " . count($installs) . " install(s) to {$reader->path()}.\n";

if ($carriedCredentials !== []) {
    $ids = implode(', ', array_map(static fn (InstallConfig $i): string => $i->id, $carriedCredentials));

    echo "\n";
    echo "  Note: the db_host / db_user / db_pass / db_name block has been removed from\n";
    echo "  this file. It was declared for {$ids} and nothing read it — every command\n";
    echo "  that touches a database resolves credentials from the install's own\n";
    echo "  configuration.php, which is what the site actually connects with.\n";
    echo "\n";
    echo "  If those values are recorded anywhere else, that copy is now the only one.\n";
}
echo "  Ids: " . implode(', ', array_map(static fn (InstallConfig $c): string => $c->id, $installs)) . "\n\n";

echo "Make sure 'build.properties' is gitignored — never commit it.\n";

configureCwmSiblings($projectRoot, $reader);

echo "\nNext steps:\n";
echo "  composer joomla-install   # download Joomla into each path (skip if already populated)\n";
echo "  composer link             # symlink the project into each install\n";
echo "  composer verify           # confirm extensions are registered\n";

/**
 * Two-level CWM sibling configuration:
 *
 *   1. `dev.cwmSiblings` in cwm-build.config.json declares WHICH CWM
 *      packages this project consumes via local path repositories
 *      (committed, shared across developers — defines the relationship).
 *
 *   2. `[paths]` block in build.properties spells out WHERE each
 *      sibling lives on this developer's machine (gitignored, per-dev).
 *
 *   3. cwm-setup reads (1) + (2), prompts for any missing paths,
 *      then writes a `[paths]` block to build.properties AND
 *      synchronises composer.json's `repositories[]` so Composer
 *      picks up the right path-repo URLs on the next install.
 *
 * Bootstrap on a fresh clone: composer.json ships with the maintainer's
 * working defaults (relative URLs like `../lib_cwmscripture`). If a
 * contributor's layout differs, `composer install` may fail; running
 * `composer setup` re-runs this path-config flow and rewrites the
 * URLs before they re-run `composer install`.
 */
function configureCwmSiblings(string $projectRoot, PropertiesReader $reader): void
{
    $configPath = $projectRoot . '/cwm-build.config.json';

    if (!is_file($configPath)) {
        return;
    }

    $configData = json_decode((string) file_get_contents($configPath), true);

    if (!is_array($configData)) {
        return;
    }

    $siblings = $configData['dev']['cwmSiblings'] ?? null;

    if (!is_array($siblings) || $siblings === []) {
        // Nothing declared — nothing to confirm. Silent skip.
        return;
    }

    echo "\n=== CWM siblings (cross-component dev) ===\n";
    echo "This project declares " . count($siblings) . " CWM sibling(s) in cwm-build.config.json.\n";
    echo "Confirm the local checkout path for each — paths are saved to build.properties\n";
    echo "and surfaced to Composer via composer.json's repositories[] block.\n\n";

    $existingPaths = $reader->paths();
    $resolved      = [];

    foreach ($siblings as $package) {
        if (!is_string($package) || $package === '') {
            continue;
        }

        $existing = $existingPaths[$package] ?? null;
        $default  = $existing
            ?? (new SiblingPathGuesser())->guess(dirname($projectRoot), $package)
            ?? '';

        if ($default !== '' && is_dir($default)) {
            echo "  {$package}  →  {$default}\n";

            $confirm = ask("    Use this checkout? [Y/n]", 'Y');

            if ($confirm !== null && strtolower(substr(trim((string) $confirm), 0, 1)) === 'n') {
                $entered = ask("    Path", null);
                $default = ($entered !== null && $entered !== '') ? trim($entered) : $default;
            }
        } else {
            echo "  {$package}\n";

            if ($default !== '') {
                echo "    (default: {$default} — not found on disk)\n";
            }

            $entered = ask("    Path", $default === '' ? null : $default);

            if ($entered === null || $entered === '') {
                echo "    Skipping — no path provided.\n";

                continue;
            }

            $default = trim($entered);

            if (!is_dir($default)) {
                echo "    WARNING: that path doesn't exist either. Saving anyway — fix before `composer install`.\n";
            }
        }

        $resolved[$package] = $default;
    }

    if ($resolved === []) {
        echo "\n  No paths configured.\n";

        return;
    }

    // Merge with any pre-existing entries that weren't re-prompted this run
    // (the user might keep paths for siblings declared elsewhere).
    $merged = array_merge($existingPaths, $resolved);
    $reader->writePaths($merged);

    echo "\n  Wrote " . count($resolved) . " path(s) to build.properties.\n";

    // Sync the path-repo URLs in composer.json.
    syncComposerPathRepos($projectRoot, $resolved);
}

/**
 * Sync composer.json's `repositories[]` block so each $resolved[$package]
 * has a matching path-repo entry with the developer's absolute path.
 *
 * The mutation itself lives in Config\ComposerPathRepoSync so it can be
 * tested (#32) — this wrapper owns only the file I/O.
 *
 * @param  array<string, string> $resolved Map of package name -> absolute path.
 */
function syncComposerPathRepos(string $projectRoot, array $resolved): void
{
    $composerPath = $projectRoot . '/composer.json';

    if (!is_file($composerPath)) {
        echo "  composer.json not found — skipping path-repo sync.\n";

        return;
    }

    $raw = (string) file_get_contents($composerPath);

    if ($raw === '') {
        return;
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        echo "  composer.json is not valid JSON — skipping path-repo sync.\n";

        return;
    }

    $result = (new ComposerPathRepoSync())->sync($data, $resolved);

    if (!$result['changed']) {
        echo "  composer.json path repos already in sync.\n";

        return;
    }

    $trailing  = substr($raw, -1) === "\n" ? "\n" : '';
    $rewritten = json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . $trailing;

    if ($rewritten === false || file_put_contents($composerPath, $rewritten) === false) {
        fwrite(\STDERR, "  WARNING: could not rewrite composer.json — sync repositories[] by hand.\n");

        return;
    }

    echo "  Updated composer.json repositories[] — re-run `composer update` to refresh symlinks.\n";
}

/**
 * True when stdin appears to be a TTY. Lets the wizard refuse to run
 * in non-interactive contexts (CI, piped invocation) instead of silently
 * blocking on the first prompt.
 */
function isInteractive(): bool
{
    if (\function_exists('stream_isatty')) {
        return @stream_isatty(STDIN);
    }

    if (\function_exists('posix_isatty')) {
        return @posix_isatty(STDIN);
    }

    return true;
}

/**
 * Prompt the user and return their trimmed input. Empty input falls back
 * to $default; a closed stdin (EOF) also returns $default so partial
 * runs through a here-doc finish gracefully rather than hanging.
 */
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
