<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * Reads and writes the per-developer build.properties file.
 *
 * The canonical format is INI with one section per Joomla install:
 *
 *   ; Comma-separated list of install ids
 *   installs = j5, j6
 *
 *   [j5]
 *   path = /path/to/joomla5
 *   url = https://j5-dev.local:8890
 *   version = 5.4.2
 *   db_host = localhost
 *   db_user =
 *   db_pass =
 *   db_name =
 *   admin_user = admin
 *   admin_pass = admin
 *   admin_email = admin@example.com
 *
 * Legacy fall-back (Proclaim's flat key=value layout) is also recognised:
 *   builder.joomla_paths=/path/to/j5,/path/to/j6
 *   builder.j5dev.url=...
 *   builder.j5dev.db_host=...
 * The reader maps Proclaim's `j5dev` / `j6dev` ids to `j5` / `j6` and merges
 * `builder.joomla_paths` entries into the matching sections by position.
 */
final class PropertiesReader
{
    public function __construct(private readonly string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return list<InstallConfig>
     */
    public function installs(): array
    {
        if (!$this->exists()) {
            throw new \RuntimeException(
                "build.properties not found at {$this->path}. Run 'composer setup' first."
            );
        }

        $raw = parse_ini_file($this->path, true, INI_SCANNER_RAW);

        if ($raw === false) {
            throw new \RuntimeException("Could not parse {$this->path} as INI.");
        }

        // Detect format: INI-sections (preferred) vs legacy flat Proclaim style.
        $hasSections = false;

        foreach ($raw as $value) {
            if (is_array($value)) {
                $hasSections = true;

                break;
            }
        }

        return $hasSections
            ? $this->fromSections($raw)
            : $this->fromLegacyFlat($raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<InstallConfig>
     */
    private function fromSections(array $raw): array
    {
        $idsRaw = (string) ($raw['installs'] ?? '');
        $ids    = array_filter(array_map('trim', explode(',', $idsRaw)));

        if ($ids === []) {
            // No explicit list — treat every section as an install.
            $ids = array_keys(array_filter($raw, 'is_array'));
        }

        $installs = [];

        foreach ($ids as $id) {
            $section = $raw[$id] ?? null;

            if (!is_array($section)) {
                continue;
            }

            $installs[] = new InstallConfig(
                id:      $id,
                path:    rtrim((string) ($section['path'] ?? ''), '/'),
                url:     ($section['url'] ?? '') === '' ? null : (string) $section['url'],
                version: ($section['version'] ?? '') === '' ? null : (string) $section['version'],
                db:      [
                    'host' => (string) ($section['db_host'] ?? 'localhost'),
                    'user' => (string) ($section['db_user'] ?? ''),
                    'pass' => (string) ($section['db_pass'] ?? ''),
                    'name' => (string) ($section['db_name'] ?? ''),
                ],
                admin:   [
                    'user'  => (string) ($section['admin_user'] ?? 'admin'),
                    'pass'  => (string) ($section['admin_pass'] ?? 'admin'),
                    'email' => (string) ($section['admin_email'] ?? 'admin@example.com'),
                ],
            );
        }

        return $installs;
    }

    /**
     * Compatibility path for Proclaim's `builder.joomla_paths=` + `builder.<id>.<key>=` layout.
     *
     * @param  array<string, mixed>  $raw
     * @return list<InstallConfig>
     */
    private function fromLegacyFlat(array $raw): array
    {
        $pathsRaw = (string) ($raw['builder.joomla_paths']
            ?? $raw['builder.joomla_path']
            ?? '');
        $dir   = trim((string) ($raw['builder.joomla_dir'] ?? ''), '/');
        $paths = array_values(array_filter(array_map('trim', explode(',', $pathsRaw))));

        if ($paths === []) {
            return [];
        }

        // Discover ids by scanning for `builder.<id>.url` keys.
        $ids = [];

        foreach ($raw as $key => $_) {
            if (preg_match('/^builder\.([^.]+)\.url$/', (string) $key, $m)) {
                $ids[] = $m[1];
            }
        }

        $ids = array_values(array_unique($ids));

        // Default Proclaim ids if discovery turned up nothing.
        if ($ids === []) {
            $ids = ['j5dev', 'j6dev'];
        }

        $defaultVersion = (string) ($raw['joomla.version'] ?? '');
        $installs       = [];

        foreach ($paths as $i => $rawPath) {
            $id   = $ids[$i] ?? "j{$i}";
            $path = rtrim($rawPath, '/');

            if ($dir !== '') {
                $path .= '/' . $dir;
            }

            $prefix = "builder.{$id}";

            $installs[] = new InstallConfig(
                id:      $this->normaliseLegacyId($id),
                path:    $path,
                url:     ($raw["{$prefix}.url"] ?? '') === '' ? null : (string) $raw["{$prefix}.url"],
                version: $defaultVersion === '' ? null : $defaultVersion,
                db:      [
                    'host' => (string) ($raw["{$prefix}.db_host"] ?? 'localhost'),
                    'user' => (string) ($raw["{$prefix}.db_user"] ?? ''),
                    'pass' => (string) ($raw["{$prefix}.db_pass"] ?? ''),
                    'name' => (string) ($raw["{$prefix}.db_name"] ?? ''),
                ],
                admin:   [
                    'user'  => (string) ($raw["{$prefix}.username"] ?? 'admin'),
                    'pass'  => (string) ($raw["{$prefix}.password"] ?? 'admin'),
                    'email' => (string) ($raw["{$prefix}.email"] ?? 'admin@example.com'),
                ],
            );
        }

        return $installs;
    }

    /**
     * Map Proclaim's `j5dev` → `j5`. Leaves modern ids untouched.
     */
    private function normaliseLegacyId(string $id): string
    {
        return preg_replace('/dev$/', '', $id) ?? $id;
    }

    /**
     * Write a fresh build.properties from a list of installs. Existing comments
     * are not preserved — callers that want to keep a hand-edited file should
     * write in place instead. Used by the setup wizard.
     *
     * @param  list<InstallConfig>  $installs
     */
    public function write(array $installs): void
    {
        $ids   = array_map(static fn (InstallConfig $i): string => $i->id, $installs);
        $lines = [
            '; build.properties — local Joomla installs for cwm-build-tools dev commands.',
            '; Gitignored. Per-developer. Generated by `composer setup`.',
            '',
            '; Comma-separated list of install ids (must match the section names below).',
            'installs = ' . implode(', ', $ids),
            '',
        ];

        foreach ($installs as $install) {
            $lines[] = "[{$install->id}]";
            $lines[] = 'path = ' . $install->path;
            $lines[] = 'url = ' . ($install->url ?? '');
            $lines[] = 'version = ' . ($install->version ?? '');
            $lines[] = 'db_host = ' . $install->dbHost();
            $lines[] = 'db_user = ' . $install->dbUser();
            $lines[] = 'db_pass = ' . $install->dbPass();
            $lines[] = 'db_name = ' . $install->dbName();
            $lines[] = 'admin_user = ' . $install->adminUser();
            $lines[] = 'admin_pass = ' . $install->adminPass();
            $lines[] = 'admin_email = ' . $install->adminEmail();
            $lines[] = '';
        }

        file_put_contents($this->path, implode("\n", $lines));
    }
}
