<?php

declare(strict_types=1);

/**
 * Interactive scaffolder for cwm-build.config.json in a project that's
 * adopting cwm-build-tools.
 *
 * Walks the project tree to detect:
 *   - package manifest (build/pkg_*.xml, **\/pkg_*.xml)
 *   - top-level extension (component manifest at the root, library, plugin, module)
 *   - sub-extension manifests (libraries/lib_*\/, plugins/<group>/<element>/,
 *     modules/mod_*\/, administrator/modules/mod_*\/)
 *   - github owner/repo from .git/config (no subprocess)
 *   - existing changelog file (build/*-changelog.xml)
 *   - build command and output glob from common Proclaim/CWMScripture patterns
 *
 * Every value is pre-filled with the detected default; the user presses Enter
 * to accept or types an override. ARS category/stream ids default to 0 — those
 * almost always need to be looked up via 'cwm-ars-list categories' after
 * init runs.
 *
 * After writing the file, automatically runs cwm-sync-configs to install
 * the managed .gitignore block (and whatever other handlers are wired up).
 *
 * Usage (from the consuming project root):
 *   composer require --dev cwm/build-tools
 *   vendor/bin/cwm-init                        # interactive
 *   vendor/bin/cwm-init -- --force             # overwrite existing config
 *   vendor/bin/cwm-init -- --non-interactive   # accept all detected defaults
 */

const TOOLS_DIR = __DIR__ . '/..';

// Defaults used when adding cwm/build-tools to a consumer composer.json.
// The VCS URL doubles as the de-facto canonical location until packagist
// publishing happens; the constraint matches what the README documents.
const CWM_VCS_URL    = 'https://github.com/Joomla-Bible-Study/cwm-build-tools';
const CWM_CONSTRAINT = '^0.4@alpha';

// Composer scripts cwm-init will offer to wire into the consumer's
// composer.json. Names that already exist are skipped — the user is told
// which ones were added vs skipped so deliberate migrations (e.g. replacing
// `setup` that points at build/proclaim_build.php) can be done by hand.
const CWM_COMPOSER_SCRIPTS = [
    'setup'          => 'vendor/bin/cwm-setup',
    'link'           => 'vendor/bin/cwm-link',
    'link-check'     => 'vendor/bin/cwm-link-check',
    'clean'          => 'vendor/bin/cwm-clean',
    'verify'         => 'vendor/bin/cwm-verify',
    'joomla-install' => 'vendor/bin/cwm-joomla-install',
    'joomla-latest'  => 'vendor/bin/cwm-joomla-latest',
    'package'        => 'vendor/bin/cwm-package',
    'bump-version'   => 'vendor/bin/cwm-bump',
    'release'        => 'vendor/bin/cwm-release',
    'changelog'      => 'vendor/bin/cwm-changelog',
    'ars-publish'    => 'vendor/bin/cwm-ars-publish',
    'ars-list'       => 'vendor/bin/cwm-ars-list',
    'sync-configs'   => 'vendor/bin/cwm-sync-configs',
    'sync-languages' => 'vendor/bin/cwm-sync-languages',
];

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<HELP
cwm-init — scaffold cwm-build.config.json for a new consumer of cwm-build-tools.

WHAT IT DOES
  Walks the current project tree and detects the extension layout, then
  walks you through building a cwm-build.config.json. Every prompt is
  pre-filled with what was detected; press Enter to accept or type an
  override.

  When done, automatically runs cwm-sync-configs to install the managed
  blocks in .gitignore (and any future config handlers).

PREREQUISITES
  - Run from the project's root directory (where composer.json lives)
  - cwm/build-tools installed via composer require --dev (or use a path
    repository for local testing)
  - The project's manifest XML files should already be present so
    auto-detection works

USAGE
  composer cwm-init                        # interactive (recommended)
  composer cwm-init -- --force             # overwrite existing config
  composer cwm-init -- --non-interactive   # accept all detected defaults

OPTIONS
      --force            Overwrite an existing cwm-build.config.json
      --non-interactive  Skip prompts; write the detected values as-is
                         (useful for CI smoke tests)

NEXT STEPS (after init)
  composer setup            # write build.properties for your local Joomlas
  composer link             # symlink the project into each install
  composer verify           # confirm extensions registered
  cwm-ars-list categories   # find the ARS categoryId / updateStreamId
                            # then edit cwm-build.config.json to set them

HELP;

    exit(0);
}

$projectRoot = getcwd();

if ($projectRoot === false) {
    fwrite(STDERR, "Could not resolve current working directory.\n");

    exit(1);
}

$force          = in_array('--force', $argv, true);
$nonInteractive = in_array('--non-interactive', $argv, true);
$configPath     = $projectRoot . '/cwm-build.config.json';

if (file_exists($configPath) && !$force) {
    fwrite(STDERR, "Error: cwm-build.config.json already exists at {$configPath}.\n");
    fwrite(STDERR, "Pass --force to overwrite, or delete the file and re-run.\n");

    exit(1);
}

if (!$nonInteractive && !isInteractive()) {
    fwrite(STDERR, "Error: stdin is not a TTY. Run from a terminal, or pass --non-interactive.\n");

    exit(1);
}

echo "=== cwm-build-tools init ===\n\n";

if ($nonInteractive) {
    echo "(non-interactive mode — accepting all detected defaults)\n\n";
}

// ---------------------------------------------------------------
// 0. composer.json — declare the dependency before anything else
// ---------------------------------------------------------------
// If the consumer skipped `composer require --dev cwm/build-tools` and is
// running this script directly (Option A in the README), then cwm/build-tools
// will be missing from `require-dev` — and the next `composer install` will
// either fail or wipe the locally-placed copy. Catching this up front saves
// the consumer from the surprise that issue #4 reported.
$composer = readComposerStatus($projectRoot);

