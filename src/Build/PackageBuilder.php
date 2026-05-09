<?php

declare(strict_types=1);

namespace CWM\BuildTools\Build;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Builds an installable extension zip per a {@see BuildConfig}.
 *
 * Supports both the lib_cwmscripture-shape build flow (loose `str_contains`
 * excludes, optional `ensure-minified` pre-build gate, multiple sources with
 * per-source zip prefix) and the Proclaim-shape flow (strict 4-mode exclude
 * matching, vendor pruning, include-roots filter, root-extension allowlist,
 * auto-run pre-build via `passthru`).
 *
 * Path semantics:
 *   - `BuildConfig::manifest` and `BuildConfig::scriptFile` are
 *     project-relative; their `basename()` becomes the in-zip path.
 *   - `BuildConfig::sources[i].from` is project-relative; every file under
 *     it gets prefixed with `sources[i].to` inside the zip.
 *   - `BuildConfig::outputDir` is project-relative.
 *   - `outputName` accepts a literal `{version}` token — substituted with
 *     the value read from the manifest (or `versionOverride` argument).
 */
final class PackageBuilder
{
    /** Files dropped from any `vendor/` subtree when vendorPrune is on. */
    private const VENDOR_PRUNE_DOC_NAMES = [
        'README', 'CHANGELOG', 'BACKERS', 'AUTHORS', 'CONTRIBUTING', 'UPGRADE', 'SECURITY', 'LICENSE', 'COPYING',
    ];

    public function __construct(
        private readonly BuildConfig $config,
        private readonly string $projectRoot,
        private readonly bool $verbose = false,
    ) {
        if (!is_dir($this->projectRoot)) {
            throw new \InvalidArgumentException("Project root does not exist: $this->projectRoot");
        }
    }

    /**
     * Run the full build flow.
     *
     * @param  string|null $versionOverride  When non-null, used in place of the manifest's <version>. Useful for ad-hoc rebuilds.
     * @return string                        Absolute path to the resulting zip.
     */
    public function build(?string $versionOverride = null): string
    {
        $manifestPath = $this->resolve($this->config->manifest);

        if (!is_file($manifestPath)) {
            throw new \RuntimeException("Manifest not found: $manifestPath");
        }

        $reader  = new ManifestReader($manifestPath);
        $version = $versionOverride ?? $reader->version();

        if ($this->config->preBuild !== null) {
            $this->runPreBuild($this->config->preBuild);
        }

        $outputDir = $this->resolve($this->config->outputDir);

        if (!is_dir($outputDir) && !mkdir($outputDir, 0o777, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Could not create output directory: $outputDir");
        }

        $outputName = str_replace('{version}', $version, $this->config->outputName);
        $outputPath = $outputDir . '/' . $outputName;

        // Replace any prior build artifact at this exact path. Unlike the
        // legacy lib_cwmscripture/proclaim_build scripts, we don't wipe the
        // entire dist dir (that's destructive to unrelated zips); leftover
        // artifacts are gitignored anyway.
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        echo "Building " . basename($outputPath) . " (v$version)\n";

        $zip = new ZipArchive();

        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create zip: $outputPath");
        }

        $manifestEntry = basename($this->config->manifest);
        $zip->addFile($manifestPath, $manifestEntry);
        $this->log("  + $manifestEntry");

        if ($this->config->scriptFile !== null) {
            $scriptAbs = $this->resolve($this->config->scriptFile);

            if (is_file($scriptAbs)) {
                $scriptEntry = basename($this->config->scriptFile);
                $zip->addFile($scriptAbs, $scriptEntry);
                $this->log("  + $scriptEntry");
            }
        }

        foreach ($this->config->sources as $src) {
            $this->addDirectory($zip, $this->resolve($src['from']), $src['to']);
        }

        $fileCount = $zip->numFiles;
        $zip->close();

        $sizeKb = (int) round((int) filesize($outputPath) / 1024);
        echo "\nPackage built: $outputPath\n";
        echo "  Files: $fileCount\n";
        echo "  Size:  {$sizeKb} KB\n";

        return $outputPath;
    }

    /**
     * Resolve a project-relative path against the project root.
     */
    private function resolve(string $path): string
    {
        if ($path === '') {
            return $this->projectRoot;
        }

        // Already absolute — pass through.
        if ($path[0] === '/' || (strlen($path) > 1 && $path[1] === ':')) {
            return $path;
        }

        return rtrim($this->projectRoot, '/') . '/' . $path;
    }

    /**
     * Run the configured pre-build hook.
     *
     * Modes:
     *   - `ensure-minified` — gate on presence of `*.min.{ext}` siblings.
     *   - `run` — passthru a shell command (Proclaim's auto `npm run build`).
     *
     * @param array{mode: string, dirs?: list<string>, command?: string} $preBuild
     */
    private function runPreBuild(array $preBuild): void
    {
        if ($preBuild['mode'] === 'ensure-minified') {
            $this->ensureMinifiedAssets($preBuild['dirs'] ?? []);

            return;
        }

        if ($preBuild['mode'] === 'run') {
            $command = (string) ($preBuild['command'] ?? '');

            // BuildConfig validates this, but defense in depth.
            if (trim($command) === '') {
                throw new \RuntimeException('preBuild.mode "run" requires a non-empty command');
            }

            // The command may use shell features (&&, env vars, redirects) — passthru
            // gives the user live progress output. Per CLAUDE.md the build config is
            // trusted (committed by the project author), so the shell semantics are OK.
            echo "Running pre-build: $command\n";

            $exitCode = 0;
            passthru($command, $exitCode);

            if ($exitCode !== 0) {
                throw new \RuntimeException("Pre-build command failed with exit $exitCode");
            }

            echo "\n";
        }
    }

