<?php

declare(strict_types=1);

namespace CWM\BuildTools\Release;

/**
 * Replaces placeholder tokens with resolved values in configured source
 * paths at release (or package) time.
 *
 * Joomla core uses `__DEPLOY_VERSION__` in `@since` PHPDoc tags throughout
 * its source tree. The release pipeline substitutes the token with the
 * version being cut so devs never have to predict the future at PR-write
 * time. This class brings the same convention to cwm-built extensions —
 * and generalizes it to an arbitrary named token map, so a project whose
 * manifest template uses its own convention (Akeeba's Ant/Phing-style
 * `##VERSION##`/`##DATE##`, say) resolves through the same engine instead
 * of a second, parallel one.
 *
 * Config shape (under cwm-build.config.json `versionTracking`):
 *
 *   "substituteTokens": {
 *     "token":      "__DEPLOY_VERSION__",
 *     "paths":      ["admin/", "site/", "libraries/", "modules/", "plugins/"],
 *     "extensions": ["php"]
 *   }
 *
 * `token` is the legacy single-placeholder shape and keeps working exactly
 * as before — every existing `__DEPLOY_VERSION__` consumer is unaffected.
 * The general shape is `tokens`, a map of literal placeholder text to a
 * value template, evaluated by {@see resolveValue()}:
 *
 *   "substituteTokens": {
 *     "tokens": {
 *       "##VERSION##": "{version}",
 *       "##DATE##":    "{date:Y-m-d}",
 *       "##VENDOR##":  "Akeeba Ltd"
 *     },
 *     "paths":      ["build/templates/"],
 *     "extensions": ["xml"]
 *   }
 *
 * A value template is `"{version}"` (the version being substituted),
 * `"{date}"` / `"{date:FORMAT}"` (resolved at substitution time via
 * {@see DateTokenExpander}, same convention as `VersionTracker`'s
 * `devSuffix`), or any other string, used literally. `token` and `tokens`
 * are mutually exclusive in practice — when `tokens` is non-empty it wins;
 * `token` (or its `__DEPLOY_VERSION__` default) is only consulted when
 * `tokens` is absent or empty, and is normalized into the same one-entry
 * map internally, so there is exactly one substitution code path.
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
     * @param array{token?: string, tokens?: array<string, string>, paths?: list<string>, extensions?: list<string>} $config
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array  $config,
    ) {
    }

    /**
     * Resolve a template value against the version being substituted.
     *
     * Shared by the tree-walking substitution below and by any caller that
     * needs to resolve one token value on its own — e.g. a single in-place
     * file substitution that does not go through `paths`/`extensions` at
     * all (see `Build\PackageManifestSubstitution`, which resolves package
     * manifest tokens like `##VERSION##`/`##DATE##` through this same
     * method so there is one place that understands `{version}`/`{date}`).
     *
     * `{version}` resolves to $version. `{date}` / `{date:FORMAT}` resolves
     * via {@see DateTokenExpander} at call time. Anything else is returned
     * as-is — a literal replacement value.
     */
    public static function resolveValue(string $template, string $version): string
    {
        if ($template === '{version}') {
            return $version;
        }

        if (str_contains($template, '{date')) {
            return DateTokenExpander::expand($template, new \DateTimeImmutable());
        }

        return $template;
    }

    /**
     * The configured token map, literal placeholder text => value template.
     *
     * `tokens` (new, general shape) wins when non-empty. Otherwise falls
     * back to the legacy single `token` field (default
     * `__DEPLOY_VERSION__`), normalized into a one-entry map whose value is
     * `"{version}"` — i.e. exactly the old "replace token with version"
     * behavior, expressed as the smallest case of the general one.
     *
     * @return array<string, string>
     */
    private function resolveTokenMap(): array
    {
        $tokens = $this->config['tokens'] ?? null;

        if (is_array($tokens) && $tokens !== []) {
            return array_map('strval', $tokens);
        }

        $legacyToken = (string) ($this->config['token'] ?? self::DEFAULT_TOKEN);

        return [$legacyToken => '{version}'];
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
        $tokens     = $this->resolveTokenMap();
        $paths      = $this->config['paths']      ?? [];
        $extensions = $this->config['extensions'] ?? self::DEFAULT_EXTENSIONS;

        if ($paths === [] || $tokens === []) {
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
                if ($this->replaceInFile($file, $tokens, $version)) {
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
     * version staged into it (Joomla-Bible-Study/Proclaim#1704).
     *
     * Silent about missing paths — `substitute()` already warns, and warning
     * twice for one release reads like two different problems.
     *
     * @return list<string>
     */
    public function filesContainingToken(): array
    {
        $tokens     = $this->resolveTokenMap();
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

                if ($contents === false) {
                    continue;
                }

                foreach (array_keys($tokens) as $placeholder) {
                    if (str_contains($contents, $placeholder)) {
                        $found[] = $file;
                        break;
                    }
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
     * Read file, replace every configured token present, write back only
     * when content actually changed. Returns true when the file was
     * rewritten.
     *
     * @param array<string, string> $tokens Literal placeholder text => value template.
     */
    private function replaceInFile(string $path, array $tokens, string $version): bool
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            fwrite(STDERR, "Warning: could not read $path (skipped)\n");
            return false;
        }

        $original     = $contents;
        $replacements = 0;

        foreach ($tokens as $placeholder => $template) {
            if (!str_contains($contents, $placeholder)) {
                continue;
            }

            $replacements += substr_count($contents, $placeholder);
            $contents      = str_replace($placeholder, self::resolveValue($template, $version), $contents);
        }

        if ($contents === $original) {
            return false;
        }

        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Could not write $path");
        }

        echo "  $path → $replacements replacement(s)\n";

        return true;
    }
}
