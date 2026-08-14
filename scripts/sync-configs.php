<?php

/**
 * Sync managed config blocks into the consuming project.
 *
 * Reads templates from cwm-build-tools/templates/ and writes the marker-
 * delimited block(s) into the project's matching files. Lines outside the
 * markers are never touched.
 *
 * Handles:
 *   - .gitignore (managed block + extension-paths block)
 *   - build.dist.properties (full replace from the canonical template)
 *   - .editorconfig (full replace, but only of a copy this tool wrote —
 *     see the managed-file header in src/Config/ManagedFile.php)
 *   - .php-cs-fixer.dist.php (regenerates the require-base wrapper, unless
 *     the consumer has customised it)
 *   - phpunit.xml (created once from the boilerplate, never overwritten —
 *     the testsuite layout is project shape, not shared policy)
 *   - eslint.config.mjs (writes a starter wrapper that extends the shared
 *     base when no config exists; leaves an existing config alone but
 *     prints a hint when it doesn't yet import the shared base).
 *
 * The recurring rule across all of them: a file the consumer wrote is never
 * silently replaced. Either the tool can prove it wrote the file (a managed
 * header, or a wrapper still in its generated shape) or it prints a hint and
 * leaves the file alone.
 *
 * Usage:
 *   php sync-configs.php             # apply
 *   php sync-configs.php --dry-run   # preview (prints what *would* change)
 *
 * @license GPL-2.0-or-later
 */

require_once __DIR__ . '/../src/Config/ProfileResolver.php';
require_once __DIR__ . '/../src/Config/DistPropertiesInspector.php';
require_once __DIR__ . '/../src/Config/GitignorePaths.php';
require_once __DIR__ . '/../src/Config/ManagedBlock.php';
require_once __DIR__ . '/../src/Config/ManagedFile.php';
require_once __DIR__ . '/../src/Config/PhpCsFixerWrapper.php';

$projectRoot = getcwd();
$toolsRoot   = realpath(__DIR__ . '/..');
$templates   = $toolsRoot . '/templates';

$opts   = getopt('', ['dry-run']);
$dryRun = isset($opts['dry-run']);

$configFile    = $projectRoot . '/cwm-build.config.json';
$projectConfig = is_file($configFile) ? json_decode(file_get_contents($configFile), true) : [];

if (!is_array($projectConfig)) {
    $projectConfig = [];
}

syncGitignore($projectRoot, $templates, $projectConfig, $dryRun);
syncBuildDistProperties($projectRoot, $templates, $dryRun);
syncEditorConfig($projectRoot, $templates, $dryRun);
syncPhpCsFixer($projectRoot, $dryRun);
syncPhpunit($projectRoot, $templates, $dryRun);
syncEslint($projectRoot, $templates, $projectConfig, $dryRun);
checkEslintInvocation($projectRoot);
checkProfileHints($projectConfig, $toolsRoot);

echo $dryRun ? "(dry-run; no files written)\n" : "Done.\n";

/**
 * Sync managed blocks of .gitignore. Lines outside the markers are preserved.
 */
function syncGitignore(string $projectRoot, string $templates, array $config, bool $dryRun): void
{
    $target   = $projectRoot . '/.gitignore';
    $existing = is_file($target) ? file_get_contents($target) : '';

    $managedBlock   = file_get_contents($templates . '/gitignore-managed.txt') ?: '';
    $extensionBlock = renderExtensionPathsBlock($config);

    $newContent = \CWM\BuildTools\Config\ManagedBlock::upsert($existing, 'managed', $managedBlock);
    $newContent = \CWM\BuildTools\Config\ManagedBlock::upsert($newContent, 'extension paths', $extensionBlock);

    if ($newContent === $existing) {
        echo ".gitignore: up to date\n";

        return;
    }

    if ($dryRun) {
        echo ".gitignore: would update (dry-run)\n";
        echo "  - existing length: " . \strlen($existing) . " bytes\n";
        echo "  - new length:      " . \strlen($newContent) . " bytes\n";
    } else {
        file_put_contents($target, $newContent);
        echo ".gitignore: updated\n";
    }
}

