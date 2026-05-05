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

$projectRoot = getcwd();
$toolsRoot   = realpath(__DIR__ . '/..');
$templates   = $toolsRoot . '/templates';

$opts   = getopt('', ['dry-run']);
$dryRun = isset($opts['dry-run']);

$configFile    = $projectRoot . '/cwm-build.config.json';
$projectConfig = is_file($configFile) ? json_decode(file_get_contents($configFile), true) : [];

syncGitignore($projectRoot, $templates, $projectConfig, $dryRun);

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
 * Uses the project's extension name to scope path patterns to the right
 * media/ directories. Only emits paths that make sense for the extension type.
 */
function renderExtensionPathsBlock(array $config): string
{
    $name = $config['extension']['name'] ?? null;
    $type = $config['extension']['type'] ?? null;

    if (!$name) {
        return '';
    }

    $lines = ['/build/dist/'];

    // Library, component, and package extensions own media/ directories
    if (\in_array($type, ['library', 'component', 'package'], true)) {
        $mediaName = preg_replace('/^(lib_|com_|pkg_)/', '', $name);
        $lines[]   = "/media/{$mediaName}/js/*.min.js";
        $lines[]   = "/media/{$mediaName}/js/*.min.js.map";
        $lines[]   = "/media/{$mediaName}/css/*.min.css";
        $lines[]   = "/media/{$mediaName}/css/*.min.css.map";
    }

    return implode("\n", $lines) . "\n";
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
