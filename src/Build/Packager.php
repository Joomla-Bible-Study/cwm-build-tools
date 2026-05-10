<?php

declare(strict_types=1);

namespace CWM\BuildTools\Build;

use ZipArchive;

/**
 * Assembles a multi-extension Joomla package zip from a {@see PackageConfig}.
 *
 * For every entry in `PackageConfig::includes[]`, resolves a single child zip
 * (by running an inline build, shelling out to a sub-script, globbing for a
 * pre-built artifact, or building the project's own `build:` block) and
 * stages it under a unique scratch dir. Then assembles the outer zip with
 * the package manifest, optional installer scriptfile, optional language
 * files, and the staged child zips at either outer-zip root or under
 * `packages/` (per `innerLayout`).
 *
 * Differences from the legacy proclaim_build.php / CWMScriptureLinks
 * build-package.php scripts:
 *   - No shell-driven `rm -rf` calls — cleanup is native PHP, with
 *     `is_link()` guards to prevent symlink-escape from the project tree
 *     (per CLAUDE.md).
 *   - No string-form shell calls. Sub-build invocations use `proc_open`
 *     array form; STDOUT/STDERR are inherited so the user sees live progress.
 */
final class Packager
{
    /**
     * @param PackageConfig    $config       The package: block.
     * @param BuildConfig|null $parentBuild  Required only when `includes[]` contains a `self` entry — used to invoke {@see PackageBuilder} on the project's own `build:` block.
     * @param string           $projectRoot  Project root (CWD when invoked from `cwm-package`).
     * @param bool             $verbose      Print per-file additions to the outer zip.
     */
    public function __construct(
        private readonly PackageConfig $config,
        private readonly ?BuildConfig $parentBuild,
        private readonly string $projectRoot,
        private readonly bool $verbose = false,
    ) {
        if (!is_dir($this->projectRoot)) {
            throw new \InvalidArgumentException("Project root does not exist: $this->projectRoot");
        }
    }