if (!$composer['exists']) {
    echo "WARNING: no composer.json found in {$projectRoot}.\n";
    echo "         cwm-build-tools assumes a Composer-managed project. Continuing,\n";
    echo "         but you'll want to add a composer.json before publishing.\n\n";
} else {
    // Preview every change we'd make in one place so the consumer sees the full
    // diff in advance and can accept or skip with a single Y/n.
    $existingScripts  = $composer['data']['scripts'] ?? [];
    $scriptsAdded     = [];
    $scriptsSkipped   = [];

    foreach (CWM_COMPOSER_SCRIPTS as $scriptName => $scriptCmd) {
        if (isset($existingScripts[$scriptName])) {
            $scriptsSkipped[] = $scriptName;
        } else {
            $scriptsAdded[] = $scriptName;
        }
    }

    $needsRequireDev = !$composer['inRequireDev'];
    $needsVcsRepo    = !$composer['hasVcsRepo'];
    $needsScripts    = $scriptsAdded !== [];

    if ($needsRequireDev || $needsVcsRepo || $needsScripts) {
        echo "Composer integration check:\n";

        echo $composer['inRequireDev']
            ? "  - require-dev:  cwm/build-tools = {$composer['currentConstraint']} (already declared)\n"
            : "  - require-dev:  cwm/build-tools is NOT declared\n";

        echo $composer['hasVcsRepo']
            ? "  - repositories: VCS entry for cwm-build-tools is present\n"
            : "  - repositories: no VCS entry for cwm-build-tools (needed until packagist publishing)\n";

        if ($scriptsAdded !== []) {
            echo "  - scripts:      " . count($scriptsAdded) . " missing, " . count($scriptsSkipped) . " already defined\n";
        }

        echo "\nProposed changes to composer.json:\n";

        if ($needsRequireDev) {
            echo "  + require-dev:  \"cwm/build-tools\": \"" . CWM_CONSTRAINT . "\"\n";
        }

        if ($needsVcsRepo) {
            echo "  + repositories: { type: vcs, url: " . CWM_VCS_URL . " }\n";
        }

        if ($scriptsAdded !== []) {
            echo "  + scripts (new):       " . implode(', ', $scriptsAdded) . "\n";
        }

        if ($scriptsSkipped !== []) {
            echo "  ~ scripts (kept asis): " . implode(', ', $scriptsSkipped) . "\n";
            echo "    (left in place — review whether each should still point at the existing target\n";
            echo "     or be re-pointed at the matching vendor/bin/cwm-* binary by hand.)\n";
        }

        $apply = $nonInteractive ? 'Y' : ask('Apply these changes to composer.json? (Y/n)', 'Y');

        if ($nonInteractive || (is_string($apply) && strtolower(trim($apply))[0] !== 'n')) {
            try {
                $report = updateComposerJson(
                    $composer['path'],
                    $composer['data'],
                    CWM_CONSTRAINT,
                    CWM_VCS_URL,
                    CWM_COMPOSER_SCRIPTS,
                );

                echo "  composer.json updated.\n";

                if ($report['require-dev'] || $report['repo']) {
                    echo "  Run 'composer update cwm/build-tools' to refresh the lockfile.\n";
                }

                echo "\n";
            } catch (\Throwable $e) {
                fwrite(STDERR, "  Failed to update composer.json: " . $e->getMessage() . "\n\n");
            }
        } else {
            echo "  Skipped composer.json update — add the entries by hand.\n\n";
        }
    }
}

// ---------------------------------------------------------------
// 0b. package.json migration hints (issue #4.5)
// ---------------------------------------------------------------
// SOURCE_DIR / OUTPUT_DIR are too project-specific to auto-rewrite — the
// safe path is to surface the recommended shape so the consumer can copy-
// paste while reviewing the actual paths that match their own source layout.
$packageJsonHints = collectPackageJsonHints($projectRoot);

if ($packageJsonHints !== []) {
    echo "package.json migration suggestions (do these by hand — paths are project-specific):\n";

    foreach ($packageJsonHints as $name => $hint) {
        echo "\n  {$name}:\n";
        echo "    current:    {$hint['current']}\n";
        echo "    suggested:  {$hint['suggested']}\n";
    }

    echo "\n";
}

// ---------------------------------------------------------------
// 1. Detect manifest layout
// ---------------------------------------------------------------
$detected = detectLayout($projectRoot);

echo "Detected layout:\n";
echo "  package manifest: " . ($detected['package'] ?? '(none)') . "\n";
echo "  sub-manifests:    " . count($detected['extensions']) . "\n";

foreach ($detected['extensions'] as $ext) {
    echo "    - {$ext['type']}: {$ext['path']}\n";
}

if ($detected['topLevel'] !== null) {
    echo "  top-level:        {$detected['topLevel']['type']} '{$detected['topLevel']['name']}'\n";
}

echo "\n";

// ---------------------------------------------------------------
// 2. Walk the wizard
// ---------------------------------------------------------------

$extType = $detected['topLevel']['type'] ?? 'package';
$extName = $detected['topLevel']['name'] ?? '';

if (!$nonInteractive) {
    $extType = ask('Extension type (package, component, library, plugin, module)', $extType);
    $extName = ask("Extension name (e.g. pkg_X / com_X / lib_X / plg_<group>_<element> / mod_X)", $extName);
}

$packageManifest = $detected['package'] ?? '';

if ($extType === 'package' && !$nonInteractive) {
    $packageManifest = ask('Package manifest path (relative to project root)', $packageManifest);
}

// Build / output glob — pair the build script with its conventional output
// directory so the default glob matches whichever script the project ships.
$buildCommand    = $detected['buildCommand'] ?? '';
$buildOutputDir  = $detected['buildOutputDir'] ?? 'build/dist/';
$buildOutputGlob = '';

if ($extName !== '') {
    $buildOutputGlob = rtrim($buildOutputDir, '/') . "/{$extName}-*.zip";
}

if (!$nonInteractive) {
    $buildCommand    = ask('Build command (run by composer package / composer release)', $buildCommand);
    $buildOutputGlob = ask('Build output glob (must match exactly one zip after build)', $buildOutputGlob);
}

// GitHub
$gh = $detected['github'] ?? ['owner' => '', 'repo' => ''];