/**
 * Render the auto-generated "extension paths" block for .gitignore.
 *
 * Two layers of input, both optional, in this order:
 *
 *   1. Explicit overrides under cwm-build.config.json `gitignore.outputPaths[]`
 *      and `gitignore.mediaPaths[]`. When present, they replace the auto-derived
 *      defaults entirely so a project with a non-standard layout (Proclaim's
 *      `/media/com_proclaim/` + `/media/lib_cwmscripture/` mix instead of
 *      a single `/media/<stripped>/`) doesn't have to fight the defaults.
 *      cwm-init seeds these by walking the project's media/ directory.
 *
 *   2. Auto-derived fallback. Output dir comes from `build.outputGlob`'s
 *      dirname so projects whose builds write to `build/packages/` or
 *      `build/artifacts/` don't get a stale `/build/dist/` ignore. Media
 *      patterns are emitted ONLY for libraries and components (`pkg_X` is
 *      a wrapper that doesn't own a media dir of its own — issue #4.2).
 */
function renderExtensionPathsBlock(array $config): string
{
    $name = $config['extension']['name'] ?? null;
    $type = $config['extension']['type'] ?? null;

    if (!$name) {
        return '';
    }

    $lines = [];

    foreach (\CWM\BuildTools\Config\GitignorePaths::outputPaths($config) as $path) {
        $lines[] = $path;
    }

    foreach (\CWM\BuildTools\Config\GitignorePaths::mediaPaths($config, (string) $name, (string) $type) as $path) {
        $lines[] = $path;
    }

    if ($lines === []) {
        return '';
    }

    return implode("\n", $lines) . "\n";
}

/**
 * Sync the project's eslint.config.mjs.
 *
 * Three states, three actions:
 *
 *   - **No eslint config exists.** Write a starter that imports the shared
 *     base under the consumer's vendor-dir. Project-specific globals/
 *     overrides go into the wrapper later — we don't try to predict them.
 *
 *   - **Config exists and already imports the shared base.** No-op (we
 *     have no good way to merge changes into hand-edited globals lists).
 *
 *   - **Config exists but doesn't import the base.** Leave it alone but
 *     print a hint with the exact import line so the consumer can migrate
 *     by hand. Auto-rewriting risks clobbering bespoke rules and isn't
 *     worth the surprise.
 */
function syncEslint(string $projectRoot, string $templates, array $config, bool $dryRun): void
{
    $target = $projectRoot . '/eslint.config.mjs';

    $vendorDir = vendorDirFromComposer($projectRoot);
    $importPath = "./{$vendorDir}/cwm/build-tools/templates/eslint.config.base.mjs";

    if (!is_file($target)) {
        $extName     = (string) ($config['extension']['name'] ?? '');
        $globalsLine = $extName !== ''
            ? "                {$extName}: 'readonly',"
            : "                // ProjectName: 'readonly',";

        // Heredoc indented 8 spaces (matches the surrounding block); PHP's
        // flexible heredoc strips that prefix at runtime so the file content
        // lines up at column 0 in the resulting eslint.config.mjs.
        $starter = <<<MJS
        // eslint.config.mjs — extends the shared CWM base.
        // Project-specific globals and overrides go in this wrapper, not in the
        // upstream base file. Run 'composer sync-configs' to refresh after the
        // base is updated; this file is left untouched once present.

        import baseConfig from '{$importPath}';

        export default [
            ...baseConfig,
            {
                files: ['**/*.js', '**/*.mjs', '**/*.es6.js'],
                languageOptions: {
                    globals: {
        {$globalsLine}
                    },
                },
            },
        ];

        MJS;

        if ($dryRun) {
            echo "eslint.config.mjs: would create starter wrapper (dry-run)\n";

            return;
        }

        file_put_contents($target, $starter);
        echo "eslint.config.mjs: created starter wrapper\n";

        return;
    }

    $existing = (string) file_get_contents($target);

    if (str_contains($existing, 'cwm/build-tools/templates/eslint.config.base.mjs')) {
        echo "eslint.config.mjs: up to date (already imports shared base)\n";

        return;
    }

    echo "eslint.config.mjs: not extending the shared base — leaving in place.\n";
    echo "  To migrate, replace the project rule list with the wrapper pattern:\n";
    echo "    import baseConfig from '{$importPath}';\n";
    echo "    export default [...baseConfig, /* project overrides */];\n";
}

/**
 * Distribute the canonical `build.dist.properties` template from
 * cwm-build-tools to the consuming project.
 *
 * Background: build.dist.properties is the committed template each
 * consumer ships so a fresh clone can run `cp build.dist.properties
 * build.properties` (or have it auto-copied by a post-install-cmd) and
 * have a starting point. Historically every consumer carried its own
 * hand-edited copy and they drifted from the cwm-build-tools schema —
 * recent additions (role field, [paths] block, [j5-test] section)
 * exposed how fast the drift accumulates.
 *
 * This function makes the template authoritative: cwm-build-tools owns
 * the shape at `templates/build.properties.tmpl` and `cwm-sync-configs`
 * writes it (with an auto-managed header) into each consumer's
 * `build.dist.properties`. Devs continue to customize per-machine state
 * in `build.properties` (gitignored), not here.
 */
