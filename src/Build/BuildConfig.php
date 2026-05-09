<?php

declare(strict_types=1);

namespace CWM\BuildTools\Build;

/**
 * Parsed `build:` block of `cwm-build.config.json`.
 *
 * Supports both the lib_cwmscripture-shape build flow (loose `str_contains`
 * excludes, optional `ensure-minified` pre-build gate) and the Proclaim-shape
 * flow (strict 4-mode exclude matching, vendor pruning, auto-run pre-build,
 * include-roots filter, root-extension allowlist).
 *
 * Defaults preserve PR B's lib_cwmscripture shape; opt into Proclaim-shape
 * features via `excludeMatchMode: "strict"`, `vendorPrune: true`,
 * `includeRoots`, `includeRootExtensions`, `excludeExtensions`, `excludePaths`,
 * and `preBuild.mode: "run"`.
 */
final class BuildConfig
{
    public const MATCH_CONTAINS = 'contains';
    public const MATCH_STRICT   = 'strict';

    /**
     * @param string                                $outputDir              Absolute or project-relative directory for the built zip.
     * @param string                                $outputName             Output filename pattern; supports `{version}` substitution.
     * @param string                                $manifest               Project-relative path to the manifest XML; version is read from its <version> element.
     * @param string|null                           $scriptFile             Optional install scriptfile, added at zip root if present.
     * @param list<array{from: string, to: string}> $sources                Source directories with their zip-path prefix.
     * @param list<string>                          $excludes               Path patterns to skip; matched per `excludeMatchMode`.
     * @param string                                $excludeMatchMode       `"contains"` (default — PR B/lib_cwmscripture) or `"strict"` (4-mode — Proclaim).
     * @param list<string>                          $excludeExtensions      Bare extensions (no leading dot) to drop, e.g. `["map"]`.
     * @param list<string>                          $excludePaths           fnmatch glob patterns matched against the relative path, e.g. `["media/backup/*.sql"]`.
     * @param bool                                  $vendorPrune            When true, drop Composer metadata + doc/license files inside any `vendor/` subtree.
     * @param list<string>                          $includeRoots           When set, only files starting with one of these prefixes are included (subdirectory allowlist).
     * @param list<string>                          $includeRootExtensions  When set with `includeRoots`, allows root-level files with these extensions through the include filter.
     * @param array{mode: string, dirs?: list<string>, command?: string}|null $preBuild Optional pre-build hook.
     * @param array{enabled: bool, timeout: int}|null $versionPrompt Optional 3-way version prompt (manifest / date-stamped / custom). Only fires when interactive AND no `--version` override is given.
     */
    public function __construct(
        public readonly string $outputDir,
        public readonly string $outputName,
        public readonly string $manifest,
        public readonly ?string $scriptFile,
        public readonly array $sources,
        public readonly array $excludes,
        public readonly ?array $preBuild,
        public readonly string $excludeMatchMode = self::MATCH_CONTAINS,
        public readonly array $excludeExtensions = [],
        public readonly array $excludePaths = [],
        public readonly bool $vendorPrune = false,
        public readonly array $includeRoots = [],
        public readonly array $includeRootExtensions = [],
        public readonly ?array $versionPrompt = null,
    ) {
    }