if (!$nonInteractive) {
    $gh['owner']             = ask('GitHub owner', $gh['owner']);
    $gh['repo']              = ask('GitHub repo',  $gh['repo']);
    $gh['releaseBranch']     = ask('Release branch', $gh['releaseBranch'] ?? 'main');
    $gh['developmentBranch'] = ask('Development branch (blank to skip versions.json bump)', $gh['developmentBranch'] ?? 'development');
}

$gh['releaseBranch']     ??= 'main';
$gh['developmentBranch'] ??= 'development';

if (!$nonInteractive
    && $gh['releaseBranch'] !== ''
    && $gh['developmentBranch'] !== ''
    && $gh['releaseBranch'] === $gh['developmentBranch']
) {
    echo "\nWARNING: releaseBranch and developmentBranch are both '{$gh['releaseBranch']}'.\n";
    echo "  These are normally different — releaseBranch is where 'gh release create' targets,\n";
    echo "  developmentBranch is where the post-release versions.json bump goes. If they're\n";
    echo "  the same, release.sh step 7 just churns on the same branch.\n";
    $confirm = ask("  Continue anyway? (y/N)", 'N');

    if (strtolower(trim($confirm))[0] !== 'y') {
        $gh['releaseBranch']     = ask('Release branch (where releases are cut from)', 'main');
        $gh['developmentBranch'] = ask('Development branch (where day-to-day work merges)', 'development');
    }
}

// ARS
$ars = [
    'endpoint'        => 'https://www.christianwebministries.org',
    'categoryId'      => 0,
    'updateStreamId'  => 0,
    'tokenItem'       => 'CWM ARS API Token',
    'tokenVault'      => 'CWM',
    'itemDescription' => $extName !== '' ? "{$extName} download" : '',
];

if (!$nonInteractive) {
    echo "\nARS publish (skip with blank endpoint if not using ARS):\n";
    $ars['endpoint']        = ask('  ARS site URL', $ars['endpoint']);
    $ars['categoryId']      = (int) ask('  ARS category id (0 = look up later)', (string) $ars['categoryId']);
    $ars['updateStreamId']  = (int) ask('  ARS update stream id (0 = look up later)', (string) $ars['updateStreamId']);
    $ars['tokenItem']       = ask('  1Password item label', $ars['tokenItem']);
    $ars['tokenVault']      = ask('  1Password vault',     $ars['tokenVault']);
    $ars['itemDescription'] = ask('  ARS item description', $ars['itemDescription']);
}

// Changelog
$changelogFile = $detected['changelogFile'] ?? ($extName !== '' ? "build/{$extName}-changelog.xml" : '');
$changelogUrl  = '';

if ($changelogFile !== '' && $gh['owner'] !== '' && $gh['repo'] !== '') {
    $changelogUrl = sprintf(
        'https://raw.githubusercontent.com/%s/%s/%s/%s',
        $gh['owner'],
        $gh['repo'],
        $gh['releaseBranch'],
        ltrim($changelogFile, '/'),
    );
}

if (!$nonInteractive) {
    echo "\nChangelog (Joomla XML changelog used by the Update server):\n";
    $changelogFile = ask('  Changelog file path (blank to skip)', $changelogFile);
    $changelogUrl  = ask('  Changelog URL (raw.githubusercontent.com link to the same file)', $changelogUrl);
}

// ---------------------------------------------------------------
// 3. Build the config dictionary
// ---------------------------------------------------------------

$config = [
    'extension' => [
        'type' => $extType,
        'name' => $extName,
    ],
    'manifests' => [],
    'build'     => [
        'command'    => $buildCommand,
        'outputGlob' => $buildOutputGlob,
    ],
    'ars'    => [
        'endpoint'       => $ars['endpoint'],
        'categoryId'     => $ars['categoryId'],
        'updateStreamId' => $ars['updateStreamId'],
        'tokenItem'      => $ars['tokenItem'],
        'tokenVault'     => $ars['tokenVault'],
    ],
    'github' => array_filter([
        'owner'             => $gh['owner'],
        'repo'              => $gh['repo'],
        'releaseBranch'     => $gh['releaseBranch'],
        'developmentBranch' => $gh['developmentBranch'],
    ], static fn ($v) => $v !== ''),
];

if ($ars['itemDescription'] !== '') {
    $config['ars']['itemDescription'] = $ars['itemDescription'];
}

if ($packageManifest !== '') {
    $config['manifests']['package'] = $packageManifest;
}

if ($detected['extensions'] !== []) {
    $config['manifests']['extensions'] = $detected['extensions'];
}

if ($detected['versionsFile'] !== null) {
    $config['versionsFile'] = $detected['versionsFile'];
}

if ($changelogFile !== '') {
    $config['changelog'] = array_filter([
        'file' => $changelogFile,
        'url'  => $changelogUrl,
    ], static fn ($v) => $v !== '');
}

if ($detected['announcementCommand'] !== null) {
    $config['announcement'] = [
        'bulletsDir' => 'build',
        'command'    => $detected['announcementCommand'],
    ];
}

if ($detected['vendors'] !== []) {
    $config['vendors'] = $detected['vendors'];
}

if ($detected['mediaPaths'] !== []) {
    // Seed gitignore.mediaPaths[] from what's actually on disk so
    // sync-configs writes the right ignore rules. Without this, projects
    // with non-default media layouts (e.g. Proclaim's mix of
    // /media/com_proclaim/, /media/lib_cwmscripture/, /media/proclaim/)
    // got a single /media/<stripped>/... block that didn't match reality.
    $config['gitignore'] = ['mediaPaths' => $detected['mediaPaths']];
}

$config['dev'] = ['deriveLinks' => true];

// ---------------------------------------------------------------
// 4. Write the file
// ---------------------------------------------------------------

$json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($json === false) {
    fwrite(STDERR, "Failed to serialise config to JSON: " . json_last_error_msg() . "\n");

    exit(1);
}

if (file_put_contents($configPath, $json . "\n") === false) {
    fwrite(STDERR, "Failed to write {$configPath}\n");

    exit(1);
}

echo "\nWrote {$configPath}\n";

// ---------------------------------------------------------------
// 5. Run cwm-sync-configs to install managed blocks
// ---------------------------------------------------------------

echo "\nRunning sync-configs to install managed blocks...\n";