    /**
     * For each listed dir, every `<name>.<ext>` that isn't already a `.min.<ext>`
     * must have a corresponding `<name>.min.<ext>` sibling.
     *
     * @param list<string> $dirs
     */
    private function ensureMinifiedAssets(array $dirs): void
    {
        $missing = [];

        foreach ($dirs as $relDir) {
            $absDir = $this->resolve($relDir);

            if (!is_dir($absDir)) {
                continue;
            }

            $entries = scandir($absDir) ?: [];

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || !is_file($absDir . '/' . $entry)) {
                    continue;
                }

                if (!preg_match('/\.([a-z0-9]+)$/i', $entry, $m)) {
                    continue;
                }

                $ext = $m[1];

                if (str_ends_with($entry, '.min.' . $ext)) {
                    continue;
                }

                $minEntry = substr($entry, 0, -strlen('.' . $ext)) . '.min.' . $ext;

                if (!file_exists($absDir . '/' . $minEntry)) {
                    $missing[] = $relDir . '/' . $minEntry;
                }
            }
        }

        if ($missing !== []) {
            fwrite(STDERR, "Pre-build gate failed: missing minified assets:\n");

            foreach ($missing as $m) {
                fwrite(STDERR, "  - $m\n");
            }

            fwrite(STDERR, "\nRun the project's build step (typically `npm run build`) before packaging.\n");
            exit(1);
        }
    }

    /**
     * Walk a source directory and add its files to the zip under $zipPrefix.
     */
    private function addDirectory(ZipArchive $zip, string $sourcePath, string $zipPrefix): void
    {
        if (!is_dir($sourcePath)) {
            echo "  SKIP: $sourcePath (not found)\n";

            return;
        }

        $sourceReal = realpath($sourcePath);

        if ($sourceReal === false) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filePath = $file->getRealPath();
            // Normalize separators to forward-slash so cross-platform
            // patterns and globs match on Windows too.
            $relativePath = str_replace('\\', '/', substr($filePath, strlen($sourceReal) + 1));

            if (!$this->shouldInclude($relativePath)) {
                continue;
            }

            $entryPath = $zipPrefix === '' ? $relativePath : rtrim($zipPrefix, '/') . '/' . $relativePath;

            $zip->addFile($filePath, $entryPath);
            $this->log("  + $entryPath");
        }
    }

    /**
     * Decide whether a file (by its source-relative path) lands in the zip.
     *
     * Excludes are checked first, then includes (when configured). When
     * `includeRoots` and `includeRootExtensions` are both empty (the default
     * lib_cwmscripture shape), everything not explicitly excluded is included.
     */
    private function shouldInclude(string $relativePath): bool
    {
        if ($this->matchesExcludes($relativePath)) {
            return false;
        }

        if ($this->config->includeRoots === [] && $this->config->includeRootExtensions === []) {
            return true;
        }

        return $this->matchesIncludes($relativePath);
    }

    /**
     * Apply every configured exclusion rule against the relative path.
     *
     * Order: excludeMatchMode list → excludeExtensions → excludePaths globs →
     * vendorPrune. Any match short-circuits to `true`.
     */
    private function matchesExcludes(string $relativePath): bool
    {
        foreach ($this->config->excludes as $pattern) {
            if ($pattern === '') {
                continue;
            }

            if ($this->config->excludeMatchMode === BuildConfig::MATCH_STRICT) {
                if (self::matchesStrict($relativePath, $pattern)) {
                    return true;
                }
            } elseif (str_contains($relativePath, $pattern)) {
                return true;
            }
        }

        if ($this->config->excludeExtensions !== []) {
            $ext = pathinfo($relativePath, PATHINFO_EXTENSION);

            if ($ext !== '' && in_array($ext, $this->config->excludeExtensions, true)) {
                return true;
            }
        }

        foreach ($this->config->excludePaths as $glob) {
            if ($glob !== '' && fnmatch($glob, $relativePath)) {
                return true;
            }
        }

        if ($this->config->vendorPrune && str_contains($relativePath, '/vendor/')) {
            $basename = basename($relativePath);

            if ($basename === 'installed.json' || $basename === 'installed.php') {
                return true;
            }

            $upper = strtoupper(pathinfo($basename, PATHINFO_FILENAME));

            if (in_array($upper, self::VENDOR_PRUNE_DOC_NAMES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strict 4-mode pattern match: exact, prefix-with-slash, contained-with-slashes,
     * suffix-after-slash. Matches Proclaim's `proclaim_build.php` semantics.
     */
    private static function matchesStrict(string $path, string $pattern): bool
    {
        $clean = rtrim($pattern, '/');

        if ($clean === '') {
            return false;
        }

        if ($path === $clean) {
            return true;
        }

        if (str_starts_with($path, $clean . '/')) {
            return true;
        }

        if (str_contains($path, '/' . $clean . '/')) {
            return true;
        }

        return str_ends_with($path, '/' . $clean);
    }

    /**
     * Apply the include filter: at least one of (a) the path starts with a
     * configured root prefix, or (b) the file is at the source root and has
     * an extension on the root-extensions allowlist.
     */
    private function matchesIncludes(string $relativePath): bool
    {
        foreach ($this->config->includeRoots as $root) {
            if ($root !== '' && str_starts_with($relativePath, $root)) {
                return true;
            }
        }

        if ($this->config->includeRootExtensions === []) {
            return false;
        }

        // Root-level files only — those with no '/' in the source-relative path.
        if (str_contains($relativePath, '/')) {
            return false;
        }

        $ext = pathinfo($relativePath, PATHINFO_EXTENSION);

        return $ext !== '' && in_array($ext, $this->config->includeRootExtensions, true);
    }

    private function log(string $line): void
    {
        if ($this->verbose) {
            echo $line . "\n";
        }
    }
}
