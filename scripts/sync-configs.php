<?php

/**
 * Sync managed config blocks into the consuming project.
 *
 * Reads templates from cwm-build-tools/templates/ and writes the marker-
 * delimited block(s) into the project's matching files. Lines outside the
 * markers are never touched.
 *
 * Currently handles:
 *   - .gitignore (managed block + extension-paths block)
 *   - eslint.config.mjs (writes a starter wrapper that extends the shared
 *     base when no config exists; leaves an existing config alone but
 *     prints a hint when it doesn't yet import the shared base).
 *
 * Future:
 *   - .editorconfig (full replace)
 *   - .php-cs-fixer.dist.php (regenerates the require-base wrapper)
 *
 * Usage:
 *   php sync-configs.php             # apply
 *   php sync-configs.php --dry-run   # preview (prints what *would* change)
 *
 * @license GPL-2.0-or-later
 */

require_once __DIR__ . '/../src/Config/ProfileResolver.php';

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
syncEslint($projectRoot, $templates, $projectConfig, $dryRun);
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

    $newContent = upsertBlock($existing, 'managed',         $managedBlock);
    $newContent = upsertBlock($newContent, 'extension paths', $extensionBlock);

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

    foreach (resolveOutputPaths($config) as $path) {
        $lines[] = $path;
    }

    foreach (resolveMediaPaths($config, (string) $name, (string) $type) as $path) {
        $lines[] = $path;
    }

    if ($lines === []) {
        return '';
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @return list<string>
 */
function resolveOutputPaths(array $config): array
{
    $explicit = $config['gitignore']['outputPaths'] ?? null;

    if (is_array($explicit)) {
        return array_values(array_map('strval', $explicit));
    }

    $glob = (string) ($config['build']['outputGlob'] ?? '');

    if ($glob === '') {
        return ['/build/dist/'];
    }

    $dir = trim(\dirname($glob), '/.');

    if ($dir === '') {
        return ['/build/dist/'];
    }

    return ['/' . $dir . '/'];
}

/**
 * @return list<string>
 */
function resolveMediaPaths(array $config, string $name, string $type): array
{
    $explicit = $config['gitignore']['mediaPaths'] ?? null;

    if (is_array($explicit)) {
        return array_values(array_map('strval', $explicit));
    }

    // Auto-derive only for the extension types whose name maps cleanly to a
    // single /media/<x>/ directory by Joomla convention. Packages own no
    // media dir of their own, plugins/modules don't have a generic one.
    if (!\in_array($type, ['library', 'component'], true)) {
        return [];
    }

    $stripped = preg_replace('/^(lib_|com_)/', '', $name);

    return [
        "/media/{$stripped}/js/*.min.js",
        "/media/{$stripped}/js/*.min.js.map",
        "/media/{$stripped}/css/*.min.css",
        "/media/{$stripped}/css/*.min.css.map",
    ];
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
 * Insert or replace a marker-delimited block in $content. Lines outside
 * the markers are preserved verbatim.
 */
function upsertBlock(string $content, string $blockId, string $blockBody): string
{
    $startMarker = "# === cwm-build-tools: {$blockId} (do not edit between markers) ===";
    $endMarker   = "# === cwm-build-tools: end {$blockId} ===";

    $blockBody = trim($blockBody, "\n");

    // Block has no content for this project — strip if present.
    if (trim($blockBody) === '') {
        $pattern = '/' . preg_quote($startMarker, '/') . '.*?' . preg_quote($endMarker, '/') . "\n?/s";

        return preg_replace($pattern, '', $content) ?? $content;
    }

    $newBlock = "{$startMarker}\n{$blockBody}\n{$endMarker}\n";

    if (str_contains($content, $startMarker)) {
        $pattern = '/' . preg_quote($startMarker, '/') . '.*?' . preg_quote($endMarker, '/') . "\n?/s";

        return preg_replace($pattern, $newBlock, $content) ?? $content;
    }

    // Append, preserving trailing newline behavior
    return rtrim($content, "\n") . "\n\n" . $newBlock;
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
