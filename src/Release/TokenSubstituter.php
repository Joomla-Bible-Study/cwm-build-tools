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
     * Fold the extension's install script into the configured paths.
     *
     * The installer named by `package.installer` (or `build.scriptFile`) is
     * shipped source — it is the manifest's <scriptfile>, and Joomla runs it on
     * every install. It also lives in `build/`, which no project lists under
     * `substituteTokens.paths` because the rest of that directory is tooling
     * that must not be rewritten. So the one file in there that genuinely ships
     * was the one file never substituted, and every release published it with a
     * literal __DEPLOY_VERSION__ in its docblocks (#75).
     *
     * Derived from configuration the tool already reads, so no project has to
     * remember to add it, and it cannot over-reach into the rest of build/.
     *
     * @param  array{paths?: list<string>} $substituteConfig the versionTracking.substituteTokens block
     * @param  array<string, mixed>        $config           the whole cwm-build.config.json
     * @return list<string> paths to walk, installer included, without duplicates
     */
    public static function pathsWithInstaller(array $substituteConfig, array $config): array
    {
        $paths = array_values(array_filter(
            $substituteConfig['paths'] ?? [],
            static fn ($p): bool => \is_string($p) && $p !== '',
        ));

        $candidates = [
            $config['package']['installer'] ?? null,
            $config['build']['scriptFile']  ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (\is_string($candidate) && $candidate !== '' && !\in_array($candidate, $paths, true)) {
                $paths[] = $candidate;
            }
        }

        return $paths;
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

            if ($this->isSubmoduleRoot($absolute)) {
                fwrite(STDERR, "Warning: substituteTokens path is a git submodule: $relative (skipped)\n");
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
     * Every file the next `substitute()` call would rewrite.
     *
     * Exists so a caller can snapshot those files before substituting and put
     * them back afterwards. `Build\ChildTokenSubstitution` needs that: it
     * substitutes a `subBuild` child's working tree so the packaged child does
     * not ship the literal token, but that tree is usually a submodule
     * checkout, and leaving it rewritten would show up as another repo's
     * version staged into it (#1704).
     *
     * Silent about missing paths — `substitute()` already warns, and warning
     * twice for one release reads like two different problems.
     *
     * @return list<string>
     */
    public function filesContainingToken(): array
    {
        $token      = $this->config['token']      ?? self::DEFAULT_TOKEN;
        $paths      = $this->config['paths']      ?? [];
        $extensions = $this->config['extensions'] ?? self::DEFAULT_EXTENSIONS;

        $found = [];

        foreach ($paths as $relative) {
            $absolute = $this->projectRoot . '/' . ltrim((string) $relative, '/');

            if (!file_exists($absolute) || $this->isSubmoduleRoot($absolute)) {
                continue;
            }

            foreach ($this->walkFiles($absolute, $extensions) as $file) {
                $contents = file_get_contents($file);

                if ($contents !== false && str_contains($contents, $token)) {
                    $found[] = $file;
                }
            }
        }

        return $found;
    }

    /**
     * Is this configured path root itself a submodule (or a nested clone)?
     *
     * The descent filter in {@see walkFiles()} skips submodules it *encounters*,
     * but an iterator filter never sees the root it was handed — so a project
     * that names a submodule directly in `paths` was substituting it with the
     * outer version, which is the exact thing the 1.16.0 guard exists to
     * prevent (#92). `libraries/` was safe only because descent found the
     * submodule one level down; `libraries/lib_cwmscripture/` was not.
     *
     * Checked here rather than inside `walkFiles()` so that a *file* path root
     * — `pathsWithInstaller()` adds one — is unaffected.
     *
     * ⚠️ This is about the path root, not the project root.
     * `Build\ChildTokenSubstitution` points a substituter at a submodule's own
     * tree on purpose, with paths like `src/` *inside* it; those roots are not
     * submodules, so they are untouched by this. A repo's own release
     * substituting its own paths is always correct — it is only the *outer*
     * repo reaching in that is wrong.
     */
    private function isSubmoduleRoot(string $path): bool
    {
        return is_dir($path) && file_exists($path . '/.git');
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
                    if (!$current->isDir()) {
                        return true;
                    }

                    if (in_array($current->getFilename(), self::ALWAYS_SKIP, true)) {
                        return false;
                    }

                    // A git submodule is another repository's source, with its own
                    // version and its own release that substitutes its own token.
                    // Writing this project's version into it produces @since tags
                    // for a version that extension never had — Proclaim 10.4.1
                    // stamped 10.4.1 into a plugin whose own version was 1.1.5 —
                    // and leaves the submodule dirty, so the wrong values can be
                    // committed there by accident later.
                    //
                    // Detected by the presence of a `.git` entry rather than by
                    // parsing .gitmodules: a submodule checkout carries a `.git`
                    // *file* pointing at the parent's modules dir, and ALWAYS_SKIP
                    // only matches directories. Also catches a plain nested clone,
                    // which is the same hazard without being declared anywhere.
                    return !file_exists($current->getPathname() . '/.git');
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