$syncScript = realpath(TOOLS_DIR . '/scripts/sync-configs.php');

if ($syncScript === false || !is_file($syncScript)) {
    echo "  WARNING: scripts/sync-configs.php not found — skipping sync.\n";
} else {
    // PHP_BINARY is the absolute path to the running PHP interpreter, so we
    // do not need a shell to resolve it. proc_open with an args array runs
    // without invoking /bin/sh, so the values can't be reinterpreted as
    // shell metacharacters even if a future contributor parameterises this.
    $process = proc_open(
        [PHP_BINARY, $syncScript],
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes,
        $projectRoot,
    );

    $rc = is_resource($process) ? proc_close($process) : 1;

    if ($rc !== 0) {
        echo "  WARNING: sync-configs returned exit code {$rc}.\n";
    }
}

// ---------------------------------------------------------------
// 5b. Stale runtime artifacts — leftovers from tools cwm-build-tools now
//     provides. Detected at scan time but offered for cleanup AFTER
//     sync-configs runs, so the user has reviewed all other changes first.
// ---------------------------------------------------------------

if ($detected['staleArtifacts'] !== []) {
    echo "\nStale artifacts found (now provided by cwm-build-tools):\n";

    foreach ($detected['staleArtifacts'] as $relative) {
        echo "  - {$relative}\n";
    }

    $remove = $nonInteractive
        ? 'N'
        : ask('Remove these? (y/N)', 'N');

    if (is_string($remove) && strtolower(trim($remove))[0] === 'y') {
        foreach ($detected['staleArtifacts'] as $relative) {
            $absolute = $projectRoot . '/' . $relative;

            if (is_dir($absolute) && !is_link($absolute)) {
                removeDirectory($absolute);
                echo "  removed: {$relative}/\n";
            } elseif (file_exists($absolute) || is_link($absolute)) {
                @unlink($absolute);
                echo "  removed: {$relative}\n";
            }
        }
    } else {
        echo "  Skipped — review manually before deleting.\n";
    }
}

// ---------------------------------------------------------------
// 6. Next-steps banner
// ---------------------------------------------------------------

echo "\nNext steps:\n";
echo "  1. Review cwm-build.config.json — set ars.categoryId / ars.updateStreamId\n";
echo "     (use 'cwm-ars-list categories' to find them).\n";
echo "  2. composer setup            # write build.properties for your local Joomlas\n";
echo "  3. composer joomla-install   # download Joomla into each path (skip if populated)\n";
echo "  4. composer link             # symlink the project into each install\n";
echo "  5. composer verify           # confirm extensions registered in #__extensions\n";

exit(0);

// =====================================================================
// Helpers
// =====================================================================

/**
 * @return array{
 *     package: ?string,
 *     extensions: list<array{type: string, path: string}>,
 *     topLevel: ?array{type: string, name: string},
 *     github: array{owner: string, repo: string, releaseBranch?: string, developmentBranch?: string},
 *     buildCommand: ?string,
 *     buildOutputDir: ?string,
 *     changelogFile: ?string,
 *     versionsFile: ?string,
 *     announcementCommand: ?string,
 *     vendors: list<array{npm: string, label: string, notes: string}>,
 *     mediaPaths: list<string>,
 *     staleArtifacts: list<string>,
 * }
 */
function detectLayout(string $projectRoot): array
{
    $build = detectBuild($projectRoot);

    return [
        'package'             => detectPackageManifest($projectRoot),
        'extensions'          => detectSubExtensions($projectRoot),
        'topLevel'            => detectTopLevel($projectRoot),
        'github'              => detectGithubOrigin($projectRoot),
        'buildCommand'        => $build['command'],
        'buildOutputDir'      => $build['outputDir'],
        'changelogFile'       => detectChangelogFile($projectRoot),
        'versionsFile'        => is_file($projectRoot . '/build/versions.json') ? 'build/versions.json' : null,
        'announcementCommand' => detectAnnouncementCommand($projectRoot),
        'vendors'             => detectVendors($projectRoot),
        'mediaPaths'          => detectMediaPaths($projectRoot),
        'staleArtifacts'      => detectStaleArtifacts($projectRoot),
    ];
}

/**
 * Walk <project>/media/<x>/(js|css)/ to find the directories that already
 * receive built JS/CSS. cwm-sync-configs uses this to seed
 * gitignore.mediaPaths[] so projects with multiple sub-extensions (Proclaim's
 * `/media/com_proclaim/`, `/media/lib_cwmscripture/`, `/media/proclaim/`)
 * get the right ignore rules instead of a single `/media/<stripped>/`.
 *
 * @return list<string>
 */
function detectMediaPaths(string $projectRoot): array
{
    $root = $projectRoot . '/media';

    if (!is_dir($root)) {
        return [];
    }

    $paths = [];

    foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $entry) {
        if (!$entry->isDir() || $entry->isLink()) {
            continue;
        }

        $base = '/media/' . $entry->getFilename();

        if (is_dir($entry->getPathname() . '/js')) {
            $paths[] = "{$base}/js/*.min.js";
            $paths[] = "{$base}/js/*.min.js.map";
        }

        if (is_dir($entry->getPathname() . '/css')) {
            $paths[] = "{$base}/css/*.min.css";
            $paths[] = "{$base}/css/*.min.css.map";
        }
    }

    sort($paths);

    return $paths;
}

/**
 * Detect orphaned files left behind by tools cwm-build-tools now provides:
 *   - build/__pycache__/ from a project-local sync_languages.py
 *   - build/.source_cache.json / build/.translation_cache.json (old cache
 *     locations before issue #4.8)
 *   - build/sync_languages.py / build/generate-changelog-entry.sh /
 *     build/release.sh / build/bump.php / build/build-css.js / build/rollup.config.js /
 *     build/eslint.config.mjs / build/check-vendor-versions.js / build/update-vendors.js
 *     when the consumer migrated their composer scripts to vendor/bin/cwm-*
 *     but forgot to delete the project-local copies.
 *
 * @return list<string>
 */