function syncBuildDistProperties(string $projectRoot, string $templates, bool $dryRun): void
{
    $source = $templates . '/build.properties.tmpl';
    $target = $projectRoot . '/build.dist.properties';

    if (!is_file($source)) {
        echo "build.dist.properties: cwm-build-tools template missing at {$source} — skipped\n";

        return;
    }

    $templateBody = (string) file_get_contents($source);

    // The template is committed by every consumer, so it must never ship a
    // populated secret. Checked before anything is written: if this ever trips,
    // the leak is in cwm-build-tools and would propagate to every project.
    $inspector       = new \CWM\BuildTools\Config\DistPropertiesInspector();
    $templateSecrets = $inspector->populatedSecretKeys($templateBody);

    if ($templateSecrets !== []) {
        echo "build.dist.properties: REFUSING to sync — the cwm-build-tools template has populated\n";
        echo "  credential values, and this file is committed by every consumer:\n";

        foreach ($templateSecrets as $key) {
            echo "    {$key}\n";
        }

        echo "  Blank them in {$source} before running this again.\n";

        return;
    }

    // Comment marker is `#` (not `;`) so Java-properties-aware IDEs accept
    // it cleanly; PHP's `parse_ini_string` accepts both. The template body
    // already starts with the canonical header, so we don't re-stamp our
    // own — `composer cwm-sync-configs` is the only writer either way.
    $newContent = $templateBody;
    $existing   = is_file($target) ? (string) file_get_contents($target) : '';

    if ($existing === $newContent) {
        echo "build.dist.properties: up to date\n";

        return;
    }

    // Two things worth saying out loud before this file is replaced. Both
    // describe the OLD content, so they have to be reported whether or not the
    // write actually happens.
    $existingSecrets = $inspector->populatedSecretKeys($existing);
    $droppedKeys     = $inspector->keysMissingFromTemplate($existing, $templateBody);

    if ($existingSecrets !== []) {
        echo "build.dist.properties: WARNING — the current file has real values in credential keys:\n";

        foreach ($existingSecrets as $key) {
            echo "    {$key}\n";
        }

        echo "  This file is committed. Per-machine values belong in build.properties (gitignored).\n";
        echo "  Check whether they reached git: git log -p -- build.dist.properties\n";
    }

    if ($droppedKeys !== []) {
        echo "build.dist.properties: NOTE — " . \count($droppedKeys) . " key(s) present locally are not in\n";
        echo "  the cwm-build-tools template and will be removed:\n";

        foreach ($droppedKeys as $key) {
            echo "    {$key}\n";
        }

        echo "  If they are a legitimate part of the schema, add them to the template instead.\n";
    }

    if ($dryRun) {
        echo "build.dist.properties: would " . ($existing === '' ? 'create' : 'update') . " (dry-run)\n";

        return;
    }

    file_put_contents($target, $newContent);
    echo "build.dist.properties: " . ($existing === '' ? 'created' : 'updated') . " from template\n";
}



/**
 * Sync the project's .editorconfig from the shared template.
 *
 * Full replace, with one precondition: the existing file must carry the
 * managed-file header, proving a previous sync wrote it. A consumer that
 * predates this handler has a hand-written .editorconfig — Proclaim and
 * CWMScriptureLinks both do — and replacing it would change the indent
 * settings under every open editor in the project without being asked.
 *
 * That is also why the shared template matches the settings those two
 * already had (PHP at four spaces) rather than joomla-cms core's tabs:
 * adopting the template should be a no-op for the projects adopting it.
 */
