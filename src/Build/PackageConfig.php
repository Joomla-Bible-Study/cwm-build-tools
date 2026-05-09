<?php

declare(strict_types=1);

namespace CWM\BuildTools\Build;

/**
 * Parsed `package:` block of `cwm-build.config.json`.
 *
 * Drives `cwm-package`'s assembly of a multi-extension Joomla package zip.
 * Each entry in `includes[]` is a discriminated union over four `type`s:
 *
 *   - `self`     — invoke {@see PackageBuilder} against the project's own
 *                  `build:` block, then bundle the result. Proclaim's
 *                  "step 3: build com_proclaim" in process.
 *   - `subBuild` — shell out to a sub-extension's existing build script
 *                  (`php <buildScript> [args]`) within `path`, then glob
 *                  `distGlob` (relative to `path`) for the produced zip.
 *                  Used during transition while sub-extensions still ship
 *                  their own `build/build-package.php` scripts.
 *   - `prebuilt` — assume the zip is already built; glob `distGlob` and
 *                  copy. Helpful when the sub-extension is a submodule
 *                  whose release zip is already on disk.
 *   - `inline`   — a nested `BuildConfig`-shaped block; the Packager runs
 *                  {@see PackageBuilder} on that config and bundles the
 *                  result. CSL's `plg_task_cwmscripture` (a sibling
 *                  directory built in-process) maps to this.
 */
final class PackageConfig
{
    public const LAYOUT_ROOT             = 'root';
    public const LAYOUT_PACKAGES_PREFIX  = 'packages-prefix';

    public const TYPE_SELF      = 'self';
    public const TYPE_SUB_BUILD = 'subBuild';
    public const TYPE_PREBUILT  = 'prebuilt';
    public const TYPE_INLINE    = 'inline';

    /**
     * @param string                                $manifest       Path to the package manifest XML; version is read from <version>.
     * @param string                                $outputDir      Project-relative directory for the assembled outer zip.
     * @param string                                $outputName     Output filename pattern; `{version}` substituted from manifest.
     * @param string                                $innerLayout    Where child zips are placed inside the outer zip — `"root"` (default) or `"packages-prefix"` (under `packages/`).
     * @param string|null                           $installer      Optional install scriptfile path; added at outer-zip root if present.
     * @param list<array{from: string, to: string}> $languageFiles  Language INI files; `from` is project-relative source, `to` is its outer-zip path.
     * @param non-empty-list<array<string, mixed>>  $includes       Discriminated-union entries (see class doc).
     * @param array{expectedEntries?: list<string>}|null $verify    Optional self-verify block.
     */
    public function __construct(
        public readonly string $manifest,
        public readonly string $outputDir,
        public readonly string $outputName,
        public readonly string $innerLayout,
        public readonly ?string $installer,
        public readonly array $languageFiles,
        public readonly array $includes,
        public readonly ?array $verify,
    ) {
    }

