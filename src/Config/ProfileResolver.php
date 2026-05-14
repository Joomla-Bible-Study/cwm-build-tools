<?php

declare(strict_types=1);

namespace CWM\BuildTools\Config;

/**
 * Resolves a consumer's effective `versionTracking` block from a named
 * profile + optional inline overrides.
 *
 * Background: each consumer of cwm-build-tools sits in one of three
 * archetypes — Joomla component, standalone library, or package wrapper —
 * and each archetype has a near-identical versionTracking shape (which
 * files to sync, which paths to scan for __DEPLOY_VERSION__). Authoring
 * that block by hand in every consumer meant every shape change required
 * touching every consumer's cwm-build.config.json. Profiles centralise the
 * shape here; consumers declare `"profile": "<archetype>"` and only spell
 * out the per-repo deltas under their own `versionTracking` key.
 *
 * Resolution order:
 *   1. Load profile defaults from templates/profiles/<name>.json
 *   2. Deep-merge consumer's inline `versionTracking` block on top
 *   3. Return null when neither layer contributed anything (opt-out)
 */
final class ProfileResolver
{
    /** Profile names shipped under templates/profiles/. */
    private const KNOWN = ['component', 'library', 'package-wrapper'];

    /**
     * Resolve the effective versionTracking block: profile defaults
     * (if any) deep-merged with the consumer's overrides.
     *
     * Returns null when there's no profile AND no inline block — callers
     * treat that the same as "opted out of version tracking".
     *
     * @param  array<string, mixed> $config    The full cwm-build.config.json contents.
     * @param  string|null          $toolsRoot Override path to cwm-build-tools root
     *                                         (used by tests against fixture profiles).
     * @return array<string, mixed>|null       Resolved versionTracking block, or null.
     */
    public static function resolve(array $config, ?string $toolsRoot = null): ?array
    {
        $profileName = $config['profile'] ?? null;
        $override    = $config['versionTracking'] ?? null;

        $baseTracking = $profileName !== null
            ? self::loadProfileTracking((string) $profileName, $toolsRoot)
            : [];

        $overrideTracking = is_array($override) ? $override : [];

        if ($baseTracking === [] && $overrideTracking === []) {
            return null;
        }

        return self::deepMerge($baseTracking, $overrideTracking);
    }

    /**
     * Suggest a profile name from extension type. Returns null when the
     * type doesn't map cleanly — cwm-init prompts in that case.
     *
     * `package` and `file` both map to `package-wrapper`: both ship as a
     * wrapper around sub-extensions and don't own a primary source tree
     * of their own. Plugins and modules typically ship as sub-extensions
     * inside a package wrapper rather than standalone — they have no
     * default profile, but a consumer can opt in to `library` if they
     * ship one independently.
     *
     * @param array<string, mixed> $config Full cwm-build.config.json.
     */
    public static function detect(array $config): ?string
    {
        $type = $config['extension']['type'] ?? null;

        return match ($type) {
            'component'        => 'component',
            'library'          => 'library',
            'package', 'file'  => 'package-wrapper',
            default            => null,
        };
    }

    /** @return list<string> */
    public static function known(): array
    {
        return self::KNOWN;
    }

    /**
     * Load the `versionTracking` block from a named profile template.
     *
     * @return array<string, mixed>
     */
    private static function loadProfileTracking(string $name, ?string $toolsRoot): array
    {
        if (!in_array($name, self::KNOWN, true)) {
            throw new \InvalidArgumentException(
                "Unknown profile '{$name}'. Known profiles: " . implode(', ', self::KNOWN)
            );
        }

        $root = $toolsRoot ?? \dirname(__DIR__, 2);
        $path = $root . '/templates/profiles/' . $name . '.json';

        if (!is_file($path)) {
            throw new \RuntimeException("Profile template missing: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data)) {
            throw new \RuntimeException("Profile template is not valid JSON: {$path}");
        }

        $tracking = $data['versionTracking'] ?? [];

        return is_array($tracking) ? $tracking : [];
    }

    /**
     * Deep-merge two arrays. Assoc arrays merge recursively; numerically-
     * indexed lists replace wholesale so a consumer's `paths: [...]` fully
     * overrides the profile's defaults instead of accumulating duplicates.
     *
     * @param  array<int|string, mixed> $base
     * @param  array<int|string, mixed> $override
     * @return array<int|string, mixed>
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !array_is_list($value)
                && !array_is_list($base[$key])
            ) {
                $base[$key] = self::deepMerge($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
