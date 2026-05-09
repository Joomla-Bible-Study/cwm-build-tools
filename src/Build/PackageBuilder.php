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
 * Phase 1 — covers the lib_cwmscripture build shape: read manifest version,
 * optionally gate on minified asset presence, then walk one or more source
 * directories into the zip with a configurable per-source prefix. Excludes
 * are matched as path substrings (`str_contains`), the loose mode used by
 * lib_cwmscripture and CWMScriptureLinks. Strict-mode (Proclaim's 4-mode
 * exclude + vendor prune + root-ext include) lands in a follow-up PR.
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
        // legacy lib_cwmscripture script, we don't wipe the entire dist dir
        // (that's destructive to unrelated zips); leftover artifacts are
        // gitignored anyway.
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
            $this->addDirectory($zip, $this->resolve($src['from']), $src['to'], $this->config->excludes);
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
     * Run the configured pre-build gate.
     *
     * `ensure-minified` is the only mode currently supported. For each listed
     * directory, every `<name>.<ext>` that isn't already a `.min.<ext>` must
     * have a corresponding `<name>.min.<ext>` sibling, otherwise the build
     * fails with a hint to run the project's build step.
     *
     * @param array{mode: string, dirs?: list<string>} $preBuild
     */
    private function runPreBuild(array $preBuild): void
    {
        if ($preBuild['mode'] !== 'ensure-minified') {
            return;
        }

        $missing = [];

        foreach ($preBuild['dirs'] ?? [] as $relDir) {
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

                // Already a minified version — skip.
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
     *
     * @param list<string> $excludes
     */
    private function addDirectory(ZipArchive $zip, string $sourcePath, string $zipPrefix, array $excludes): void
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

            $filePath     = $file->getRealPath();
            $relativePath = substr($filePath, strlen($sourceReal) + 1);

            if (self::matchesExclude($relativePath, $excludes)) {
                continue;
            }

            $entryPath = $zipPrefix === '' ? $relativePath : rtrim($zipPrefix, '/') . '/' . $relativePath;

            $zip->addFile($filePath, $entryPath);
            $this->log("  + $entryPath");
        }
    }

    /**
     * @param list<string> $excludes
     */
    private static function matchesExclude(string $path, array $excludes): bool
    {
        foreach ($excludes as $pattern) {
            if ($pattern !== '' && str_contains($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function log(string $line): void
    {
        if ($this->verbose) {
            echo $line . "\n";
        }
    }
}