    /**
     * @param  array<string, mixed> $cfg
     * @throws \InvalidArgumentException When required fields are missing or malformed.
     */
    public static function fromArray(array $cfg): self
    {
        foreach (['manifest', 'outputDir', 'outputName'] as $required) {
            if (empty($cfg[$required]) || !is_string($cfg[$required])) {
                throw new \InvalidArgumentException("package.$required is required and must be a string");
            }
        }

        $innerLayout = $cfg['innerLayout'] ?? self::LAYOUT_ROOT;

        if (!is_string($innerLayout) || !in_array($innerLayout, [self::LAYOUT_ROOT, self::LAYOUT_PACKAGES_PREFIX], true)) {
            throw new \InvalidArgumentException(
                'package.innerLayout must be "root" or "packages-prefix" (got ' . var_export($innerLayout, true) . ')'
            );
        }

        $installer = isset($cfg['installer']) && is_string($cfg['installer']) ? $cfg['installer'] : null;

        $rawLanguage = $cfg['languageFiles'] ?? [];

        if (!is_array($rawLanguage)) {
            throw new \InvalidArgumentException('package.languageFiles must be an array');
        }

        $languageFiles = [];

        foreach ($rawLanguage as $i => $entry) {
            if (!is_array($entry) || empty($entry['from']) || empty($entry['to'])) {
                throw new \InvalidArgumentException("package.languageFiles[$i] must have non-empty 'from' and 'to' keys");
            }

            $languageFiles[] = ['from' => (string) $entry['from'], 'to' => (string) $entry['to']];
        }

        $rawIncludes = $cfg['includes'] ?? [];

        if (!is_array($rawIncludes) || $rawIncludes === []) {
            throw new \InvalidArgumentException('package.includes is required and must be a non-empty array');
        }

        $includes = [];

        foreach ($rawIncludes as $i => $entry) {
            $includes[] = self::validateInclude($i, $entry);
        }

        $verify = null;

        if (isset($cfg['verify'])) {
            if (!is_array($cfg['verify'])) {
                throw new \InvalidArgumentException('package.verify must be an object');
            }

            $expected = $cfg['verify']['expectedEntries'] ?? [];

            if (!is_array($expected)) {
                throw new \InvalidArgumentException('package.verify.expectedEntries must be an array of strings');
            }

            $verify = [
                'expectedEntries' => array_values(array_map('strval', $expected)),
            ];
        }

        return new self(
            manifest:      (string) $cfg['manifest'],
            outputDir:     (string) $cfg['outputDir'],
            outputName:    (string) $cfg['outputName'],
            innerLayout:   $innerLayout,
            installer:     $installer,
            languageFiles: $languageFiles,
            includes:      $includes,
            verify:        $verify,
        );
    }

    /**
     * Validate one `includes[]` entry by `type` and return the normalized shape.
     *
     * @param  array<string, mixed>|mixed $entry
     * @return array<string, mixed>
     */
    private static function validateInclude(int $i, mixed $entry): array
    {
        if (!is_array($entry) || empty($entry['type'])) {
            throw new \InvalidArgumentException("package.includes[$i] must have a `type`");
        }

        $type = (string) $entry['type'];

        if (empty($entry['outputName']) || !is_string($entry['outputName'])) {
            throw new \InvalidArgumentException("package.includes[$i] requires non-empty 'outputName'");
        }

        $normalized = ['type' => $type, 'outputName' => (string) $entry['outputName']];

        switch ($type) {
            case self::TYPE_SELF:
                // No extra fields. Bundles the result of running `cwm-build`
                // on the project's own `build:` block.
                break;

            case self::TYPE_SUB_BUILD:
                foreach (['path', 'buildScript', 'distGlob'] as $req) {
                    if (empty($entry[$req]) || !is_string($entry[$req])) {
                        throw new \InvalidArgumentException("package.includes[$i] (type subBuild) requires non-empty '$req'");
                    }
                }

                $args = $entry['args'] ?? [];

                if (!is_array($args)) {
                    throw new \InvalidArgumentException("package.includes[$i].args must be an array of strings");
                }

                $normalized['path']        = (string) $entry['path'];
                $normalized['buildScript'] = (string) $entry['buildScript'];
                $normalized['distGlob']    = (string) $entry['distGlob'];
                $normalized['args']        = array_values(array_map('strval', $args));
                break;

            case self::TYPE_PREBUILT:
                if (empty($entry['distGlob']) || !is_string($entry['distGlob'])) {
                    throw new \InvalidArgumentException("package.includes[$i] (type prebuilt) requires non-empty 'distGlob'");
                }

                $normalized['distGlob'] = (string) $entry['distGlob'];
                break;

            case self::TYPE_INLINE:
                if (empty($entry['config']) || !is_array($entry['config'])) {
                    throw new \InvalidArgumentException("package.includes[$i] (type inline) requires a 'config' object");
                }

                // Validate by running BuildConfig::fromArray — surfaces nested errors here.
                BuildConfig::fromArray($entry['config']);
                $normalized['config'] = $entry['config'];
                break;

            default:
                throw new \InvalidArgumentException(
                    "package.includes[$i].type must be one of: self, subBuild, prebuilt, inline (got '$type')"
                );
        }

        return $normalized;
    }
}