function detectStaleArtifacts(string $projectRoot): array
{
    $candidates = [
        'build/__pycache__',
        'build/.source_cache.json',
        'build/.translation_cache.json',
        // Migration leftovers — only flagged here; the consumer confirms before
        // we remove anything. Each maps to a vendor/bin/cwm-* equivalent.
        'build/sync_languages.py',
        'build/generate-changelog-entry.sh',
        'build/release.sh',
        'build/bump.php',
        'build/build-css.js',
        'build/rollup.config.js',
        'build/eslint.config.mjs',
        'build/check-vendor-versions.js',
        'build/update-vendors.js',
        'build/ars-release.sh',
    ];

    $found = [];

    foreach ($candidates as $relative) {
        $path = $projectRoot . '/' . $relative;

        if (file_exists($path)) {
            $found[] = $relative;
        }
    }

    return $found;
}

/**
 * Locate the package wrapper manifest, if any. Walks the conventional
 * paths (`build/pkg_*.xml`, root `pkg_*.xml`, `build/*-package.xml`) and
 * returns the FIRST file whose root <extension type="..."> attribute is
 * "package". Returns the path relative to the project root.
 */
function detectPackageManifest(string $projectRoot): ?string
{
    foreach (['build/pkg_*.xml', 'pkg_*.xml', 'build/*-package.xml'] as $pattern) {
        $matches = glob($projectRoot . '/' . $pattern) ?: [];

        foreach ($matches as $match) {
            if (manifestType($match) === 'package') {
                return ltrim(substr($match, \strlen($projectRoot)), '/');
            }
        }
    }

    return null;
}

/**
 * @return list<array{type: string, path: string}>
 */
function detectSubExtensions(string $projectRoot): array
{
    $found    = [];
    $seenReal = [];

    // Layered scan: project-root components, admin/ components, libraries,
    // plugins, modules. Within each pass we let collectManifests handle
    // dedup by realpath, so a symlinked manifest (e.g. admin/proclaim.xml ->
    // ../proclaim.xml in Proclaim) only appears once in the output.
    collectManifests(glob($projectRoot . '/*.xml') ?: [], 'component', $projectRoot, $found, $seenReal);
    collectManifests(glob($projectRoot . '/admin/*.xml') ?: [], 'component', $projectRoot, $found, $seenReal);
    collectManifests(glob($projectRoot . '/libraries/lib_*/*.xml') ?: [], 'library', $projectRoot, $found, $seenReal);
    collectManifests(glob($projectRoot . '/plugins/*/*/*.xml') ?: [], 'plugin', $projectRoot, $found, $seenReal);
    collectManifests(glob($projectRoot . '/modules/mod_*/*.xml') ?: [], 'module', $projectRoot, $found, $seenReal);
    collectManifests(glob($projectRoot . '/administrator/modules/mod_*/*.xml') ?: [], 'module', $projectRoot, $found, $seenReal);

    return $found;
}

/**
 * Append each candidate that has the expected manifest type, skipping any
 * whose realpath has already been emitted. Mutates $found and $seenReal.
 *
 * @param  list<string>                                 $candidates
 * @param  list<array{type: string, path: string}>     $found
 * @param  array<string, true>                          $seenReal
 */
function collectManifests(array $candidates, string $expectedType, string $projectRoot, array &$found, array &$seenReal): void
{
    foreach ($candidates as $candidate) {
        if (manifestType($candidate) !== $expectedType) {
            continue;
        }

        $real = realpath($candidate);

        if ($real === false) {
            continue;
        }

        if (isset($seenReal[$real])) {
            continue;
        }

        $seenReal[$real] = true;

        $found[] = [
            'type' => $expectedType,
            'path' => ltrim(substr($candidate, \strlen($projectRoot)), '/'),
        ];
    }
}

/**
 * @return ?array{type: string, name: string}
 */
function detectTopLevel(string $projectRoot): ?array
{
    // Prefer a package wrapper if one exists.
    $pkg = detectPackageManifest($projectRoot);

    if ($pkg !== null) {
        $name = readManifestField($projectRoot . '/' . $pkg, 'packagename');

        if ($name !== '' && !str_starts_with($name, 'pkg_')) {
            $name = 'pkg_' . $name;
        }

        return [
            'type' => 'package',
            'name' => $name !== '' ? strtolower($name) : 'pkg_' . strtolower(basename($projectRoot)),
        ];
    }

    // Otherwise pick the most common single-extension shape.
    $libraries = glob($projectRoot . '/libraries/lib_*/*.xml') ?: [];

    if (\count($libraries) === 1 && manifestType($libraries[0]) === 'library') {
        $name = readManifestField($libraries[0], 'name');

        return [
            'type' => 'library',
            'name' => $name !== '' ? strtolower($name) : 'lib_' . basename(\dirname($libraries[0])),
        ];
    }

    $rootXml = glob($projectRoot . '/*.xml') ?: [];

    foreach ($rootXml as $candidate) {
        $type = manifestType($candidate);

        if ($type === 'library' || $type === 'component') {
            $name = readManifestField($candidate, 'name');

            return [
                'type' => $type,
                'name' => $name !== '' ? strtolower($name) : '',
            ];
        }
    }

    return null;
}

/**
 * Read the `type=` attribute on the root <extension> element of a Joomla
 * manifest XML. Returns an empty string if the file is not a parseable
 * manifest, so callers can use loose equality to filter by expected type.
 */
