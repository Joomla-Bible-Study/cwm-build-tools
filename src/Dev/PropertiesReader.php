<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * Reads and writes the per-developer build.properties file.
 *
 * The canonical format is INI with one section per Joomla install. Each
 * install carries a `role` of either `dev` (the symlink-style working
 * install where `cwm-link` deploys; this is the default) or `test` (the
 * artifact-style install where `cwm-install-zip` deploys a built zip and
 * `cwm-verify --target test` queries `#__extensions`).
 *
 *   ; Comma-separated list of install ids
 *   installs = j5, j5-test, j6
 *
 *   [j5]
 *   role = dev
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
 *   [j5-test]
 *   role = test
 *   path = /path/to/joomla5-test
 *   ; ... same fields as above, pointing at a separate Joomla install
 *
 * Legacy fall-back (Proclaim's flat key=value layout) is also recognised
 * and treated as all-dev:
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

        $raw = self::parseProperties((string) file_get_contents($this->path));

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
     * Filter installs() down to those declaring the given role. Useful for
     * driving commands at a specific target: `cwm-link` walks installsFor('dev'),
     * `cwm-install-zip` and `cwm-verify --target test` walk installsFor('test').
     *
     * Returns an empty list when no install matches — callers should report
     * a clear message rather than silently no-op.
     *
     * @return list<InstallConfig>
     */
    public function installsFor(string $role): array
    {
        $out = [];

        foreach ($this->installs() as $install) {
            if ($install->role === $role) {
                $out[] = $install;
            }
        }

        return $out;
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
                role:    $this->normaliseRole((string) ($section['role'] ?? InstallConfig::ROLE_DEV)),
            );
        }

        return $installs;
    }

    /**
     * Validate the role value. Unknown roles are flagged loudly so a typo
     * (e.g. `role = teest`) doesn't silently exclude an install from every
     * target lookup.
     */
    private function normaliseRole(string $role): string
    {
        $trimmed = strtolower(trim($role));

        if ($trimmed === '') {
            return InstallConfig::ROLE_DEV;
        }

        if (!in_array($trimmed, [InstallConfig::ROLE_DEV, InstallConfig::ROLE_TEST], true)) {
            throw new \RuntimeException(
                "Unknown install role '{$role}' in build.properties — must be 'dev' or 'test'."
            );
        }

        return $trimmed;
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
        $rawDir = (string) ($raw['builder.joomla_dir'] ?? '');
        $dir    = trim($rawDir, '/');
        $paths  = array_values(array_filter(array_map('trim', explode(',', $pathsRaw))));

        if ($paths === []) {
            return [];
        }

        // Issue #2.2: Proclaim's existing build.properties uses
        // `builder.joomla_dir` as a separate ABSOLUTE path (a Joomla CMS
        // source clone used for class-signature checks), not as a relative
        // subpath under each install root. cwm-build-tools treats the same
        // key as a relative subpath, which is what the property's own
        // documentation says — but the collision means an existing
        // /Volumes/.../GitHub/joomla-cms value gets concatenated onto each
        // install path and produces nonsense like
        // `/Sites/j5-dev/Volumes/.../GitHub/joomla-cms`. Detect and ignore
        // absolute values rather than silently break the install paths.
        if ($dir !== '' && $this->looksAbsolute($rawDir)) {
            fwrite(
                \STDERR,
                "Warning: builder.joomla_dir='{$rawDir}' looks like an absolute path; "
                . "cwm-build-tools expects a relative subpath under each install root. "
                . "Ignoring it. (Set to empty, or rename the consumer-side absolute key, "
                . "to silence this warning.)\n",
            );
            $dir = '';
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
     * True when the value looks like an absolute filesystem path rather
     * than a relative subpath. Recognises POSIX `/foo`, Windows drive
     * paths (`C:\…`, `C:/…`), and UNC paths (`\\server\share`).
     */
    private function looksAbsolute(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        if ($value[0] === '/' || $value[0] === '\\') {
            return true;
        }

        // C: or C:/ or C:\
        return (bool) preg_match('~^[A-Za-z]:[\\\\/]?~', $value);
    }

    /**
     * Parse a Java-style properties / INI string, tolerating reserved characters
     * inside comment lines. PHP's parse_ini_string treats `?{}|&~!()^"[]` as
     * reserved even when they appear inside `#` or `;` comments — so a stock
     * `# Full path(s) to your install` line raises a syntax error and the whole
     * file fails to parse. We strip comment lines first, which is safe because
     * parse_ini drops them anyway.
     *
     * @return  array<string, mixed>|false
     *
     * @since   0.4.1-alpha
     */
    private static function parseProperties(string $contents): array|false
    {
        // Normalise line endings so the regex matches CRLF inputs too.
        $normalised = str_replace(["\r\n", "\r"], "\n", $contents);

        // Drop any line whose first non-whitespace character is # or ; — these
        // are comments by both Java-properties and INI conventions.
        $stripped = preg_replace('/^[ \t]*[#;].*$/m', '', $normalised);

        if ($stripped === null) {
            return false;
        }

        return parse_ini_string($stripped, true, INI_SCANNER_RAW);
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
            $lines[] = 'role = ' . $install->role;
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
