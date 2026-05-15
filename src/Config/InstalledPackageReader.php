<?php

declare(strict_types=1);

namespace CWM\BuildTools\Config;

/**
 * Reads `vendor/composer/installed.json` and yields the subset of installed
 * packages that have declared a Joomla footprint via
 * `extra.cwm-build-tools.joomlaLinks` in their own composer.json.
 *
 * Why parse installed.json directly rather than use
 * `\Composer\InstalledVersions`: the runtime API strips the `extra` block
 * from its accessors (`getAllRawData()` returns a reduced schema). Laravel's
 * `Illuminate\Foundation\PackageManifest::build()` follows the same
 * direct-parse pattern for its `extra.laravel` discovery, and that's the
 * model copied here.
 *
 * Composer 1 stored installed.json as a flat top-level list; Composer 2
 * wraps it as `{"packages": [...]}`. Both shapes are handled.
 */
final class InstalledPackageReader
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    public static function fromCwd(): self
    {
        return new self((string) getcwd());
    }

    /**
     * Every installed package whose composer.json declared
     * `extra.cwm-build-tools.joomlaLinks` — never null, may be empty.
     *
     * Packages without the extra block are silently skipped. Malformed
     * `joomlaLinks` entries throw with the offending package name so the
     * author of that package gets a clear error in their consumer's logs.
     *
     * @return list<CwmPackage>
     */
    public function cwmPackages(): array
    {
        $installedPath = $this->projectRoot . '/vendor/composer/installed.json';

        if (!is_file($installedPath)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents($installedPath), true);

        if (!is_array($raw)) {
            throw new \RuntimeException("installed.json is not valid JSON: {$installedPath}");
        }

        $packages = $raw['packages'] ?? $raw;

        if (!is_array($packages)) {
            return [];
        }

        $result = [];

        foreach ($packages as $pkg) {
            if (!is_array($pkg) || !isset($pkg['name'])) {
                continue;
            }

            $joomlaLinks = $pkg['extra']['cwm-build-tools']['joomlaLinks'] ?? null;

            if ($joomlaLinks === null) {
                continue;
            }

            $result[] = $this->buildPackage($pkg, $joomlaLinks);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed> $pkg
     * @param  mixed                $joomlaLinks
     */
    private function buildPackage(array $pkg, mixed $joomlaLinks): CwmPackage
    {
        $name = (string) $pkg['name'];

        if (!is_array($joomlaLinks) || !array_is_list($joomlaLinks)) {
            throw new \RuntimeException(
                "Package '{$name}': extra.cwm-build-tools.joomlaLinks must be a JSON array, got "
                . get_debug_type($joomlaLinks)
            );
        }

        $validated = [];

        foreach ($joomlaLinks as $index => $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException(
                    "Package '{$name}': joomlaLinks[{$index}] must be an object"
                );
            }

            $validated[] = $this->validateLink($name, $index, $entry);
        }

        $installPath = $this->resolveInstallPath($pkg);
        $isPathRepo  = ($pkg['dist']['type'] ?? null) === 'path';
        $sourcePath  = $isPathRepo ? $this->resolveSourcePath($pkg) : null;

        return new CwmPackage(
            name: $name,
            version: (string) ($pkg['version'] ?? ''),
            versionNormalized: (string) ($pkg['version_normalized'] ?? ''),
            joomlaLinks: $validated,
            installPath: $installPath,
            isPathRepo: $isPathRepo,
            sourcePath: $sourcePath,
            reference: $pkg['dist']['reference'] ?? $pkg['source']['reference'] ?? null,
        );
    }

    /**
     * Validate one joomlaLinks entry and return it as a normalised assoc array.
     *
     * @param  array<string, mixed> $entry
     * @return array<string, string>
     */
    private function validateLink(string $package, int $index, array $entry): array
    {
        $type = $entry['type'] ?? null;

        if (!is_string($type) || $type === '') {
            throw new \RuntimeException(
                "Package '{$package}': joomlaLinks[{$index}].type is required"
            );
        }

        $known = ['library', 'plugin', 'module', 'component'];

        if (!in_array($type, $known, true)) {
            throw new \RuntimeException(
                "Package '{$package}': joomlaLinks[{$index}].type '{$type}' is not one of "
                . implode(', ', $known)
            );
        }

        $required = match ($type) {
            'plugin'              => ['group', 'element'],
            'library', 'module', 'component' => ['name'],
        };

        foreach ($required as $key) {
            if (!isset($entry[$key]) || !is_string($entry[$key]) || $entry[$key] === '') {
                throw new \RuntimeException(
                    "Package '{$package}': joomlaLinks[{$index}] (type {$type}) requires '{$key}'"
                );
            }
        }

        $out = ['type' => $type];

        foreach (['name', 'group', 'element', 'client'] as $key) {
            if (isset($entry[$key]) && is_string($entry[$key]) && $entry[$key] !== '') {
                $out[$key] = $entry[$key];
            }
        }

        if ($type === 'module' && isset($out['client']) && !in_array($out['client'], ['site', 'administrator'], true)) {
            throw new \RuntimeException(
                "Package '{$package}': joomlaLinks[{$index}].client must be 'site' or 'administrator'"
            );
        }

        return $out;
    }

    /**
     * Composer 2 stores `install-path` relative to `vendor/composer/`; Composer 1
     * had no such field. Fall back to vendor/<name>/ when absent.
     *
     * @param array<string, mixed> $pkg
     */
    private function resolveInstallPath(array $pkg): string
    {
        $relativeFromVendorComposer = $pkg['install-path'] ?? null;

        $base = $this->projectRoot . '/vendor/composer';

        if (is_string($relativeFromVendorComposer) && $relativeFromVendorComposer !== '') {
            $resolved = realpath($base . '/' . $relativeFromVendorComposer);

            if ($resolved !== false) {
                return $resolved;
            }

            // Fall through: the canonical path may not exist on disk in tests.
            return $this->normalisePath($base . '/' . $relativeFromVendorComposer);
        }

        $fallback = $this->projectRoot . '/vendor/' . (string) $pkg['name'];

        return realpath($fallback) !== false ? (string) realpath($fallback) : $fallback;
    }

    /**
     * For path-repo installs, `dist.url` is the path declared in the consuming
     * project's `repositories[].url` — always relative to the project root.
     *
     * @param array<string, mixed> $pkg
     */
    private function resolveSourcePath(array $pkg): ?string
    {
        $url = $pkg['dist']['url'] ?? null;

        if (!is_string($url) || $url === '') {
            return null;
        }

        $candidate = $this->isAbsolute($url)
            ? $url
            : $this->projectRoot . '/' . $url;

        $resolved = realpath($candidate);

        return $resolved !== false ? $resolved : $this->normalisePath($candidate);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }

    /**
     * Collapse `./` and `../` segments without touching the filesystem, for
     * paths that don't (yet) exist on disk (test fixtures, vendor dirs that
     * haven't been installed). realpath() returns false for those.
     */
    private function normalisePath(string $path): string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $out   = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                if ($out === [] && $part === '') {
                    $out[] = '';
                }
                continue;
            }

            if ($part === '..') {
                array_pop($out);
                continue;
            }

            $out[] = $part;
        }

        return implode('/', $out);
    }
}
