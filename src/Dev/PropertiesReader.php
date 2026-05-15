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
            : $this->fromFlat($raw);
    }

    /**
     * Read the `[paths]` block — per-developer absolute paths to CWM
     * sibling repos consumed via Composer path repositories.
     *
     * Returns an empty array when the file doesn't exist or has no
     * `[paths]` section. Keys are Composer package names
     * (e.g. "joomla-bible-study/lib-cwmscripture"); values are absolute
     * filesystem paths. Used by cwm-setup to remember per-developer
     * checkouts of CWM siblings, and to sync composer.json's
     * `repositories[]` block.
     *
     * @return array<string, string>
     */
    public function paths(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $raw = self::parseProperties((string) file_get_contents($this->path));

        if ($raw === false) {
            return [];
        }

        $out = [];

        // Flat format: `paths.<package> = <absolute path>`. Preferred form
        // since v1.4 — the template ships this shape so Java-properties-
        // aware IDEs don't flag the file.
        foreach ($raw as $key => $value) {
            if (is_string($value) && preg_match('/^paths\.(.+)$/', (string) $key, $m) === 1) {
                $out[$m[1]] = $value;
            }
        }

        // Backward-compat: `[paths]` INI section.
        if (isset($raw['paths']) && is_array($raw['paths'])) {
            foreach ($raw['paths'] as $key => $value) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
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
     * Reserved section names that the build.properties schema uses for
     * non-install purposes. Must be excluded from the auto-discovery
     * branch below or they'd be parsed as malformed installs.
     */
    private const RESERVED_SECTIONS = ['paths'];

    /**
     * @param  array<string, mixed>  $raw
     * @return list<InstallConfig>
     */
    private function fromSections(array $raw): array
    {
        $idsRaw = (string) ($raw['installs'] ?? '');
        $ids    = array_filter(array_map('trim', explode(',', $idsRaw)));

        if ($ids === []) {
            // No explicit list — treat every section as an install,
            // except reserved sections like [paths].
            $ids = array_keys(array_filter(
                $raw,
                static fn ($value, $key) => is_array($value) && !in_array((string) $key, self::RESERVED_SECTIONS, true),
                ARRAY_FILTER_USE_BOTH,
            ));
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
     * Parse the flat key=value format that ships as the canonical schema
     * since v1.4. Every key is globally unique so Java-properties-aware
     * IDEs (PhpStorm, IntelliJ) don't flag duplicate sections — the cost
     * of moving away from INI `[id]` sections.
     *
     * Recognized shape:
     *   joomla.version = 5.4.2           (default version fallback)
     *   builder.installs = j5, j6, ...   (explicit id list — preferred)
     *   builder.<id>.role        = dev|test
     *   builder.<id>.path        = /abs/path
     *   builder.<id>.url         = https://...
     *   builder.<id>.version     = 5.4.2
     *   builder.<id>.db_host/user/pass/name
     *   builder.<id>.admin_user/pass/email
     *
     * Backward-compat shims (Proclaim's pre-v1.4 layout still parses):
     *   builder.joomla_paths = /p1,/p2   (positional install paths)
     *   builder.joomla_dir   = subpath   (appended to each path; absolute
     *                                     values warned and ignored)
     *   builder.<id>.username/password/email  (Proclaim admin key names)
     *   Discover ids from `builder.<id>.url` when no installs= or paths
     *   listing is set; map `j5dev` → `j5` etc.
     *
     * @param  array<string, mixed>  $raw
     * @return list<InstallConfig>
     */
    private function fromFlat(array $raw): array
    {
        $installsRaw = (string) ($raw['builder.installs'] ?? '');
        $ids         = array_values(array_filter(array_map('trim', explode(',', $installsRaw))));

        $pathsRaw = (string) ($raw['builder.joomla_paths']
            ?? $raw['builder.joomla_path']
            ?? '');
        $positionalPaths = array_values(array_filter(array_map('trim', explode(',', $pathsRaw))));

        $rawDir = (string) ($raw['builder.joomla_dir'] ?? '');
        $dir    = trim($rawDir, '/');

        // Issue #2.2 carry-over: ignore absolute builder.joomla_dir values
        // (Proclaim's repo uses the same key for a separate absolute Joomla
        // CMS source path; concatenating it onto each install root produces
        // nonsense paths).
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

        // Auto-discovery when `builder.installs =` isn't set: prefer
        // per-install `.path` keys, fall back to `.url` (Proclaim legacy).
        if ($ids === []) {
            $idsFromPaths = [];
            $idsFromUrl   = [];

            foreach ($raw as $key => $_) {
                if (preg_match('/^builder\.([^.]+)\.path$/', (string) $key, $m) === 1) {
                    $idsFromPaths[] = $m[1];
                } elseif (preg_match('/^builder\.([^.]+)\.url$/', (string) $key, $m) === 1) {
                    $idsFromUrl[] = $m[1];
                }
            }

            $ids = array_values(array_unique($idsFromPaths !== [] ? $idsFromPaths : $idsFromUrl));
        }

        // Proclaim legacy: positional paths with default ids.
        if ($ids === [] && $positionalPaths !== []) {
            $ids = ['j5dev', 'j6dev'];
        }

        if ($ids === []) {
            return [];
        }

        $defaultVersion = (string) ($raw['joomla.version'] ?? '');
        $installs       = [];

        foreach ($ids as $i => $id) {
            $prefix = "builder.{$id}";

            // Prefer explicit per-install path; fall back to positional
            // builder.joomla_paths by index for the Proclaim legacy layout.
            $path = (string) ($raw["{$prefix}.path"] ?? ($positionalPaths[$i] ?? ''));
            $path = rtrim($path, '/');

            if ($path !== '' && $dir !== '') {
                $path .= '/' . $dir;
            }

            $version = (string) ($raw["{$prefix}.version"] ?? $defaultVersion);

            $installs[] = new InstallConfig(
                id:      $this->normaliseLegacyId($id),
                path:    $path,
                url:     ($raw["{$prefix}.url"] ?? '') === '' ? null : (string) $raw["{$prefix}.url"],
                version: $version === '' ? null : $version,
                db:      [
                    'host' => (string) ($raw["{$prefix}.db_host"] ?? 'localhost'),
                    'user' => (string) ($raw["{$prefix}.db_user"] ?? ''),
                    'pass' => (string) ($raw["{$prefix}.db_pass"] ?? ''),
                    'name' => (string) ($raw["{$prefix}.db_name"] ?? ''),
                ],
                admin:   [
                    'user'  => (string) ($raw["{$prefix}.admin_user"]
                        ?? $raw["{$prefix}.username"]
                        ?? 'admin'),
                    'pass'  => (string) ($raw["{$prefix}.admin_pass"]
                        ?? $raw["{$prefix}.password"]
                        ?? 'admin'),
                    'email' => (string) ($raw["{$prefix}.admin_email"]
                        ?? $raw["{$prefix}.email"]
                        ?? 'admin@example.com'),
                ],
                role:    $this->normaliseRole((string) ($raw["{$prefix}.role"] ?? InstallConfig::ROLE_DEV)),
            );
        }

        return $installs;
    }

    /**
     * Backward-compat alias retained so any external caller using the
     * pre-v1.4 method name still works. Internal callers should use
     * fromFlat().
     *
     * @param  array<string, mixed>  $raw
     * @return list<InstallConfig>
     */
    private function fromLegacyFlat(array $raw): array
    {
        return $this->fromFlat($raw);
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
     * Write a fresh build.properties from a list of installs. Preserves
     * any existing [paths] block (per-developer CWM sibling paths) so a
     * setup re-run that reconfigures installs doesn't accidentally drop
     * the path-repo state. Existing comments are NOT preserved — the
     * file is regenerated from scratch.
     *
     * @param  list<InstallConfig>  $installs
     */
    public function write(array $installs): void
    {
        $existingPaths = $this->paths();

        $this->writeFile($installs, $existingPaths);
    }

    /**
     * Inverse of write(): preserves installs while replacing the [paths]
     * block. Used by cwm-setup's CWM-siblings flow.
     *
     * @param  array<string, string>  $paths
     */
    public function writePaths(array $paths): void
    {
        $existingInstalls = $this->exists() ? $this->installs() : [];

        $this->writeFile($existingInstalls, $paths);
    }

    /**
     * Single emitter for the whole build.properties file — combines the
     * `builder.<id>.*` install blocks with the `paths.<package>` cross-
     * component dep map. Both write() and writePaths() funnel through
     * here so neither path accidentally drops the other's state.
     *
     * Emits the flat-key format (the v1.4 canonical schema): every key
     * is globally unique so IDEs that parse `.properties` as Java-style
     * don't flag duplicate sections like `[j5]role=dev` vs `[j6]role=dev`.
     *
     * @param  list<InstallConfig>     $installs
     * @param  array<string, string>   $paths
     */
    private function writeFile(array $installs, array $paths): void
    {
        $lines = [
            '# build.properties — local Joomla installs for cwm-build-tools dev commands.',
            '# Gitignored. Per-developer. Generated by `composer setup`.',
            '',
        ];

        if ($installs !== []) {
            $ids     = array_map(static fn (InstallConfig $i): string => $i->id, $installs);
            $lines[] = '# Comma-separated list of install ids.';
            $lines[] = 'builder.installs = ' . implode(', ', $ids);
            $lines[] = '';

            foreach ($installs as $install) {
                $prefix  = "builder.{$install->id}";
                $lines[] = "# ----- {$install->id} ({$install->role}) -----";
                $lines[] = "{$prefix}.role        = {$install->role}";
                $lines[] = "{$prefix}.path        = {$install->path}";
                $lines[] = "{$prefix}.url         = " . ($install->url ?? '');
                $lines[] = "{$prefix}.version     = " . ($install->version ?? '');
                $lines[] = "{$prefix}.db_host     = " . $install->dbHost();
                $lines[] = "{$prefix}.db_user     = " . $install->dbUser();
                $lines[] = "{$prefix}.db_pass     = " . $install->dbPass();
                $lines[] = "{$prefix}.db_name     = " . $install->dbName();
                $lines[] = "{$prefix}.admin_user  = " . $install->adminUser();
                $lines[] = "{$prefix}.admin_pass  = " . $install->adminPass();
                $lines[] = "{$prefix}.admin_email = " . $install->adminEmail();
                $lines[] = '';
            }
        }

        if ($paths !== []) {
            $lines[] = '# Per-developer absolute paths to CWM siblings declared in';
            $lines[] = '# cwm-build.config.json dev.cwmSiblings. Written by cwm-setup.';

            foreach ($paths as $package => $absolutePath) {
                $lines[] = 'paths.' . $package . ' = ' . $absolutePath;
            }

            $lines[] = '';
        }

        file_put_contents($this->path, implode("\n", $lines));
    }
}