function manifestType(string $path): string
{
    if (!is_file($path)) {
        return '';
    }

    $previous = libxml_use_internal_errors(true);

    try {
        $xml = simplexml_load_file($path);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    if (!$xml instanceof SimpleXMLElement || $xml->getName() !== 'extension') {
        return '';
    }

    return (string) ($xml['type'] ?? '');
}

/**
 * Read a top-level child element from a Joomla extension manifest, e.g.
 * <name>, <version>, <packagename>. Returns an empty string when the
 * field is absent or the file is unparseable.
 */
function readManifestField(string $path, string $field): string
{
    if (!is_file($path)) {
        return '';
    }

    $previous = libxml_use_internal_errors(true);

    try {
        $xml = simplexml_load_file($path);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    if (!$xml instanceof SimpleXMLElement) {
        return '';
    }

    return trim((string) ($xml->{$field} ?? ''));
}

/**
 * Read git remote.origin.url from .git/config without spawning a subprocess.
 *
 * .git/config follows an INI-like grammar; for our purposes we only need
 * the [remote "origin"] section's url= line. For branch defaults we walk
 * .git/refs/remotes/origin/ and .git/packed-refs to figure out which
 * branches actually exist on origin, and bias toward conventional names
 * (main/master for release, development/develop for development).
 *
 * @return array{owner: string, repo: string, releaseBranch?: string, developmentBranch?: string}
 */
function detectGithubOrigin(string $projectRoot): array
{
    $out        = ['owner' => '', 'repo' => ''];
    $configPath = $projectRoot . '/.git/config';

    if (!is_file($configPath)) {
        return $out;
    }

    $content = (string) file_get_contents($configPath);
    $url     = '';

    if (preg_match('/\[remote\s+"origin"\][^\[]*?url\s*=\s*(\S+)/s', $content, $m)) {
        $url = trim($m[1]);
    }

    if ($url !== '' && preg_match('~github\.com[:/]([^/]+)/([^/.]+?)(?:\.git)?$~', $url, $m)) {
        $out['owner'] = $m[1];
        $out['repo']  = $m[2];
    }

    $branches    = listOriginBranches($projectRoot);
    $headDefault = readOriginHeadDefault($projectRoot);

    // releaseBranch: the typical "this is where releases are cut" branch.
    // Prefer main, then master, then origin/HEAD (which on GitHub may be set
    // to the default branch — useful when the project actually does
    // single-branch releases off something non-standard).
    if (in_array('main', $branches, true)) {
        $out['releaseBranch'] = 'main';
    } elseif (in_array('master', $branches, true)) {
        $out['releaseBranch'] = 'master';
    } elseif ($headDefault !== null) {
        $out['releaseBranch'] = $headDefault;
    }

    // developmentBranch: where ongoing work happens.
    if (in_array('development', $branches, true)) {
        $out['developmentBranch'] = 'development';
    } elseif (in_array('develop', $branches, true)) {
        $out['developmentBranch'] = 'develop';
    } elseif ($headDefault !== null && $headDefault !== ($out['releaseBranch'] ?? null)) {
        // If origin/HEAD differs from releaseBranch (GitHub-style "develop is
        // the default"), surface that as the development hint.
        $out['developmentBranch'] = $headDefault;
    }

    return $out;
}

/**
 * Enumerate refs under refs/remotes/origin/ from both the loose-ref
 * directory and packed-refs. Returns the simple branch names.
 *
 * @return list<string>
 */
function listOriginBranches(string $projectRoot): array
{
    $branches = [];

    $refsDir = $projectRoot . '/.git/refs/remotes/origin';

    if (is_dir($refsDir)) {
        foreach (new \FilesystemIterator($refsDir, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $branches[$entry->getFilename()] = true;
        }
    }

    $packed = $projectRoot . '/.git/packed-refs';

    if (is_file($packed)) {
        $lines = file($packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }

            if (preg_match('~refs/remotes/origin/(\S+)~', $line, $m)) {
                $branches[$m[1]] = true;
            }
        }
    }

    unset($branches['HEAD']);

    return array_keys($branches);
}

/**
 * Resolve the symbolic ref at .git/refs/remotes/origin/HEAD to a branch
 * name. This is the branch GitHub considers "default" for the repo. Used
 * as a last-resort hint when neither main nor master is present locally.
 */
function readOriginHeadDefault(string $projectRoot): ?string
{
    $headRef = $projectRoot . '/.git/refs/remotes/origin/HEAD';

    if (!is_file($headRef)) {
        return null;
    }

    $line = trim((string) file_get_contents($headRef));

    if (preg_match('~^ref:\s*refs/remotes/origin/(\S+)~', $line, $m)) {
        return $m[1];
    }

    return null;
}

/**
 * Detect the project's build command and the output directory it writes
 * artifacts to. Pairs the two together because the right zip directory is
 * coupled to the build script: Proclaim's `proclaim_build.php` writes to
 * `build/packages/`, the CWMScripture-style `build-package.php` writes to
 * `build/dist/`.
 *
 * @return array{command: ?string, outputDir: ?string}
 */
function detectBuild(string $projectRoot): array
{
    $candidates = [
        'build/proclaim_build.php' => ['command' => 'php build/proclaim_build.php package', 'outputDir' => 'build/packages/'],
        'build/build-package.php'  => ['command' => 'php build/build-package.php',          'outputDir' => 'build/dist/'],
    ];

    foreach ($candidates as $relative => $hit) {
        if (is_file($projectRoot . '/' . $relative)) {
            return $hit;
        }
    }

    return ['command' => null, 'outputDir' => null];
}

/**
 * Find the project's existing Joomla changelog XML, if any. Looks under
 * build/ for `*-changelog.xml` and returns the first match relative to
 * the project root.
 */
function detectChangelogFile(string $projectRoot): ?string
{
    foreach (glob($projectRoot . '/build/*-changelog.xml') ?: [] as $match) {
        return ltrim(substr($match, \strlen($projectRoot)), '/');
    }

    return null;
}

/**
 * Detect a project's release-announcement script (e.g. Proclaim's
 * build/cwm-article.sh). When present, release.sh step 8 invokes it with
 * the version and the bullets file.
 */
function detectAnnouncementCommand(string $projectRoot): ?string
{
    foreach (['build/cwm-article.sh'] as $candidate) {
        if (is_file($projectRoot . '/' . $candidate)) {
            return "bash {$candidate}";
        }
    }

    return null;
}

/**
 * Look for an existing vendor list in build/check-vendor-versions.js and
 * extract the npm package names. Falls back to package.json `dependencies`
 * (NOT devDependencies — those are tooling, not bundled vendor libraries).
 *
 * @return list<array{npm: string, label: string, notes: string}>
 */
function detectVendors(string $projectRoot): array
{
    foreach (['build/check-vendor-versions.js', 'build/update-vendors.js'] as $relative) {
        $path = $projectRoot . '/' . $relative;

        if (!is_file($path)) {
            continue;
        }

        $vendors = parseVendorListFromJs((string) file_get_contents($path));

        if ($vendors !== []) {
            return $vendors;
        }
    }

    $packageJson = $projectRoot . '/package.json';

    if (!is_file($packageJson)) {
        return [];
    }

    $data = json_decode((string) file_get_contents($packageJson), true);

    if (!is_array($data) || !is_array($data['dependencies'] ?? null)) {
        return [];
    }

    // package.json dependencies[] are bundled npm libraries by convention
    // (devDependencies are tooling like rollup/eslint and don't ship to
    // /media/vendor). Treat each as a vendor candidate; the consumer can
    // trim the list afterward by editing cwm-build.config.json.
    $out = [];

    foreach (array_keys($data['dependencies']) as $pkg) {
        $out[] = [
            'npm'   => (string) $pkg,
            'label' => normaliseVendorLabel((string) $pkg),
            'notes' => '',
        ];
    }

    return $out;
}

/**
 * Pull npm package names out of older check-vendor-versions.js shapes:
 *   const VENDOR_PACKAGES = [{npm: "chart.js", ...}, ...];
 *   const vendors = ["chart.js", "@fancyapps/ui", ...];
 *   require('npm/foo')   // ignored — not a vendor declaration
 *
 * Robust enough for the shapes Proclaim and CWMScripture used; the user can
 * always override the result during the wizard.
 *
 * @return list<array{npm: string, label: string, notes: string}>
 */
function parseVendorListFromJs(string $source): array
{
    $vendors = [];

    if (preg_match_all('/\bnpm\s*:\s*[\'"]([^\'"]+)[\'"]/', $source, $m)) {
        foreach ($m[1] as $npm) {
            $vendors[$npm] = ['npm' => $npm, 'label' => normaliseVendorLabel($npm), 'notes' => ''];
        }
    }

    if ($vendors === []
        && preg_match('/\b(?:VENDOR_PACKAGES|VENDOR_LIST|vendors)\s*=\s*\[(.*?)\]/s', $source, $arr)
    ) {
        if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $arr[1], $m)) {
            foreach ($m[1] as $npm) {
                $vendors[$npm] = ['npm' => $npm, 'label' => normaliseVendorLabel($npm), 'notes' => ''];
            }
        }
    }

    return array_values($vendors);
}