function syncEditorConfig(string $projectRoot, string $templates, bool $dryRun): void
{
    $source = $templates . '/.editorconfig';
    $target = $projectRoot . '/.editorconfig';

    if (!is_file($source)) {
        echo ".editorconfig: cwm-build-tools template missing at {$source} — skipped\n";

        return;
    }

    $newContent = \CWM\BuildTools\Config\ManagedFile::stamp((string) file_get_contents($source));
    $existing   = is_file($target) ? (string) file_get_contents($target) : '';

    if ($existing === $newContent) {
        echo ".editorconfig: up to date\n";

        return;
    }

    if ($existing !== '' && !\CWM\BuildTools\Config\ManagedFile::isManaged($existing)) {
        echo ".editorconfig: locally owned (no managed header) — leaving in place.\n";
        echo "  To adopt the shared one, delete .editorconfig and run this again.\n";
        echo "  Diff it first if the project has deliberate settings:\n";
        echo "    diff .editorconfig {$source}\n";

        return;
    }

    if ($dryRun) {
        echo '.editorconfig: would ' . ($existing === '' ? 'create' : 'update') . " (dry-run)\n";

        return;
    }

    file_put_contents($target, $newContent);
    echo '.editorconfig: ' . ($existing === '' ? 'created' : 'updated') . " from template\n";
}

/**
 * Sync the project's .php-cs-fixer.dist.php wrapper.
 *
 * The wrapper is regenerated rather than copied, because the require path
 * embeds the consumer's vendor-dir. Regeneration stops as soon as the file
 * stops being a bare `return require` — at that point it holds project
 * decisions (an extra exclude, a rule override) and rewriting it would
 * silently drop them.
 *
 * A file that doesn't reference the shared base at all is left alone with
 * a migration hint, matching how syncEslint treats a bespoke eslint config.
 */
function syncPhpCsFixer(string $projectRoot, bool $dryRun): void
{
    $target    = $projectRoot . '/.php-cs-fixer.dist.php';
    $vendorDir = vendorDirFromComposer($projectRoot);

    $wrapper     = \CWM\BuildTools\Config\PhpCsFixerWrapper::render($vendorDir);
    $requirePath = \CWM\BuildTools\Config\PhpCsFixerWrapper::requirePath($vendorDir);

    $existing = is_file($target) ? (string) file_get_contents($target) : '';

    if ($existing === $wrapper) {
        echo ".php-cs-fixer.dist.php: up to date\n";

        return;
    }

    if ($existing !== '' && !\CWM\BuildTools\Config\PhpCsFixerWrapper::extendsSharedBase($existing)) {
        echo ".php-cs-fixer.dist.php: not extending the shared base — leaving in place.\n";
        echo "  To migrate, replace the rule set with:\n";
        echo "    return require __DIR__ . '/{$requirePath}';\n";

        return;
    }

    if ($existing !== '' && !\CWM\BuildTools\Config\PhpCsFixerWrapper::isGenerated($existing)) {
        echo ".php-cs-fixer.dist.php: customised wrapper — leaving in place.\n";
        echo "  It extends the shared base and adds project overrides, so the sync\n";
        echo "  won't rewrite it. Check the require path still reads:\n";
        echo "    __DIR__ . '/{$requirePath}'\n";

        return;
    }

    if ($dryRun) {
        echo '.php-cs-fixer.dist.php: would ' . ($existing === '' ? 'create' : 'update') . " wrapper (dry-run)\n";

        return;
    }

    file_put_contents($target, $wrapper);
    echo '.php-cs-fixer.dist.php: ' . ($existing === '' ? 'created' : 'updated') . " wrapper\n";
}

/**
 * Seed the project's phpunit.xml from the boilerplate, once.
 *
 * Unlike the other handlers this never updates an existing file. The parts
 * worth sharing are the strict-mode flags, and they only matter at the
 * moment a project starts testing; everything else in the file — which
 * directories hold the suites, which hold the source — is the project's
 * own shape, and a "refresh" would overwrite it with a guess.
 */
function syncPhpunit(string $projectRoot, string $templates, bool $dryRun): void
{
    $source = $templates . '/phpunit.xml.tmpl';
    $target = $projectRoot . '/phpunit.xml';

    if (!is_file($source)) {
        echo "phpunit.xml: cwm-build-tools template missing at {$source} — skipped\n";

        return;
    }

    if (is_file($target) || is_file($projectRoot . '/phpunit.xml.dist')) {
        echo "phpunit.xml: present — left alone (testsuite layout is project-owned)\n";

        return;
    }

    if ($dryRun) {
        echo "phpunit.xml: would create from boilerplate (dry-run)\n";

        return;
    }

    file_put_contents($target, (string) file_get_contents($source));
    echo "phpunit.xml: created from boilerplate — point <directory> at the project's source\n";
}

/**
 * Read composer.json `config.vendor-dir` so the eslint import path matches
 * the consumer's actual install layout. Falls back to the Composer default
 * `vendor` when the key is absent or composer.json isn't present.
 */
