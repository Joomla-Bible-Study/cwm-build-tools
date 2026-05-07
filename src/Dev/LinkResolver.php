<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * Resolves the symlink list for a project against a given Joomla install.
 *
 * Two layers:
 *
 *   1. Auto-derive — for each entry in `manifests.extensions[]` plus the
 *      top-level `extension` block, generate the conventional Joomla layout
 *      mappings (admin/site/media for components, libraries/<name> +
 *      manifests/libraries for libraries, plugins/<group>/<element> for
 *      plugins, modules/[admin]/<name> for modules).
 *
 *   2. Explicit — `dev.links[]` and `dev.internalLinks[]` from the project's
 *      cwm-build.config.json. Explicit entries are always emitted; the
 *      consumer can disable auto-derive via `dev.deriveLinks: false` for
 *      complex layouts (Proclaim, etc.).
 *
 * Targets in `dev.links[]` may use the `{joomlaPath}` placeholder.
 *
 * @phpstan-type LinkPair array{source: string, target: string}
 */
final class LinkResolver
{
    public function __construct(
        private readonly string $projectRoot,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {
    }

    /**
     * Internal symlinks (within the project repo).
     *
     * @return list<LinkPair>
     */
    public function internalLinks(): array
    {
        $out = [];

        foreach ($this->config['dev']['internalLinks'] ?? [] as $entry) {
            $source = $this->resolveProject((string) ($entry['source'] ?? ''));
            $link   = $this->resolveProject((string) ($entry['link'] ?? $entry['target'] ?? ''));

            if ($source === '' || $link === '') {
                continue;
            }

            $out[] = ['source' => $source, 'target' => $link];
        }

        return $out;
    }

    /**
     * External symlinks (project files → Joomla install).
     *
     * @return list<LinkPair>
     */
    public function externalLinks(string $joomlaPath): array
    {
        $out = [];

        if (($this->config['dev']['deriveLinks'] ?? true) !== false) {
            foreach ($this->autoDerive($joomlaPath) as $pair) {
                $out[] = $pair;
            }
        }

        foreach ($this->config['dev']['links'] ?? [] as $entry) {
            $sourceRaw = (string) ($entry['source'] ?? '');
            $targetRaw = (string) ($entry['target'] ?? '');

            if ($sourceRaw === '' || $targetRaw === '') {
                continue;
            }

            $source = $this->resolveProject($sourceRaw);
            $target = strtr($targetRaw, ['{joomlaPath}' => rtrim($joomlaPath, '/')]);
            $out[]  = ['source' => $source, 'target' => $target];
        }

        return $this->dedupe($out);
    }

    /**
     * @return iterable<LinkPair>
     */
    private function autoDerive(string $joomlaPath): iterable
    {
        $joomlaPath = rtrim($joomlaPath, '/');

        // Top-level extension (the package's "headline" extension, e.g. the component).
        $extension = $this->config['extension'] ?? null;

        if (is_array($extension)) {
            yield from $this->deriveFromTopLevel($extension, $joomlaPath);
        }

        foreach ($this->config['manifests']['extensions'] ?? [] as $manifest) {
            $type     = (string) ($manifest['type'] ?? '');
            $manPath  = (string) ($manifest['path'] ?? '');
            $manFull  = $this->resolveProject($manPath);

            if ($manFull === '' || !is_file($manFull)) {
                continue;
            }

            yield from match ($type) {
                'library'   => $this->deriveLibrary($manFull, $joomlaPath),
                'plugin'    => $this->derivePlugin($manFull, $joomlaPath),
                'module'    => $this->deriveModule($manFull, $joomlaPath),
                'component' => $this->deriveComponent($manFull, $joomlaPath, $extension),
                default     => [],
            };
        }
    }

    /**
     * Components don't usually appear under manifests.extensions[] (the
     * top-level `extension` block describes them). Drive component derivation
     * from there.
     *
     * @param  array<string, mixed>  $extension
     * @return iterable<LinkPair>
     */
    private function deriveFromTopLevel(array $extension, string $joomlaPath): iterable
    {
        if (($extension['type'] ?? null) !== 'component') {
            return;
        }

        $name = (string) ($extension['name'] ?? '');

        if ($name === '' || !str_starts_with($name, 'com_')) {
            return;
        }

        $admin = $this->resolveProject('admin');
        $site  = $this->resolveProject('site');
        $media = $this->resolveProject('media');

        if (is_dir($admin)) {
            yield ['source' => $admin, 'target' => "{$joomlaPath}/administrator/components/{$name}"];
        }

        if (is_dir($site)) {
            yield ['source' => $site, 'target' => "{$joomlaPath}/components/{$name}"];
        }

        if (is_dir($media)) {
            yield ['source' => $media, 'target' => "{$joomlaPath}/media/{$name}"];
        }
    }

    /**
     * @return iterable<LinkPair>
     */
    private function deriveComponent(string $manifestPath, string $joomlaPath, ?array $extension): iterable
    {
        // Only handle the case where a component manifest is listed under
        // manifests.extensions[]. Most projects keep it on extension.* and we
        // already covered that in deriveFromTopLevel.
        if ($extension !== null && ($extension['type'] ?? null) === 'component') {
            return;
        }

        $xml = $this->loadManifest($manifestPath);

        if ($xml === null) {
            return;
        }

        $name = strtolower(trim((string) $xml->name));

        if ($name === '' || !str_starts_with($name, 'com_')) {
            return;
        }

        $base = \dirname($manifestPath);

        foreach (['admin' => "/administrator/components/{$name}",
                  'site'  => "/components/{$name}",
                  'media' => "/media/{$name}"] as $sub => $tail) {
            $src = $base . '/' . $sub;

            if (is_dir($src)) {
                yield ['source' => $src, 'target' => $joomlaPath . $tail];
            }
        }
    }

    /**
     * @return iterable<LinkPair>
     */
    private function deriveLibrary(string $manifestPath, string $joomlaPath): iterable
    {
        $xml = $this->loadManifest($manifestPath);

        if ($xml === null) {
            return;
        }

        $libraryName = trim((string) ($xml->libraryname ?? ''));
        $name        = trim((string) $xml->name);

        if ($libraryName === '' && $name !== '') {
            $libraryName = preg_replace('/^lib_/', '', strtolower($name));
        }

        if ($libraryName === '') {
            return;
        }

        $libDir = \dirname($manifestPath);

        // libraries/lib_X → joomla/libraries/X
        yield ['source' => $libDir, 'target' => "{$joomlaPath}/libraries/{$libraryName}"];

        // The library manifest itself is also expected under
        // administrator/manifests/libraries/<name>.xml
        yield [
            'source' => $manifestPath,
            'target' => "{$joomlaPath}/administrator/manifests/libraries/{$libraryName}.xml",
        ];

        // media/lib_X (if present alongside the library)
        $mediaSrc = $libDir . '/media/lib_' . $libraryName;

        if (is_dir($mediaSrc)) {
            yield ['source' => $mediaSrc, 'target' => "{$joomlaPath}/media/lib_{$libraryName}"];
        }
    }

    /**
     * @return iterable<LinkPair>
     */
    private function derivePlugin(string $manifestPath, string $joomlaPath): iterable
    {
        $xml = $this->loadManifest($manifestPath);

        if ($xml === null) {
            return;
        }

        $group = trim((string) ($xml['group'] ?? ''));

        if ($group === '') {
            return;
        }

        // Element comes from the manifest filename (e.g. proclaim.xml → proclaim).
        $element = pathinfo($manifestPath, PATHINFO_FILENAME);

        if ($element === '') {
            return;
        }

        $pluginDir = \dirname($manifestPath);
        yield ['source' => $pluginDir, 'target' => "{$joomlaPath}/plugins/{$group}/{$element}"];
    }

    /**
     * @return iterable<LinkPair>
     */
    private function deriveModule(string $manifestPath, string $joomlaPath): iterable
    {
        $xml = $this->loadManifest($manifestPath);

        if ($xml === null) {
            return;
        }

        $client = trim((string) ($xml['client'] ?? 'site'));
        $name   = strtolower(trim((string) $xml->name));

        if ($name === '' || !str_starts_with($name, 'mod_')) {
            return;
        }

        $modDir = \dirname($manifestPath);
        $tail   = $client === 'administrator'
            ? "/administrator/modules/{$name}"
            : "/modules/{$name}";

        yield ['source' => $modDir, 'target' => $joomlaPath . $tail];
    }

    private function loadManifest(string $path): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_file($path);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml === false ? null : $xml;
    }

    private function resolveProject(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if ($path[0] === '/') {
            return $path;
        }

        return rtrim($this->projectRoot, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param  list<LinkPair>  $links
     * @return list<LinkPair>
     */
    private function dedupe(array $links): array
    {
        $seen = [];
        $out  = [];

        foreach ($links as $pair) {
            $key = $pair['target'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[]      = $pair;
        }

        return $out;
    }
}