/**
 * Convert an npm package name into a display label. Drops the scope on
 * scoped packages (`@vendor/name` → `name`) — most CWM consumers prefer
 * the bare leaf in the public-facing label and keep the scope only in
 * the npm install command.
 */
function normaliseVendorLabel(string $npm): string
{
    // Scoped packages (@vendor/name) → the user-visible name is usually the
    // tail. The existing Proclaim labels follow this rule (@fancyapps/ui →
    // "fancybox" was a hand-edit, but tail-as-label is the right default).
    if ($npm !== '' && $npm[0] === '@' && str_contains($npm, '/')) {
        return substr($npm, strpos($npm, '/') + 1);
    }

    return $npm;
}

/**
 * @return array{exists: bool, path: string, data?: array<string, mixed>, inRequireDev?: bool, hasVcsRepo?: bool, currentConstraint?: ?string}
 */
function readComposerStatus(string $projectRoot): array
{
    $path = $projectRoot . '/composer.json';

    if (!is_file($path)) {
        return ['exists' => false, 'path' => $path];
    }

    $data = json_decode((string) file_get_contents($path), true);

    if (!is_array($data)) {
        return ['exists' => false, 'path' => $path];
    }

    $inRequireDev = isset($data['require-dev']['cwm/build-tools']);
    $hasVcsRepo   = false;

    foreach ($data['repositories'] ?? [] as $repo) {
        if (!is_array($repo)) {
            continue;
        }

        if (($repo['type'] ?? '') === 'vcs'
            && str_contains((string) ($repo['url'] ?? ''), 'cwm-build-tools')
        ) {
            $hasVcsRepo = true;

            break;
        }
    }

    return [
        'exists'            => true,
        'path'              => $path,
        'data'              => $data,
        'inRequireDev'      => $inRequireDev,
        'hasVcsRepo'        => $hasVcsRepo,
        'currentConstraint' => $data['require-dev']['cwm/build-tools'] ?? null,
    ];
}

/**
 * Idempotent: re-running won't duplicate the require-dev entry, the
 * repositories[] VCS entry, or any of the scripts. Preserves all other
 * keys and their relative order.
 *
 * @param  array<string, mixed>   $data
 * @param  array<string, string>  $scripts  Composer scripts to add. Names
 *                                          that already exist in the
 *                                          consumer composer.json are
 *                                          skipped (and reported back via
 *                                          the return value's
 *                                          'scripts-skipped' list).
 *
 * @return array{require-dev: bool, repo: bool, scripts-added: list<string>, scripts-skipped: list<string>}
 */
function updateComposerJson(string $path, array $data, string $constraint, string $vcsUrl, array $scripts = []): array
{
    $report = [
        'require-dev'    => false,
        'repo'           => false,
        'scripts-added'  => [],
        'scripts-skipped'=> [],
    ];

    if (!isset($data['require-dev']['cwm/build-tools'])) {
        $data['require-dev']                    ??= [];
        $data['require-dev']['cwm/build-tools']   = $constraint;
        $report['require-dev']                    = true;
    }

    $hasRepo = false;

    foreach ($data['repositories'] ?? [] as $repo) {
        if (is_array($repo)
            && ($repo['type'] ?? '') === 'vcs'
            && str_contains((string) ($repo['url'] ?? ''), 'cwm-build-tools')
        ) {
            $hasRepo = true;

            break;
        }
    }

    if (!$hasRepo) {
        $data['repositories']   ??= [];
        $data['repositories'][]   = ['type' => 'vcs', 'url' => $vcsUrl];
        $report['repo']           = true;
    }

    if ($scripts !== []) {
        $data['scripts'] ??= [];

        foreach ($scripts as $name => $cmd) {
            if (isset($data['scripts'][$name])) {
                $report['scripts-skipped'][] = $name;

                continue;
            }

            $data['scripts'][$name] = $cmd;
            $report['scripts-added'][] = $name;
        }
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new \RuntimeException('Could not encode composer.json: ' . json_last_error_msg());
    }

    if (file_put_contents($path, $json . "\n") === false) {
        throw new \RuntimeException("Failed to write {$path}");
    }

    return $report;
}