    /**
     * Run the full assembly flow.
     *
     * @param  string|null $versionOverride  When non-null, used in place of the manifest's <version>.
     * @return string                        Absolute path to the assembled outer zip.
     */
    public function package(?string $versionOverride = null): string
    {
        $manifestPath = $this->resolve($this->config->manifest);

        if (!is_file($manifestPath)) {
            throw new \RuntimeException("Package manifest not found: $manifestPath");
        }

        $reader  = new ManifestReader($manifestPath);
        $version = $versionOverride ?? $reader->version();

        $outputDir = $this->resolve($this->config->outputDir);

        if (!is_dir($outputDir) && !mkdir($outputDir, 0o777, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Could not create output directory: $outputDir");
        }

        $outputName = str_replace('{version}', $version, $this->config->outputName);
        $outputPath = $outputDir . '/' . $outputName;

        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        echo "Assembling " . basename($outputPath) . " (v$version)\n";

        $stagingDir = $this->createStagingDir();

        try {
            $stagedChildren = $this->resolveIncludes($stagingDir, $version);
            $this->writeOuterZip($outputPath, $manifestPath, $stagedChildren);

            if ($this->config->verify !== null) {
                $this->verifyOutputZip($outputPath, $this->config->verify);
            }
        } finally {
            $this->removeDirectory($stagingDir);
        }

        $sizeKb = (int) round((int) filesize($outputPath) / 1024);
        echo "\nPackage assembled: $outputPath\n";
        echo "  Children: " . count($this->config->includes) . "\n";
        echo "  Size:     {$sizeKb} KB\n";

        return $outputPath;
    }

    /**
     * Resolve every `includes[]` entry into an absolute path to a staged zip.
     *
     * `$outerVersion` is threaded into `self` includes so the inner build
     * uses the same version as the outer wrapper — Proclaim's `pkg_proclaim`
     * shape, where the package and the main extension share one version
     * cadence. `inline`, `subBuild`, and `prebuilt` includes are version-
     * independent (they have their own manifest, their own dist glob, or
     * their own pre-built artifact respectively) and are NOT threaded.
     *
     * @return list<array{outputName: string, path: string}>
     */
    private function resolveIncludes(string $stagingDir, string $outerVersion): array
    {
        $resolved = [];

        foreach ($this->config->includes as $i => $include) {
            $stagedPath = $stagingDir . '/' . $include['outputName'];

            switch ($include['type']) {
                case PackageConfig::TYPE_SELF:
                    $stagedPath = $this->resolveSelf($include, $stagedPath, $outerVersion);
                    break;

                case PackageConfig::TYPE_SUB_BUILD:
                    $stagedPath = $this->resolveSubBuild($include, $stagedPath, $i);
                    break;

                case PackageConfig::TYPE_PREBUILT:
                    $stagedPath = $this->resolvePrebuilt($include, $stagedPath, $i);
                    break;

                case PackageConfig::TYPE_INLINE:
                    $stagedPath = $this->resolveInline($include, $stagedPath);
                    break;

                default:
                    throw new \LogicException("Unhandled include type '{$include['type']}'");
            }

            $resolved[] = ['outputName' => $include['outputName'], 'path' => $stagedPath];
        }

        return $resolved;
    }

    /**
     * Build the project's own `build:` block via PackageBuilder.
     *
     * The outer package version is threaded down so the `self` zip matches
     * the wrapper version — without this, the inner extension reads its
     * own manifest's `<version>` and could end up at a different version
     * than the wrapper if the manifests have drifted (e.g. between
     * `cwm-bump` runs).
     *
     * @param array<string, mixed> $include
     */
    private function resolveSelf(array $include, string $stagedPath, string $outerVersion): string
    {
        if ($this->parentBuild === null) {
            throw new \RuntimeException(
                "package.includes contains a 'self' entry, but the project's `build:` block isn't loaded. " .
                "Define the `build:` block in cwm-build.config.json so cwm-package can invoke PackageBuilder."
            );
        }

        echo "  -> building self ({$include['outputName']}) at v$outerVersion\n";

        $builder    = new PackageBuilder($this->parentBuild, $this->projectRoot, $this->verbose);
        $producedAt = $builder->build($outerVersion);

        $this->copy($producedAt, $stagedPath);

        return $stagedPath;
    }

    /**
     * Shell out to a sub-extension's existing build script and pick up the produced zip.
     *
     * @param array<string, mixed> $include
     */
    private function resolveSubBuild(array $include, string $stagedPath, int $i): string
    {
        $subDir = $this->resolve($include['path']);

        if (!is_dir($subDir)) {
            throw new \RuntimeException("package.includes[$i] (subBuild): path not found: $subDir");
        }

        $buildScriptAbs = $subDir . '/' . ltrim($include['buildScript'], '/');

        if (!is_file($buildScriptAbs)) {
            throw new \RuntimeException("package.includes[$i] (subBuild): buildScript not found: $buildScriptAbs");
        }

        echo "  -> sub-build {$include['outputName']} (in {$include['path']})\n";

        // Array-form proc_open: no shell, no metachar interpretation. Inherit
        // STDOUT/STDERR so the user sees the sub-build's progress live.
        $cmd = array_merge(['php', $buildScriptAbs], $include['args']);

        // Open fresh stdio handles instead of using the predefined STDIN/STDOUT
        // /STDERR constants — those constants are file resources, and another
        // test closing the parent's STDIN under PHPUnit's random-order runs
        // makes them invalid for subsequent uses.
        $proc = proc_open(
            $cmd,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', 'php://stdout', 'w'],
                2 => ['file', 'php://stderr', 'w'],
            ],
            $pipes,
            $subDir
        );

        if (!is_resource($proc)) {
            throw new \RuntimeException("package.includes[$i] (subBuild): could not spawn php for $buildScriptAbs");
        }

        $exit = proc_close($proc);

        if ($exit !== 0) {
            throw new \RuntimeException("package.includes[$i] (subBuild): $buildScriptAbs exited with $exit");
        }

        $globPath = $subDir . '/' . ltrim($include['distGlob'], '/');
        $matches  = glob($globPath) ?: [];

        if ($matches === []) {
            throw new \RuntimeException(
                "package.includes[$i] (subBuild): no files matched distGlob '{$include['distGlob']}' (under {$include['path']})"
            );
        }

        // If multiple, pick the most-recently modified (favors the just-produced one).
        if (count($matches) > 1) {
            usort($matches, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        }

        $this->copy($matches[0], $stagedPath);

        return $stagedPath;
    }

    /**
     * Glob for an already-built zip on disk and stage it.
     *
     * @param array<string, mixed> $include
     */
    private function resolvePrebuilt(array $include, string $stagedPath, int $i): string
    {
        $globPath = $this->resolve($include['distGlob']);
        $matches  = glob($globPath) ?: [];

        if ($matches === []) {
            throw new \RuntimeException(
                "package.includes[$i] (prebuilt): no files matched distGlob '{$include['distGlob']}'. " .
                "Build the sub-extension first (e.g. via its own build script or cwm-build), " .
                "then re-run cwm-package."
            );
        }

        if (count($matches) > 1) {
            usort($matches, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        }

        echo "  -> prebuilt {$include['outputName']} (from " . basename($matches[0]) . ")\n";

        $this->copy($matches[0], $stagedPath);

        return $stagedPath;
    }

    /**
     * Build a sub-extension in-process from a nested BuildConfig.
     *
     * @param array<string, mixed> $include
     */
    private function resolveInline(array $include, string $stagedPath): string
    {
        echo "  -> inline build {$include['outputName']}\n";

        $childConfig = BuildConfig::fromArray($include['config']);
        $builder     = new PackageBuilder($childConfig, $this->projectRoot, $this->verbose);
        $producedAt  = $builder->build();

        $this->copy($producedAt, $stagedPath);

        return $stagedPath;
    }

    /**
     * Write the outer zip — manifest, optional installer, language files,
     * and the staged child zips at the configured layout.
     *
     * @param list<array{outputName: string, path: string}> $stagedChildren
     */
    private function writeOuterZip(string $outputPath, string $manifestPath, array $stagedChildren): void
    {
        $zip = new ZipArchive();

        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create outer zip: $outputPath");
        }

        $manifestEntry = basename($this->config->manifest);
        $zip->addFile($manifestPath, $manifestEntry);
        $this->log("  + $manifestEntry");

        if ($this->config->installer !== null) {
            $installerAbs = $this->resolve($this->config->installer);

            if (is_file($installerAbs)) {
                $entry = basename($this->config->installer);
                $zip->addFile($installerAbs, $entry);
                $this->log("  + $entry");
            }
        }

        foreach ($this->config->languageFiles as $lang) {
            $fromAbs = $this->resolve($lang['from']);

            if (!is_file($fromAbs)) {
                throw new \RuntimeException("package.languageFiles: source not found: {$lang['from']}");
            }

            $zip->addFile($fromAbs, $lang['to']);
            $this->log("  + {$lang['to']}");
        }

        $prefix = $this->config->innerLayout === PackageConfig::LAYOUT_PACKAGES_PREFIX ? 'packages/' : '';

        foreach ($stagedChildren as $child) {
            $entry = $prefix . $child['outputName'];
            $zip->addFile($child['path'], $entry);
            $this->log("  + $entry");
        }

        $zip->close();
    }

    /**
     * Re-open the assembled zip and assert each `expectedEntries[]` is present.
     *
     * @param array{expectedEntries?: list<string>} $verify
     */
    private function verifyOutputZip(string $outputPath, array $verify): void
    {
        $expected = $verify['expectedEntries'] ?? [];

        if ($expected === []) {
            return;
        }

        $zip = new ZipArchive();

        if ($zip->open($outputPath) !== true) {
            throw new \RuntimeException("Could not re-open outer zip for verify: $outputPath");
        }

        $missing = [];

        foreach ($expected as $entry) {
            if ($zip->locateName($entry) === false) {
                $missing[] = $entry;
            }
        }

        $zip->close();

        if ($missing !== []) {
            throw new \RuntimeException(
                "package.verify failed - missing entries:\n  - " . implode("\n  - ", $missing)
            );
        }

        echo "  Verify: " . count($expected) . " expected entries present.\n";
    }

    /**
     * Resolve a project-relative path against the project root.
     */
    private function resolve(string $path): string
    {
        if ($path === '') {
            return $this->projectRoot;
        }

        if ($path[0] === '/' || (strlen($path) > 1 && $path[1] === ':')) {
            return $path;
        }

        return rtrim($this->projectRoot, '/') . '/' . $path;
    }

    /**
     * Copy a file, creating the destination's parent directory if needed.
     */
    private function copy(string $from, string $to): void
    {
        $dir = dirname($to);

        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create staging dir: $dir");
        }

        if (!@copy($from, $to)) {
            throw new \RuntimeException("Could not copy '$from' to '$to'");
        }
    }

    private function createStagingDir(): string
    {
        $base = sys_get_temp_dir() . '/cwm-package-' . uniqid('', true);

        if (!mkdir($base, 0o700, true) && !is_dir($base)) {
            throw new \RuntimeException("Could not create staging dir: $base");
        }

        return $base;
    }

    /**
     * Recursively remove a directory, with `is_link()` guards so a symlink
     * inside the staging dir gets unlinked rather than recursed-through.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }

        $entries = scandir($dir) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_link($path) || is_file($path)) {
                @unlink($path);
                continue;
            }

            $this->removeDirectory($path);
        }

        @rmdir($dir);
    }

    private function log(string $line): void
    {
        if ($this->verbose) {
            echo $line . "\n";
        }
    }
}
