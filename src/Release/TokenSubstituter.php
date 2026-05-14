<?php

declare(strict_types=1);

namespace CWM\BuildTools\Release;

/**
 * Replaces a placeholder token (default `__DEPLOY_VERSION__`) with the actual
 * release version in configured source paths at release time.
 *
 * Joomla core uses `__DEPLOY_VERSION__` in `@since` PHPDoc tags throughout
 * its source tree. The release pipeline substitutes the token with the
 * version being cut so devs never have to predict the future at PR-write
 * time. This class brings the same convention to cwm-built extensions.
 *
 * Substitution runs ONLY during `cwm-release` (between bump and build) —
 * not during `cwm-bump` standalone. The placeholder is meant to stay in
 * source between releases, so dev branches keep accumulating
 * `@since __DEPLOY_VERSION__` until the next release locks in a real
 * version.
 *
 * Config shape (under cwm-build.config.json `versionTracking`):
 *
 *   "substituteTokens": {
 *     "token":      "__DEPLOY_VERSION__",
 *     "paths":      ["admin/", "site/", "libraries/", "modules/", "plugins/"],
 *     "extensions": ["php"]
 *   }
 *
 * Absent `substituteTokens` block → no-op.
 */
final class TokenSubstituter
{
    private const DEFAULT_TOKEN      = '__DEPLOY_VERSION__';
    private const DEFAULT_EXTENSIONS = ['php'];

    /**
     * Directories always skipped during the walk. Substituting inside vendored
     * code or VCS metadata would be a footgun.
     */
    private const ALWAYS_SKIP = ['vendor', 'node_modules', '.git'];

    /**
     * @param array{token?: string, paths?: list<string>, extensions?: list<string>} $config
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array  $config,
    ) {
    }

    /**
     * Walk configured paths, replace the token with $version in every file
     * matching the extension filter. Files without the token are left
     * untouched (no mtime bump, no needless writes).
     *
     * @return list<string> Files actually rewritten.
     */
    public function substitute(string $version): array
    {
        $token      = $this->config['token']      ?? self::DEFAULT_TOKEN;
        $paths      = $this->config['paths']      ?? [];
        $extensions = $this->config['extensions'] ?? self::DEFAULT_EXTENSIONS;

        if ($paths === []) {
            return [];
        }

        $touched = [];

        foreach ($paths as $relative) {
            $absolute = $this->projectRoot . '/' . ltrim((string) $relative, '/');

            if (!file_exists($absolute)) {
                fwrite(STDERR, "Warning: substituteTokens path not found: $absolute (skipped)\n");
                continue;
            }

            foreach ($this->walkFiles($absolute, $extensions) as $file) {
                if ($this->replaceInFile($file, $token, $version)) {
                    $touched[] = $file;
                }
            }
        }

        return $touched;
    }

    /**
     * @param  list<string> $extensions
     * @return iterable<string>
     */
    private function walkFiles(string $path, array $extensions): iterable
    {
        if (is_file($path)) {
            if ($this->matchesExtension($path, $extensions)) {
                yield $path;
            }
            return;
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current): bool {
                    if ($current->isDir() && in_array($current->getFilename(), self::ALWAYS_SKIP, true)) {
                        return false;
                    }
                    return true;
                },
            ),
        );

        foreach ($iter as $info) {
            if ($info->isFile() && $this->matchesExtension($info->getPathname(), $extensions)) {
                yield $info->getPathname();
            }
        }
    }

    /**
     * @param list<string> $extensions
     */
    private function matchesExtension(string $path, array $extensions): bool
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return in_array($ext, $extensions, true);
    }

    /**
     * Read file, replace token if present, write back only when content
     * actually changed. Returns true when the file was rewritten.
     */
    private function replaceInFile(string $path, string $token, string $version): bool
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            fwrite(STDERR, "Warning: could not read $path (skipped)\n");
            return false;
        }

        if (!str_contains($contents, $token)) {
            return false;
        }

        $replaced = str_replace($token, $version, $contents);

        if ($replaced === $contents) {
            return false;
        }

        if (file_put_contents($path, $replaced) === false) {
            throw new \RuntimeException("Could not write $path");
        }

        $count = substr_count($contents, $token);
        echo "  $path → $count replacement(s)\n";

        return true;
    }
}
