<?php

declare(strict_types=1);

namespace CWM\BuildTools\Build;

/**
 * Parsed `build:` block of `cwm-build.config.json`.
 *
 * Captures only the fields needed by the lib_cwmscripture-shape build flow
 * (the simplest of the three consumer shapes the consolidation targets — see
 * issue #5). Subsequent PRs add fields for Proclaim's strict-mode filtering,
 * vendor pruning, auto-run pre-build, and interactive version prompting.
 */
final class BuildConfig
{
    /**
     * @param string                                       $outputDir       Absolute or project-relative directory for the built zip.
     * @param string                                       $outputName      Output filename pattern; supports `{version}` substitution.
     * @param string                                       $manifest        Project-relative path to the manifest XML; version is read from its <version> element.
     * @param string|null                                  $scriptFile      Optional install scriptfile, added at zip root if present.
     * @param list<array{from: string, to: string}>        $sources         Source directories with their zip-path prefix.
     * @param list<string>                                 $excludes        Path substrings to skip (str_contains semantics).
     * @param array{mode: string, dirs?: list<string>}|null $preBuild       Optional pre-build gate config.
     */
    public function __construct(
        public readonly string $outputDir,
        public readonly string $outputName,
        public readonly string $manifest,
        public readonly ?string $scriptFile,
        public readonly array $sources,
        public readonly array $excludes,
        public readonly ?array $preBuild,
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

        $rawExcludes = $cfg['excludes'] ?? [];

        if (!is_array($rawExcludes)) {
            throw new \InvalidArgumentException('build.excludes must be an array of strings');
        }

        $excludes = array_values(array_map('strval', $rawExcludes));

        $preBuild = null;

        if (isset($cfg['preBuild'])) {
            if (!is_array($cfg['preBuild']) || empty($cfg['preBuild']['mode'])) {
                throw new \InvalidArgumentException('build.preBuild must be an object with a non-empty `mode`');
            }

            $mode = (string) $cfg['preBuild']['mode'];

            if ($mode !== 'ensure-minified') {
                throw new \InvalidArgumentException(
                    "build.preBuild.mode must be 'ensure-minified' (got '$mode'). " .
                    "Other modes (e.g. 'run', 'gate') are reserved for future PRs."
                );
            }

            $dirs = $cfg['preBuild']['dirs'] ?? [];

            if (!is_array($dirs)) {
                throw new \InvalidArgumentException('build.preBuild.dirs must be an array of strings');
            }

            $preBuild = [
                'mode' => $mode,
                'dirs' => array_values(array_map('strval', $dirs)),
            ];
        }

        return new self(
            outputDir:  (string) $cfg['outputDir'],
            outputName: (string) $cfg['outputName'],
            manifest:   (string) $cfg['manifest'],
            scriptFile: isset($cfg['scriptFile']) && is_string($cfg['scriptFile']) ? $cfg['scriptFile'] : null,
            sources:    $sources,
            excludes:   $excludes,
            preBuild:   $preBuild,
        );
    }
}