    /**
     * Build a BuildConfig from the raw `build:` block of cwm-build.config.json.
     *
     * @param  array<string, mixed> $cfg
     * @throws \InvalidArgumentException When required fields are missing or malformed.
     */
    public static function fromArray(array $cfg): self
    {
        foreach (['outputDir', 'outputName', 'manifest'] as $required) {
            if (empty($cfg[$required]) || !is_string($cfg[$required])) {
                throw new \InvalidArgumentException("build.$required is required and must be a string");
            }
        }

        $rawSources = $cfg['sources'] ?? [];

        if (!is_array($rawSources) || $rawSources === []) {
            throw new \InvalidArgumentException('build.sources is required and must be a non-empty array');
        }

        $sources = [];

        foreach ($rawSources as $i => $src) {
            if (!is_array($src) || empty($src['from']) || !isset($src['to'])) {
                throw new \InvalidArgumentException("build.sources[$i] must have non-empty 'from' and 'to' keys");
            }

            $sources[] = ['from' => (string) $src['from'], 'to' => (string) $src['to']];
        }

        $excludes              = self::stringList($cfg, 'excludes');
        $excludeExtensions     = array_map(static fn (string $e): string => ltrim($e, '.'), self::stringList($cfg, 'excludeExtensions'));
        $excludePaths          = self::stringList($cfg, 'excludePaths');
        $includeRoots          = self::stringList($cfg, 'includeRoots');
        $includeRootExtensions = array_map(static fn (string $e): string => ltrim($e, '.'), self::stringList($cfg, 'includeRootExtensions'));

        $matchMode = $cfg['excludeMatchMode'] ?? self::MATCH_CONTAINS;

        if (!is_string($matchMode) || !in_array($matchMode, [self::MATCH_CONTAINS, self::MATCH_STRICT], true)) {
            throw new \InvalidArgumentException(
                'build.excludeMatchMode must be "contains" or "strict" (got ' . var_export($matchMode, true) . ')'
            );
        }

        $vendorPrune = isset($cfg['vendorPrune']) ? (bool) $cfg['vendorPrune'] : false;

        $preBuild = null;

        if (isset($cfg['preBuild'])) {
            if (!is_array($cfg['preBuild']) || empty($cfg['preBuild']['mode'])) {
                throw new \InvalidArgumentException('build.preBuild must be an object with a non-empty `mode`');
            }

            $mode = (string) $cfg['preBuild']['mode'];

            if (!in_array($mode, ['ensure-minified', 'run'], true)) {
                throw new \InvalidArgumentException(
                    "build.preBuild.mode must be one of: ensure-minified, run (got '$mode')"
                );
            }

            $preBuild = ['mode' => $mode];

            if ($mode === 'ensure-minified') {
                $dirs = $cfg['preBuild']['dirs'] ?? [];

                if (!is_array($dirs)) {
                    throw new \InvalidArgumentException('build.preBuild.dirs must be an array of strings');
                }

                $preBuild['dirs'] = array_values(array_map('strval', $dirs));
            } elseif ($mode === 'run') {
                $command = $cfg['preBuild']['command'] ?? '';

                if (!is_string($command) || trim($command) === '') {
                    throw new \InvalidArgumentException('build.preBuild.command is required when mode is "run"');
                }

                $preBuild['command'] = $command;
            }
        }

        $versionPrompt = null;

        if (isset($cfg['versionPrompt'])) {
            if (!is_array($cfg['versionPrompt'])) {
                throw new \InvalidArgumentException('build.versionPrompt must be an object');
            }

            $enabled = isset($cfg['versionPrompt']['enabled']) ? (bool) $cfg['versionPrompt']['enabled'] : false;
            $timeout = isset($cfg['versionPrompt']['timeout']) ? (int) $cfg['versionPrompt']['timeout'] : 10;

            if ($timeout < 0) {
                throw new \InvalidArgumentException('build.versionPrompt.timeout must be a non-negative integer');
            }

            $versionPrompt = ['enabled' => $enabled, 'timeout' => $timeout];
        }

        return new self(
            outputDir:             (string) $cfg['outputDir'],
            outputName:            (string) $cfg['outputName'],
            manifest:              (string) $cfg['manifest'],
            scriptFile:            isset($cfg['scriptFile']) && is_string($cfg['scriptFile']) ? $cfg['scriptFile'] : null,
            sources:               $sources,
            excludes:              $excludes,
            preBuild:              $preBuild,
            excludeMatchMode:      $matchMode,
            excludeExtensions:     $excludeExtensions,
            excludePaths:          $excludePaths,
            vendorPrune:           $vendorPrune,
            includeRoots:          $includeRoots,
            includeRootExtensions: $includeRootExtensions,
            versionPrompt:         $versionPrompt,
        );
    }

    /**
     * Pull a list-of-strings field, defaulting to []; validates the type.
     *
     * @param  array<string, mixed> $cfg
     * @return list<string>
     */
    private static function stringList(array $cfg, string $key): array
    {
        $raw = $cfg[$key] ?? [];

        if (!is_array($raw)) {
            throw new \InvalidArgumentException("build.$key must be an array of strings");
        }

        return array_values(array_map('strval', $raw));
    }
}