/**
 * Inspect the consumer's package.json scripts for build:js / build:css /
 * watch entries that still point at locally-shipped copies of the templates
 * cwm-build-tools now provides. For each match, emit a suggested replacement
 * that invokes the shared template via the consumer's vendor-dir.
 *
 * Path arguments in the existing command are preserved verbatim into the
 * SOURCE_DIR / OUTPUT_DIR env vars where we can extract them — that's
 * usually the simplest mechanical rewrite. When we can't extract them, we
 * leave placeholders so the consumer fills them in.
 *
 * @return array<string, array{current: string, suggested: string}>
 */
function collectPackageJsonHints(string $projectRoot): array
{
    $path = $projectRoot . '/package.json';

    if (!is_file($path)) {
        return [];
    }

    $data = json_decode((string) file_get_contents($path), true);

    if (!is_array($data) || !is_array($data['scripts'] ?? null)) {
        return [];
    }

    $vendorDir = vendorDirFromComposerJson($projectRoot);
    $hints     = [];

    foreach ($data['scripts'] as $name => $cmd) {
        $cmd = (string) $cmd;

        if (!is_string($name) || $cmd === '') {
            continue;
        }

        // Already migrated — script invokes the cwm-build-tools template.
        if (str_contains($cmd, 'cwm/build-tools/templates/')) {
            continue;
        }

        // Legacy build/rollup.config.js → vendor template.
        if (str_contains($cmd, 'build/rollup.config.js') || str_contains($cmd, 'rollup.config.js')) {
            [$source, $output] = extractSourceOutput($cmd);
            $hints[$name] = [
                'current'   => $cmd,
                'suggested' => buildRollupCommand($vendorDir, $source, $output, str_contains($cmd, '-w') || $name === 'watch'),
            ];

            continue;
        }

        // Legacy build/build-css.js → vendor template.
        if (str_contains($cmd, 'build/build-css.js') || str_contains($cmd, 'build-css.js')) {
            [$source, $output] = extractSourceOutput($cmd);
            $hints[$name] = [
                'current'   => $cmd,
                'suggested' => buildCssCommand($vendorDir, $source, $output),
            ];

            continue;
        }
    }

    return $hints;
}

/**
 * @return array{0: string, 1: string} {source, output}
 */
function extractSourceOutput(string $cmd): array
{
    // Existing scripts often pass two positional path-like args after the
    // tool name. Pick the first two unquoted tokens that look like paths.
    $tokens = preg_split('/\s+/', $cmd) ?: [];
    $paths  = [];

    foreach ($tokens as $tok) {
        if ($tok === '' || $tok[0] === '-') {
            continue;
        }

        // path-ish: contains a slash or is a known path-like word
        if (str_contains($tok, '/') && !str_contains($tok, '=')) {
            $paths[] = $tok;
        }
    }

    // Skip the first path if it's the script being invoked (e.g. build/build-css.js).
    if ($paths !== [] && (str_ends_with($paths[0], '.js') || str_ends_with($paths[0], '.mjs'))) {
        array_shift($paths);
    }

    return [$paths[0] ?? '<SOURCE_DIR>', $paths[1] ?? '<OUTPUT_DIR>'];
}

/**
 * Format a package.json command that runs the shared rollup template via
 * the consumer's vendor-dir, preserving SOURCE_DIR/OUTPUT_DIR as env vars
 * so the template knows where to read from and write to.
 */
function buildRollupCommand(string $vendorDir, string $source, string $output, bool $watch): string
{
    $template = "{$vendorDir}/cwm/build-tools/templates/rollup.config.js";
    $watchArg = $watch ? ' -w' : '';

    return "SOURCE_DIR={$source} OUTPUT_DIR={$output} rollup -c {$template}{$watchArg}";
}

/**
 * Format a package.json command that runs the shared build-css template
 * via the consumer's vendor-dir, with SOURCE_DIR/OUTPUT_DIR env vars
 * pointing at the project's CSS source and built-output directories.
 */
function buildCssCommand(string $vendorDir, string $source, string $output): string
{
    $template = "{$vendorDir}/cwm/build-tools/templates/build-css.js";

    return "SOURCE_DIR={$source} OUTPUT_DIR={$output} node {$template}";
}

/**
 * Resolve the consumer's Composer vendor-dir setting from composer.json's
 * `config.vendor-dir`. Defaults to "vendor" when the key is absent or the
 * file is missing — that matches Composer's own default. Used to keep
 * generated package.json / eslint paths aligned with where Composer
 * actually installs cwm/build-tools.
 */
function vendorDirFromComposerJson(string $projectRoot): string
{
    $path = $projectRoot . '/composer.json';

    if (!is_file($path)) {
        return 'vendor';
    }

    $data = json_decode((string) file_get_contents($path), true);

    if (!is_array($data)) {
        return 'vendor';
    }

    $dir = trim((string) ($data['config']['vendor-dir'] ?? 'vendor'), '/');

    return $dir !== '' ? $dir : 'vendor';
}

/**
 * Recursively remove a directory and its contents. Symlinked directories
 * are NOT recursed into; the link itself is unlinked, leaving its target
 * untouched. Errors during individual file removals are suppressed (the
 * caller is the stale-artifact cleanup, where partial progress is fine).
 */
function removeDirectory(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }

    @rmdir($path);
}

/**
 * True when stdin appears to be a TTY. Used to bail out of the wizard
 * cleanly when the script is invoked from CI or a piped command rather
 * than silently hanging on the first prompt.
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
 * Prompt the user with a question and an optional pre-filled default.
 * Pressing Enter on an empty answer accepts $default. Returns the
 * trimmed user input (or $default when stdin is closed mid-read).
 */
function ask(string $question, string $default = ''): string
{
    $prompt = $question . ($default !== '' ? " [{$default}]" : '') . ': ';
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