function vendorDirFromComposer(string $projectRoot): string
{
    $path = $projectRoot . '/composer.json';

    if (!is_file($path)) {
        return 'vendor';
    }

    $data = json_decode((string) file_get_contents($path), true);

    if (!is_array($data)) {
        return 'vendor';
    }

    $dir = (string) ($data['config']['vendor-dir'] ?? 'vendor');

    return trim($dir, '/') ?: 'vendor';
}

/**
 * Surface profile-related migration hints without rewriting cwm-build.config.json.
 *
 * Two states worth nudging on:
 *
 *   - **No `profile` key + inline `versionTracking`.** The consumer pre-dates
 *     the profile mechanism. Suggest the detected profile so future shape
 *     changes flow in via `composer update` instead of per-repo edits.
 *
 *   - **`profile` set + redundant inline `versionTracking`.** The consumer
 *     migrated but left the hand-written block as a safety net. If it adds
 *     nothing on top of the profile defaults, point out that it's safe to
 *     delete so drift can't accumulate later.
 *
 * Never rewrites the file — cwm-build.config.json is hand-authored config
 * that may carry comments-as-keys or layout choices we shouldn't disturb.
 *
 * @param array<string, mixed> $config
 */
function checkProfileHints(array $config, string $toolsRoot): void
{
    $profile  = $config['profile'] ?? null;
    $inline   = $config['versionTracking'] ?? null;
    $detected = \CWM\BuildTools\Config\ProfileResolver::detect($config);

    if ($profile === null && is_array($inline) && $inline !== []) {
        $suggestion = $detected !== null
            ? "consider adding \"profile\": \"{$detected}\""
            : 'consider declaring a "profile" key (component, library, package-wrapper)';

        echo "cwm-build.config.json: {$suggestion} so versionTracking stays in sync with cwm-build-tools updates.\n";

        return;
    }

    if (is_string($profile) && is_array($inline) && $inline !== []) {
        try {
            $profileOnly = \CWM\BuildTools\Config\ProfileResolver::resolve(
                ['profile' => $profile],
                $toolsRoot
            );
        } catch (\Throwable $e) {
            // Unknown profile — let the regular resolver flag it at run time.
            return;
        }

        if ($profileOnly === $inline) {
            echo "cwm-build.config.json: versionTracking block matches profile '{$profile}' defaults — safe to delete.\n";
        }
    }
}

/**
 * Warn when a consumer's `lint:js` script does not pin the config file.
 *
 * ESLint 10 resolves the nearest `eslint.config.*` per file rather than using
 * only the root one. A plain `eslint .` therefore walks into any directory
 * carrying its own config -- a git submodule, or this package's own install
 * root under vendor/ -- and lints those files under *that* config, which means
 * the `ignores` in templates/eslint.config.base.mjs never apply to them.
 *
 * Two ways that shows up, both seen on Proclaim (issue #90):
 *
 *   - A hard failure, when the nested config imports a base file whose Composer
 *     vendor tree is not installed: "Cannot find module ...eslint.config.base.mjs".
 *   - Silently linting more than intended. Proclaim's root config listed the
 *     submodule paths under `ignores` and they were linted regardless; the file
 *     count dropped 113 -> 105 once the config was pinned.
 *
 * Advisory only. package.json belongs to the consumer, and sync-configs does
 * not rewrite hand-authored files -- the same reason checkProfileHints() below
 * reports rather than edits.
 */
function checkEslintInvocation(string $projectRoot): void
{
    $packageJson = $projectRoot . '/package.json';

    if (!is_file($packageJson)) {
        return;
    }

    try {
        $package = json_decode((string) file_get_contents($packageJson), true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        // A malformed package.json is not this handler's problem to report.
        return;
    }

    $script = $package['scripts']['lint:js'] ?? null;

    if (!is_string($script) || $script === '') {
        return;
    }

    // Either form pins the config: --no-config-lookup stops the per-file search
    // outright, and -c/--config names the file to use.
    if (str_contains($script, '--no-config-lookup')
        || str_contains($script, '--config ')
        || preg_match('/(^|\s)-c\s/', $script) === 1) {
        return;
    }

    echo "package.json: lint:js does not pin an ESLint config.\n"
        . "  ESLint 10 loads the nearest eslint.config.* per file, so the ignores in\n"
        . "  the shared base config are advisory for any directory that has its own\n"
        . "  (a submodule, or vendor/). Consider:\n"
        . "    \"lint:js\": \"eslint --no-config-lookup -c eslint.config.mjs --max-warnings=0 .\"\n";
}
