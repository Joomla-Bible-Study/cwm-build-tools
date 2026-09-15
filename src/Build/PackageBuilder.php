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
    /**
     * Seconds a built file may lag its source before it counts as stale.
     *
     * Small on purpose. The gap this catches is a forgotten build, which is
     * minutes at the very least; a larger window would start excusing exactly
     * the case of editing a source and packaging without rebuilding. This only
     * absorbs filesystem timestamp granularity and same-moment writes.
     */
    private const MTIME_TOLERANCE = 2;

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

        // Optional 3-way version prompt — only fires when interactive AND no
        // explicit `--version` override was given. Mirrors Proclaim's existing
        // doBuild() prompt so a developer running `cwm-build` ad-hoc can pick
        // a date-stamped pre-release version without touching the manifest.
        if ($versionOverride === null
            && $this->config->versionPrompt !== null
            && ($this->config->versionPrompt['enabled'] ?? false)
            && !Prompt::isNonInteractive()
        ) {
            $version = $this->promptForVersion($version, (int) $this->config->versionPrompt['timeout']);
        }

        if ($this->config->preBuild !== null) {
            $this->runPreBuild($this->config->preBuild);
        }

        if ($this->config->verifyAssets) {
            $this->verifyAssetReferences();
        }

        if ($this->config->verifyMediaSources !== []) {
            $this->verifyMediaSourceParity();

            if ($this->config->verifyMediaFreshness) {
                $this->verifyMediaFreshness();
            }
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
        ZipEntry::add($zip, $manifestPath, $manifestEntry);
        $this->log("  + $manifestEntry");

        if ($this->config->scriptFile !== null) {
            $scriptAbs = $this->resolve($this->config->scriptFile);

            if (is_file($scriptAbs)) {
                $scriptEntry = basename($this->config->scriptFile);
                ZipEntry::add($zip, $scriptAbs, $scriptEntry);
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
        // Only check primary web-asset extensions. Other files in the same
        // directory (e.g. `*.min.js.map` source maps, `*.min.js.gz` rollup
        // gzips, `joomla.asset.json`) are not "primary assets that need a
        // minified sibling" — they're derived from the minified version, or
        // configuration metadata. Earlier versions of this gate iterated
        // every extension and produced spurious failures like
        // `foo.min.js.map → expected foo.min.js.min.map`.
        $primaryExtensions = ['js', 'css'];

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

                $ext = strtolower($m[1]);

                if (!in_array($ext, $primaryExtensions, true)) {
                    continue;
                }

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
     * Fail the build if any file referenced by a `joomla.asset.json` is absent
     * from the (post-pre-build) source tree.
     *
     * This is the safety net for the silent-skip failure mode: if an asset's
     * source (e.g. a `*.es6.mjs` module) didn't build — because the JS build
     * was skipped, or an older build toolchain ignored the suffix — the asset
     * file never appears, `joomla.asset.json` 404s at runtime, and any JS that
     * relies on it breaks for end users. Catching it here turns a silent
     * runtime breakage into a loud build failure.
     *
     * Resolution is by basename anywhere under the asset manifest's own media
     * directory (a script `uri` like `com_proclaim/foo.min.js` is served from a
     * media root that doesn't mirror the repo layout — the repo file is
     * `media/js/foo.min.js`). Protocol-relative / absolute URLs (CDN assets)
     * are skipped. Only `joomla.asset.json` files that would actually ship
     * (per the package's own include/exclude rules) are checked.
     */
    private function verifyAssetReferences(): void
    {
        $missing = [];

        foreach ($this->config->sources as $src) {
            $sourceReal = realpath($this->resolve($src['from']));

            if ($sourceReal === false || !is_dir($sourceReal)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getFilename() !== 'joomla.asset.json') {
                    continue;
                }

                $manifestReal = (string) $file->getRealPath();
                $relativePath = str_replace('\\', '/', substr($manifestReal, strlen($sourceReal) + 1));

                if (!$this->shouldInclude($relativePath)) {
                    continue;
                }

                foreach ($this->missingAssetFiles($manifestReal) as $entry) {
                    $missing[] = $entry;
                }
            }
        }

        if ($missing !== []) {
            fwrite(STDERR, "Asset verification failed — files referenced by joomla.asset.json are missing:\n");

            foreach ($missing as $m) {
                fwrite(STDERR, "  - $m\n");
            }

            fwrite(
                STDERR,
                "\nThese assets were not produced by the build. Common cause: the JS/CSS build did not run,\n"
                . "or the build toolchain is too old to compile a source (e.g. a *.es6.mjs ES module needs\n"
                . "cwm/build-tools >= 1.5). Run the project's build (typically `npm run build`) with an\n"
                . "up-to-date toolchain before packaging.\n"
            );
            exit(1);
        }
    }

    /**
     * Fail when a built media file has no source to have been built from.
     *
     * `verifyAssets` catches the opposite problem — an asset the manifest
     * references that the build never produced. This catches output that outlives
     * its source, which is invisible for as long as nothing loads it.
     *
     * It has happened: lib_cwmscripture shipped
     * `media/lib_cwmscripture/js/translations-manager.min.js` (plus `.gz` and
     * `.map`) in every release for months after `translations-manager.es6.js` was
     * replaced by `bible-translations.es6.js`. Minified output is gitignored, so no
     * checkout, branch switch or pull ever removed it, and the packager ships
     * whatever sits in `media/`. The published v1.1.6 asset had 90 files where a
     * fresh build of the same tag had 87 — the release artifact was a function of
     * the source *plus that machine's build history*.
     *
     * Nothing referenced those files, so there was no error to notice. Re-publishing
     * a corrected artifact later is not free either: it invalidates the checksums
     * the update server recorded at publish time.
     *
     * Only the top level of each output directory is checked. Subdirectories are
     * usually third-party payloads (a copied vendor library) whose layout has no
     * relationship to `media_source`, and flagging those would train people to
     * disable the check.
     *
     * @throws \RuntimeException When an orphaned build artifact is found.
     */
    private function verifyMediaSourceParity(): void
    {
        $orphans = [];

        foreach ($this->config->verifyMediaSources as $pair) {
            $outputDir = $this->resolve($pair['output']);
            $sourceDir = $this->resolve($pair['source']);

            if (!is_dir($outputDir)) {
                continue;
            }

            if (!is_dir($sourceDir)) {
                throw new \RuntimeException(
                    "build.verifyMediaSources: source directory not found: {$pair['source']}"
                );
            }

            $sourceBases = [];

            foreach ((array) scandir($sourceDir) as $entry) {
                if (!is_file($sourceDir . '/' . $entry)) {
                    continue;
                }

                $sourceBases[self::sourceBaseName($entry)] = true;
            }

            foreach ((array) scandir($outputDir) as $entry) {
                if (!is_file($outputDir . '/' . $entry)) {
                    continue;
                }

                $base = self::outputBaseName($entry);

                // null = not a build product (joomla.asset.json, index.html, a
                // licence file). Those are shipped as-is and have no source.
                if ($base === null || isset($sourceBases[$base])) {
                    continue;
                }

                $orphans[] = $pair['output'] . '/' . $entry;
            }
        }

        if ($orphans === []) {
            return;
        }

        throw new \RuntimeException(
            "Media verification failed — built files with no corresponding source:\n  - "
            . implode("\n  - ", $orphans)
            . "\n\nTheir source was removed or renamed, but the build output survived because it is\n"
            . "gitignored. Packaging them makes the release artifact depend on this machine's build\n"
            . "history rather than on the source tree. Delete them (`git clean -Xfd <media dir>`)\n"
            . "and rebuild, or add the source back if the removal was a mistake."
        );
    }

    /**
     * Base name of a source file: `foo.es6.js` -> `foo`, `foo.scss` -> `foo`.
     */
    private static function sourceBaseName(string $filename): string
    {
        $base = preg_replace('/\.[^.]+$/', '', $filename) ?? $filename;

        // Strip the ES-module marker so foo.es6.js and foo.js agree on `foo`.
        return preg_replace('/\.(es6|esm)$/i', '', $base) ?? $base;
    }

    /**
     * Refuse to package a built file older than the source it was built from.
     *
     * {@see verifyMediaSourceParity()} catches output whose source is *gone*.
     * This catches output whose source *moved on*: `foo.es6.js` is edited,
     * `foo.min.js` is never rebuilt, and the zip ships last week's behaviour
     * under this week's version number. Nothing 404s and nothing references the
     * wrong file, so there is no symptom until someone reports a bug the source
     * tree says was fixed.
     *
     * ⚠️ Timestamps are the check *here* because they cannot be the check
     * later. Zip entry mtimes are normalised when the archive is written, so by
     * the time an artifact exists the evidence is already gone. That is why
     * this runs against the working tree rather than over the built zip.
     *
     * ⚠️ Assumes build output is generated, not committed. A checkout writes
     * every tracked file at roughly the same moment, so in a project that
     * commits its minified output, source and output are separated by the order
     * git happened to write them and not by staleness. Where the output is
     * gitignored — every CWM project — a fresh clone has nothing here to
     * compare until a build has produced it, and the check is meaningful.
     *
     * Only the top level of each output directory is walked, matching the
     * parity check: subdirectories are third-party payloads with no
     * `media_source` counterpart.
     *
     * @throws \RuntimeException When a built file is older than its source.
     */
    private function verifyMediaFreshness(): void
    {
        $stale = [];

        foreach ($this->config->verifyMediaSources as $pair) {
            $outputDir = $this->resolve($pair['output']);
            $sourceDir = $this->resolve($pair['source']);

            // A missing source dir is verifyMediaSourceParity()'s error to
            // raise, and it has already run by the time we get here.
            if (!is_dir($outputDir) || !is_dir($sourceDir)) {
                continue;
            }

            // Newest source per base name. `foo.es6.js` and `foo.scss` both
            // feed `foo`, and a rebuild is owed if either of them has moved.
            $sourceTimes = [];

            foreach ((array) scandir($sourceDir) as $entry) {
                $path = $sourceDir . '/' . $entry;

                if (!is_file($path)) {
                    continue;
                }

                $base = self::sourceBaseName($entry);
                $time = (int) filemtime($path);

                if (!isset($sourceTimes[$base]) || $time > $sourceTimes[$base]['time']) {
                    $sourceTimes[$base] = ['time' => $time, 'file' => $entry];
                }
            }

            foreach ((array) scandir($outputDir) as $entry) {
                $path = $outputDir . '/' . $entry;

                if (!is_file($path)) {
                    continue;
                }

                $base = self::outputBaseName($entry);

                // null = not a build product. An unmatched base = an orphan,
                // which the parity check reports; do not report it twice.
                if ($base === null || !isset($sourceTimes[$base])) {
                    continue;
                }

                $sourceTime = $sourceTimes[$base]['time'];
                $outputTime = (int) filemtime($path);

                if ($outputTime + self::MTIME_TOLERANCE >= $sourceTime) {
                    continue;
                }

                $stale[] = sprintf(
                    '%s/%s — %s older than %s/%s',
                    $pair['output'],
                    $entry,
                    self::describeGap($sourceTime - $outputTime),
                    $pair['source'],
                    $sourceTimes[$base]['file']
                );
            }
        }

        if ($stale === []) {
            return;
        }

        throw new \RuntimeException(
            "Media freshness check failed — built files older than their source:\n  - "
            . implode("\n  - ", $stale)
            . "\n\nThe source was edited and the build never re-ran, so packaging now ships the\n"
            . "previous build's behaviour under this version number. Run the project's asset\n"
            . "build and package again.\n\n"
            . "If a build genuinely did run, check that it writes the files listed above —\n"
            . "a build step that silently skips a target leaves exactly this trace."
        );
    }

    /**
     * Describe an age gap in the largest unit that still reads honestly.
     */
    private static function describeGap(int $seconds): string
    {
        foreach ([86400 => 'day', 3600 => 'hour', 60 => 'minute'] as $unit => $label) {
            if ($seconds >= $unit) {
                $count = intdiv($seconds, $unit);

                return $count . ' ' . $label . ($count === 1 ? '' : 's');
            }
        }

        return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    }

    /**
     * Base name of a build product, or null when the file is not one.
     *
     * `foo.min.js.gz` / `foo.min.js.map` / `foo.min.js` / `foo.js` -> `foo`
     */
    private static function outputBaseName(string $filename): ?string
    {
        $name = preg_replace('/\.gz$/i', '', $filename) ?? $filename;
        $name = preg_replace('/\.map$/i', '', $name) ?? $name;

        if (!preg_match('/\.(js|mjs|css)$/i', $name)) {
            return null;
        }

        $name = preg_replace('/\.[^.]+$/', '', $name) ?? $name;

        return preg_replace('/\.min$/i', '', $name) ?? $name;
    }

    /**
     * Return the "<asset name> -> <uri>" labels for every script/style asset in
     * a joomla.asset.json whose referenced file is absent from the manifest's
     * media directory.
     *
     * @return list<string>
     */
    private function missingAssetFiles(string $manifestPath): array
    {
        $data = json_decode((string) file_get_contents($manifestPath), true);

        if (!is_array($data) || !isset($data['assets']) || !is_array($data['assets'])) {
            return [];
        }

        $mediaDir = \dirname($manifestPath);
        $missing  = [];

        foreach ($data['assets'] as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $type = $asset['type'] ?? '';
            $uri  = $asset['uri']  ?? '';

            if (!in_array($type, ['script', 'style'], true) || !is_string($uri) || $uri === '') {
                continue;
            }

            // CDN / absolute / protocol-relative URLs are not local files.
            if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $uri) === 1) {
                continue;
            }

            if (!$this->basenameExistsUnder($mediaDir, basename($uri))) {
                $name      = is_string($asset['name'] ?? null) ? $asset['name'] : '(unnamed)';
                $relMani   = str_replace('\\', '/', substr($manifestPath, strlen(rtrim($this->projectRoot, '/')) + 1));
                $missing[] = "$name -> $uri  (in $relMani)";
            }
        }

        return $missing;
    }

    /**
     * True if a file named $basename exists anywhere under $dir.
     */
    private function basenameExistsUnder(string $dir, string $basename): bool
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $basename) {
                return true;
            }
        }

        return false;
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

            ZipEntry::add($zip, $filePath, $entryPath);
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

    /**
     * 3-way prompt: keep manifest version, use a date-stamped pre-release,
     * or enter a custom value. Mirrors Proclaim's existing doBuild() prompt.
     *
     * Falls back to $manifestVersion on any unrecognized choice or empty
     * custom input.
     */
    private function promptForVersion(string $manifestVersion, int $timeout): string
    {
        $dateStamped = $manifestVersion . '.' . date('Ymd');

        echo "\nVersion options:\n";
        echo "  [1] Use manifest version $manifestVersion\n";
        echo "  [2] Use date-stamped pre-release $dateStamped\n";
        echo "  [3] Enter a custom version\n";

        $choice = Prompt::ask('Choice', '1', $timeout);

        switch ($choice) {
            case '2':
                return $dateStamped;

            case '3':
                $custom = Prompt::ask('Custom version', null, 0);

                if ($custom === null || trim($custom) === '') {
                    echo "  (empty input — falling back to manifest version)\n";

                    return $manifestVersion;
                }

                return trim($custom);

            case '1':
            default:
                return $manifestVersion;
        }
    }
}
