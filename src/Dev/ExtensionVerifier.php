<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

use CWM\BuildTools\Config\CwmPackage;

/**
 * Verifies that every extension declared in a project's
 * cwm-build.config.json (manifests.extensions[] + the top-level extension)
 * is registered in the #__extensions table of each configured Joomla DB.
 *
 * The verifier reads each manifest XML to discover (type, element, folder,
 * library_name, etc.) — projects do not need to duplicate that data into the
 * config file.
 *
 * Two modes:
 *   - report:    print OK / MISSING / WRONG-STATE for each extension/install pair.
 *   - reconcile: same, but try to UPDATE enabled/locked drift and (for
 *                libraries) INSERT a missing row + run install SQL.
 *
 * Component rows are not auto-inserted — they should be installed via the
 * Extension Manager so the rest of the install lifecycle (params, schema
 * version) runs.
 */
final class ExtensionVerifier
{
    public function __construct(
        private readonly string $projectRoot,
        /** @var array<string, mixed> */
        private readonly array $config,
        private readonly bool $verbose = false,
    ) {
    }

    /**
     * Verify against one Joomla install. Reads configuration.php from
     * $install->path to discover the DB, connects, then walks expected
     * extensions: the project's own (`expectedExtensions()`) plus every
     * declared joomlaLinks entry from each CWM Composer dep supplied.
     *
     * @param  list<CwmPackage> $packages CWM deps discovered via InstalledPackageReader.
     *                                    Each one's joomlaLinks tuples are folded
     *                                    into the expected-extensions list and
     *                                    checked against #__extensions the same
     *                                    way as the project's own extensions.
     * @return array{ok: int, fixed: int, errors: int}
     */
    public function verify(InstallConfig $install, bool $reconcile = false, array $packages = []): array
    {
        echo "\n=== Verifying: {$install->path} ===\n";

        if (!is_dir($install->path)) {
            echo "  ERROR: Path does not exist\n";

            return ['ok' => 0, 'fixed' => 0, 'errors' => 1];
        }

        $db = $this->loadJoomlaDbConfig($install->path);

        if ($db === null) {
            echo "  ERROR: Could not read configuration.php at {$install->path}\n";

            return ['ok' => 0, 'fixed' => 0, 'errors' => 1];
        }

        try {
            $pdo = new \PDO(
                'mysql:host=' . $db['host'] . ';dbname=' . $db['db'] . ';charset=utf8mb4',
                $db['user'],
                $db['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\PDOException $e) {
            echo '  ERROR: DB connection failed: ' . $e->getMessage() . "\n";

            return ['ok' => 0, 'fixed' => 0, 'errors' => 1];
        }

        $prefix       = $db['dbprefix'];
        $hasNamespace = $this->hasNamespaceColumn($pdo, $prefix);
        $expected     = array_merge($this->expectedExtensions(), $this->expectedFromPackages($packages));

        $ok     = 0;
        $fixed  = 0;
        $errors = 0;

        foreach ($expected as $ext) {
            $row = $this->lookup($pdo, $prefix, $ext);

            if ($row !== null) {
                $drift         = $this->computeDrift($row, $ext);
                $manifestDrift = $this->detectManifestCacheDrift($ext, $row);

                if ($drift === [] && $manifestDrift === []) {
                    if ($this->verbose) {
                        echo "  OK:     {$ext['name']} ({$ext['type']})\n";
                    }
                    $ok++;

                    continue;
                }

                if (!$reconcile) {
                    if ($drift !== []) {
                        echo "  DRIFT:  {$ext['name']} — needs " . implode(', ', $drift) . "\n";
                        $errors++;
                    }

                    if ($manifestDrift !== []) {
                        echo "  STALE:  {$ext['name']} manifest_cache — " . implode(', ', $manifestDrift) . "\n";
                        $errors++;
                    }

                    continue;
                }

                if ($drift !== []) {
                    $sql  = "UPDATE {$prefix}extensions SET " . implode(', ', $drift)
                        . ' WHERE extension_id = ' . (int) $row['extension_id'];
                    $pdo->prepare($sql)->execute();
                    echo "  FIXED:  {$ext['name']} — " . implode(', ', $drift) . "\n";
                    $fixed++;
                }

                if ($manifestDrift !== []) {
                    $expected = $this->parseManifestXml((string) ($ext['_manifestPath'] ?? ''));

                    if ($expected !== null
                        && $this->rebuildManifestCache($pdo, $prefix, (int) $row['extension_id'], $expected)
                    ) {
                        echo "  REBUILT: {$ext['name']} manifest_cache — " . implode(', ', $manifestDrift) . "\n";
                        $fixed++;
                    } else {
                        echo "  STALE:  {$ext['name']} manifest_cache — could not rebuild (source XML missing?)\n";
                        $errors++;
                    }
                }

                continue;
            }

            if (!$reconcile) {
                echo "  MISS:   {$ext['name']} ({$ext['type']})\n";
                $errors++;

                continue;
            }

            $action = match ($ext['type']) {
                'library' => $this->insertLibrary($pdo, $prefix, $ext, $hasNamespace),
                'plugin'  => $this->insertPlugin($pdo, $prefix, $ext, $hasNamespace),
                default   => null,
            };

            if ($action === 'added') {
                echo "  ADDED:  {$ext['name']} ({$ext['type']})\n";
                $fixed++;
            } else {
                echo "  MISS:   {$ext['name']} ({$ext['type']}) — install via Extension Manager\n";
                $errors++;
            }

            if (($row !== null || $action === 'added') && $ext['type'] === 'component' && !empty($ext['menus'])) {
                $id           = $row ? (int) $row['extension_id'] : (int) $pdo->lastInsertId();
                $menuResults  = $this->verifyMenus($pdo, $prefix, $id, $ext, $reconcile);
                $ok          += $menuResults['ok'];
                $fixed       += $menuResults['fixed'];
                $errors      += $menuResults['errors'];
            }
        }

        echo "  Summary: {$ok} OK, {$fixed} fixed, {$errors} errors\n";

        return ['ok' => $ok, 'fixed' => $fixed, 'errors' => $errors];
    }

    /**
     * Build expected-extension rows from each CWM Composer dep's declared
     * joomlaLinks tuples. Same row shape as `expectedExtensions()` so the
     * downstream lookup / drift / reconcile machinery handles both
     * uniformly.
     *
     * Per-type mapping (matches Joomla's #__extensions storage conventions):
     *   library    name=X         → element=X,        folder='',  display='lib_X', locked=1
     *   plugin     group=G,elem=E → element=E,        folder=G,   display='plg_G_E'
     *   module     name=M,client=C → element=M,       folder='',  display=M, client_id={0|1}
     *   component  name=C         → element=C,        folder='',  display=C
     *
     * Source package is recorded in `_package` so verify() can group output.
     *
     * @param  list<CwmPackage> $packages
     * @return list<array<string, mixed>>
     */
    public function expectedFromPackages(array $packages): array
    {
        $out = [];

        foreach ($packages as $pkg) {
            foreach ($pkg->joomlaLinks as $link) {
                $row = match ($link['type']) {
                    'library'   => $this->expectedLibraryRow($link),
                    'plugin'    => $this->expectedPluginRow($link),
                    'module'    => $this->expectedModuleRow($link),
                    'component' => $this->expectedComponentRow($link),
                    default     => null,
                };

                if ($row !== null) {
                    $row['_package']      = $pkg->name;
                    $row['_version']      = $pkg->version;
                    $row['_manifestPath'] = $this->manifestPathForPackageLink($pkg->sourceRoot(), $link);
                    $out[] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * Resolve where a CWM dep's source manifest XML lives so the
     * manifest_cache check can read the canonical declaration.
     *
     * Order of preference:
     *   1. Explicit `manifest` field on the joomlaLinks tuple (override)
     *   2. Conventional location per type
     *      - library/module/component: <pkgRoot>/<name>.xml
     *      - plugin: <pkgRoot>/<element>.xml or <pkgRoot>/plugins/<group>/<element>/<element>.xml
     *   3. null when nothing on disk matches (manifest check is skipped
     *      for that extension with a warning)
     *
     * @param  array<string, string> $link
     */
    private function manifestPathForPackageLink(string $pkgRoot, array $link): ?string
    {
        $pkgRoot = rtrim($pkgRoot, '/');

        if (isset($link['manifest']) && is_string($link['manifest']) && $link['manifest'] !== '') {
            $explicit = $pkgRoot . '/' . ltrim($link['manifest'], '/');

            return is_file($explicit) ? $explicit : null;
        }

        $candidates = [];

        switch ($link['type']) {
            case 'library':
            case 'module':
            case 'component':
                $name = $link['name'] ?? null;

                if ($name !== null) {
                    $candidates[] = $pkgRoot . '/' . $name . '.xml';
                }
                break;

            case 'plugin':
                $element = $link['element'] ?? null;
                $group   = $link['group']   ?? null;

                if ($element !== null) {
                    $candidates[] = $pkgRoot . '/' . $element . '.xml';
                }

                if ($element !== null && $group !== null) {
                    $candidates[] = $pkgRoot . '/plugins/' . $group . '/' . $element . '/' . $element . '.xml';
                }
                break;
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string> $link
     * @return array<string, mixed>
     */
    private function expectedLibraryRow(array $link): array
    {
        $name = $link['name'];

        return [
            'type'      => 'library',
            'element'   => $name,
            'folder'    => '',
            'name'      => 'lib_' . $name,
            'enabled'   => 1,
            'locked'    => 1,
            'namespace' => null,
        ];
    }

    /**
     * @param  array<string, string> $link
     * @return array<string, mixed>
     */
    private function expectedPluginRow(array $link): array
    {
        $group   = $link['group'];
        $element = $link['element'];

        return [
            'type'      => 'plugin',
            'element'   => $element,
            'folder'    => $group,
            'name'      => "plg_{$group}_{$element}",
            'enabled'   => 1,
            'locked'    => 0,
            'namespace' => null,
        ];
    }

    /**
     * @param  array<string, string> $link
     * @return array<string, mixed>
     */
    private function expectedModuleRow(array $link): array
    {
        $name      = $link['name'];
        $client    = $link['client'] ?? 'site';
        $clientId  = $client === 'administrator' ? 1 : 0;

        return [
            'type'      => 'module',
            'element'   => $name,
            'folder'    => '',
            'name'      => $name,
            'enabled'   => 1,
            'locked'    => 0,
            'namespace' => null,
            'client_id' => $clientId,
        ];
    }

    /**
     * @param  array<string, string> $link
     * @return array<string, mixed>
     */
    private function expectedComponentRow(array $link): array
    {
        $name = $link['name'];

        return [
            'type'      => 'component',
            'element'   => $name,
            'folder'    => '',
            'name'      => $name,
            'enabled'   => 1,
            'locked'    => 0,
            'namespace' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function expectedExtensions(): array
    {
        $out = [];

        $extension = $this->config['extension'] ?? null;

        if (is_array($extension) && ($extension['type'] ?? null) === 'component') {
            $name = (string) ($extension['name'] ?? '');

            if ($name !== '') {
                $comp = [
                    'type'      => 'component',
                    'element'   => $name,
                    'folder'    => '',
                    'name'      => $name,
                    'enabled'   => 1,
                    'locked'    => 0,
                    'namespace' => null,
                ];

                $manifestPath = $this->findComponentManifest($name);

                if ($manifestPath) {
                    $comp['menus']         = $this->extractMenus($manifestPath);
                    $comp['_manifestPath'] = $manifestPath;
                }

                $out[] = $comp;
            }
        }

        foreach ($this->config['manifests']['extensions'] ?? [] as $manifest) {
            $type    = (string) ($manifest['type'] ?? '');
            $manPath = $this->projectRoot . '/' . ltrim((string) ($manifest['path'] ?? ''), '/');

            if (!is_file($manPath)) {
                continue;
            }

            $row = $this->describeManifest($type, $manPath);

            if ($row !== null) {
                $row['_manifestPath'] = $manPath;
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeManifest(string $type, string $manifestPath): ?array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_file($manifestPath);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$xml instanceof \SimpleXMLElement) {
            return null;
        }

        $rawName   = trim((string) $xml->name);
        $namespace = trim((string) ($xml->namespace ?? ''));

        return match ($type) {
            'component' => $this->describeComponent($xml, $manifestPath),
            'library'   => $this->describeLibrary($xml, $manifestPath, $namespace),
            'plugin'    => $this->describePlugin($xml, $manifestPath, $rawName, $namespace),
            default     => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function describeComponent(\SimpleXMLElement $xml, string $manifestPath): array
    {
        $name = (string) $xml->name;

        return [
            'type'      => 'component',
            'element'   => $name,
            'folder'    => '',
            'name'      => $name,
            'enabled'   => 1,
            'locked'    => 0,
            'namespace' => (string) ($xml->namespace ?? null) ?: null,
            'menus'     => $this->extractMenus($manifestPath),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeLibrary(\SimpleXMLElement $xml, string $manifestPath, string $namespace): array
    {
        $libraryName = trim((string) ($xml->libraryname ?? ''));
        $name        = trim((string) $xml->name);

        if ($libraryName === '' && $name !== '') {
            $libraryName = preg_replace('/^lib_/', '', strtolower($name)) ?? $name;
        }

        $row = [
            'type'      => 'library',
            'element'   => $libraryName,
            'folder'    => '',
            'name'      => $name !== '' ? $name : "lib_{$libraryName}",
            'enabled'   => 1,
            'locked'    => 1,
            'namespace' => $namespace !== '' ? $namespace : null,
        ];

        $sqlInstall = \dirname($manifestPath) . '/sql/install.mysql.utf8.sql';

        if (is_file($sqlInstall)) {
            $row['installSql'] = $sqlInstall;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function describePlugin(\SimpleXMLElement $xml, string $manifestPath, string $rawName, string $namespace): array
    {
        $folder  = trim((string) ($xml['group'] ?? ''));
        $element = pathinfo($manifestPath, PATHINFO_FILENAME);

        return [
            'type'      => 'plugin',
            'element'   => $element,
            'folder'    => $folder,
            'name'      => $rawName !== '' ? $rawName : "plg_{$folder}_{$element}",
            'enabled'   => 1,
            'locked'    => 0,
            'namespace' => $namespace !== '' ? $namespace : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * Compare the row's stored manifest_cache JSON against the canonical
     * data parsed from the source manifest XML. Returns drift descriptions
     * suitable for surfacing in verify output.
     *
     * No-ops when the row carries no `_manifestPath` (CWM dep without a
     * discoverable manifest under its source root) or when manifest_cache
     * is empty (treated as "newly installed, will be populated on next
     * Joomla install/update of that extension").
     *
     * @param  array<string, mixed> $ext  Expected extension row.
     * @param  array<string, mixed> $row  DB row from lookup().
     * @return list<string>
     */
    private function detectManifestCacheDrift(array $ext, array $row): array
    {
        $manifestPath = (string) ($ext['_manifestPath'] ?? '');

        if ($manifestPath === '' || !is_file($manifestPath)) {
            return [];
        }

        $actualJson = (string) ($row['manifest_cache'] ?? '');

        if ($actualJson === '' || $actualJson === '[]' || $actualJson === '{}') {
            // Empty cache means Joomla never wrote one — flag, but it's
            // not the staleness pattern we're catching here.
            return ['empty manifest_cache (run extension install/update)'];
        }

        $expected = $this->parseManifestXml($manifestPath);

        if ($expected === null) {
            return [];
        }

        return $this->compareManifestCache($expected, $actualJson);
    }

    /**
     * Parse an extension manifest XML file into the exact shape Joomla's
     * `Installer::parseXMLInstallFile()` produces — which is what ends up
     * serialized into the `#__extensions.manifest_cache` column on install
     * and update. Used by the manifest_cache staleness check to compare
     * the canonical XML against whatever JSON currently sits in the DB.
     *
     * Matches joomla-cms 5.4 `libraries/src/Installer/Installer.php::parseXMLInstallFile`.
     *
     * @return array<string, mixed>|null
     */
    public function parseManifestXml(string $manifestPath): ?array
    {
        if (!is_file($manifestPath)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_file($manifestPath);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$xml instanceof \SimpleXMLElement) {
            return null;
        }

        $root = $xml->getName();

        if ($root !== 'extension' && $root !== 'metafile') {
            return null;
        }

        $data = [];

        $data['name'] = (string) $xml->name;
        $data['type'] = $root === 'metafile'
            ? 'language'
            : (string) $xml->attributes()->type;

        $data['creationDate'] = ((string) $xml->creationDate) ?: 'Unknown';
        $data['author']       = ((string) $xml->author) ?: 'Unknown';
        $data['copyright']    = (string) $xml->copyright;
        $data['authorEmail']  = (string) $xml->authorEmail;
        $data['authorUrl']    = (string) $xml->authorUrl;
        $data['version']      = (string) $xml->version;
        $data['description']  = (string) $xml->description;
        $data['group']        = (string) $xml->group;
        $data['changelogurl'] = (string) $xml->changelogurl;

        if (isset($xml->inheritable)) {
            $data['inheritable'] = (string) $xml->inheritable !== '0';
        }

        $namespace = isset($xml->namespace) ? (string) $xml->namespace : '';

        if ($namespace !== '') {
            $data['namespace'] = $namespace;
        }

        if (isset($xml->parent) && (string) $xml->parent !== '') {
            $data['parent'] = (string) $xml->parent;
        }

        if ($xml->files && count($xml->files->children()) > 0) {
            $filename         = basename($manifestPath);
            $data['filename'] = preg_replace('/\.[^.]+$/', '', $filename) ?: $filename;

            foreach ($xml->files->children() as $oneFile) {
                $pluginAttr = (string) $oneFile->attributes()->plugin;

                if ($pluginAttr !== '') {
                    $data['filename'] = $pluginAttr;
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * Compare the canonical manifest data (from the source XML) with the
     * manifest_cache JSON currently stored on the extension row. Returns a
     * list of human-readable drift descriptions; an empty list means the
     * cache is up to date.
     *
     * Only checks the fields the Joomla manage view actually reads — version,
     * name, description, author — because those are the ones whose staleness
     * triggers the mb_strtolower-on-null deprecation noise in Joomla 6's
     * extension manager. Other fields (creationDate, license, etc.) are
     * informational and not worth flagging as drift.
     *
     * @param  array<string, mixed> $expected From parseManifestXml().
     * @return list<string>
     */
    public function compareManifestCache(array $expected, string $actualJson): array
    {
        $actual = json_decode($actualJson, true);

        if (!is_array($actual)) {
            return ['manifest_cache is not valid JSON'];
        }

        $checked = ['name', 'version', 'description', 'author'];
        $drift   = [];

        foreach ($checked as $field) {
            $exp = $expected[$field] ?? '';
            $cur = $actual[$field] ?? '';

            if ($exp === '' && $cur === '') {
                continue;
            }

            if ((string) $exp !== (string) $cur) {
                $expDisplay = $exp === '' ? '(empty)' : $exp;
                $curDisplay = $cur === '' ? '(empty)' : $cur;
                $drift[]    = "{$field}: '{$curDisplay}' → '{$expDisplay}'";
            }
        }

        return $drift;
    }

    /**
     * Rebuild the manifest_cache JSON column for a #__extensions row by
     * UPDATEing it with the canonical data from the source manifest XML.
     * Returns true when the row was updated, false on PDO error.
     */
    private function rebuildManifestCache(\PDO $pdo, string $prefix, int $extensionId, array $expected): bool
    {
        $json = json_encode($expected, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                "UPDATE {$prefix}extensions SET manifest_cache = ? WHERE extension_id = ?"
            );

            return $stmt->execute([$json, $extensionId]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    private function lookup(\PDO $pdo, string $prefix, array $ext): ?array
    {
        $sql    = "SELECT extension_id, enabled, locked, manifest_cache FROM {$prefix}extensions WHERE type = ? AND element = ?";
        $params = [$ext['type'], $ext['element']];

        if ($ext['type'] === 'plugin' && ($ext['folder'] ?? '') !== '') {
            $sql .= ' AND folder = ?';
            $params[] = $ext['folder'];
        }

        // Modules with the same element can exist in both site (0) and admin (1)
        // client contexts; filter by client_id when supplied so we hit the right row.
        if (isset($ext['client_id'])) {
            $sql .= ' AND client_id = ?';
            $params[] = (int) $ext['client_id'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return list<string>
     */
    private function computeDrift(array $row, array $ext): array
    {
        $sets = [];

        if ((int) $row['enabled'] !== (int) $ext['enabled']) {
            $sets[] = 'enabled = ' . (int) $ext['enabled'];
        }

        if ((int) $ext['locked'] === 1 && (int) $row['locked'] !== 1) {
            $sets[] = 'locked = 1';
        }

        return $sets;
    }

    private function insertLibrary(\PDO $pdo, string $prefix, array $ext, bool $hasNamespace): string
    {
        $manifest = json_encode([
            'name'        => $ext['name'],
            'libraryname' => $ext['element'],
        ], JSON_UNESCAPED_SLASHES);

        if ($hasNamespace) {
            $stmt = $pdo->prepare(
                "INSERT INTO {$prefix}extensions "
                . '(name, type, element, folder, client_id, enabled, access, locked, manifest_cache, params, custom_data, namespace) '
                . "VALUES (?, 'library', ?, '', 0, 1, 1, ?, ?, '{}', '', ?)"
            );
            $stmt->execute([$ext['name'], $ext['element'], (int) $ext['locked'], $manifest, $ext['namespace'] ?? '']);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO {$prefix}extensions "
                . '(name, type, element, folder, client_id, enabled, access, locked, manifest_cache, params, custom_data) '
                . "VALUES (?, 'library', ?, '', 0, 1, 1, ?, ?, '{}', '')"
            );
            $stmt->execute([$ext['name'], $ext['element'], (int) $ext['locked'], $manifest]);
        }

        if (isset($ext['installSql']) && is_file($ext['installSql'])) {
            $sql        = (string) file_get_contents($ext['installSql']);
            $sql        = str_replace('#__', $prefix, $sql);
            $statements = array_filter(array_map('trim', explode(';', $sql)));

            foreach ($statements as $statement) {
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }

                try {
                    $pdo->prepare($statement)->execute();
                } catch (\PDOException $e) {
                    if ($this->verbose) {
                        echo '    NOTE: ' . substr($e->getMessage(), 0, 100) . "\n";
                    }
                }
            }
        }

        return 'added';
    }

    private function insertPlugin(\PDO $pdo, string $prefix, array $ext, bool $hasNamespace): string
    {
        if ($hasNamespace) {
            $stmt = $pdo->prepare(
                "INSERT INTO {$prefix}extensions "
                . '(name, type, element, folder, client_id, enabled, access, locked, manifest_cache, params, custom_data, namespace) '
                . "VALUES (?, 'plugin', ?, ?, 0, ?, 1, 0, '{}', '{}', '', ?)"
            );
            $stmt->execute([
                $ext['name'],
                $ext['element'],
                $ext['folder'],
                (int) $ext['enabled'],
                $ext['namespace'] ?? '',
            ]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO {$prefix}extensions "
                . '(name, type, element, folder, client_id, enabled, access, locked, manifest_cache, params, custom_data) '
                . "VALUES (?, 'plugin', ?, ?, 0, ?, 1, 0, '{}', '{}', '')"
            );
            $stmt->execute([
                $ext['name'],
                $ext['element'],
                $ext['folder'],
                (int) $ext['enabled'],
            ]);
        }

        return 'added';
    }

    private function hasNamespaceColumn(\PDO $pdo, string $prefix): bool
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM {$prefix}extensions LIKE 'namespace'");

        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    }

    /**
     * Read the Joomla DB connection info out of `<joomlaPath>/configuration.php`.
     *
     * Joomla's configuration.php is generated and follows a deterministic
     * `public $key = 'value';` shape; we parse it as text rather than
     * evaluating arbitrary PHP from disk.
     *
     * @return array{host: string, user: string, password: string, db: string, dbprefix: string}|null
     */
    private function loadJoomlaDbConfig(string $joomlaPath): ?array
    {
        $configFile = $joomlaPath . '/configuration.php';

        if (!is_file($configFile)) {
            return null;
        }

        $content = (string) file_get_contents($configFile);
        $values  = [];

        if (preg_match_all('/public\s+\$(\w+)\s*=\s*([\'"])(.*?)\2\s*;/s', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $values[$match[1]] = $this->unescapePhpString($match[3], $match[2]);
            }
        }

        if (!isset($values['db'])) {
            return null;
        }

        return [
            'host'     => $values['host'] ?? 'localhost',
            'user'     => $values['user'] ?? '',
            'password' => $values['password'] ?? '',
            'db'       => $values['db'],
            'dbprefix' => $values['dbprefix'] ?? 'jos_',
        ];
    }

    private function unescapePhpString(string $raw, string $quote): string
    {
        if ($quote === "'") {
            return strtr($raw, ['\\\\' => '\\', "\\'" => "'"]);
        }

        return stripcslashes($raw);
    }

    /**
     * @param array<string, mixed> $ext
     * @return array{ok: int, fixed: int, errors: int}
     */
    private function verifyMenus(\PDO $pdo, string $prefix, int $componentId, array $ext, bool $reconcile): array
    {
        $expectedMenus = $ext['menus'] ?? [];
        $ok            = 0;
        $fixed         = 0;
        $errors        = 0;

        // Query existing menus for this component in admin client (client_id = 1).
        $stmt = $pdo->prepare(
            "SELECT id, title, parent_id, link FROM {$prefix}menu WHERE component_id = ? AND client_id = 1"
        );
        $stmt->execute([$componentId]);
        $existing = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $parentMenuId = 0;

        foreach ($expectedMenus as $menu) {
            $foundRow = null;

            foreach ($existing as $ex) {
                if ($ex['title'] === $menu['text']) {
                    $foundRow = $ex;

                    break;
                }
            }

            if ($foundRow !== null) {
                if ($menu['level'] === 1) {
                    $parentMenuId = (int) $foundRow['id'];
                }

                if ($this->verbose) {
                    echo "    OK:     Menu: {$menu['text']}\n";
                }
                $ok++;

                continue;
            }

            if (!$reconcile) {
                echo "    MISS:   Menu: {$menu['text']}\n";
                $errors++;

                continue;
            }

            // Fix missing menu.
            try {
                $parentId = $menu['level'] === 1 ? 1 : $parentMenuId;
                $newId    = $this->insertMenu($pdo, $prefix, $componentId, $menu, $parentId);

                if ($menu['level'] === 1) {
                    $parentMenuId = $newId;
                }

                echo "    ADDED:  Menu: {$menu['text']}\n";
                $fixed++;
            } catch (\Exception $e) {
                echo "    ERROR:  Menu: {$menu['text']} — " . $e->getMessage() . "\n";
                $errors++;
            }
        }

        return ['ok' => $ok, 'fixed' => $fixed, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $menu
     */
    private function insertMenu(\PDO $pdo, string $prefix, int $componentId, array $menu, int $parentId): int
    {
        // Find max rgt to append to the end of the tree.
        $stmt = $pdo->query("SELECT MAX(rgt) FROM {$prefix}menu");
        $maxRgt = (int) $stmt->fetchColumn();

        $lft = $maxRgt + 1;
        $rgt = $maxRgt + 2;

        $alias = preg_replace('/[^a-z0-9-]/', '-', strtolower($menu['text'])) ?? $menu['text'];
        $link  = $menu['link'] !== '' ? $menu['link'] : 'index.php?option=' . $menu['text'];

        if (!str_contains($link, 'option=')) {
            $link = 'index.php?option=' . $link;
        }

        $sql = "INSERT INTO {$prefix}menu "
            . '(menutype, title, alias, note, path, link, type, published, parent_id, level, component_id, '
            . 'checked_out, checked_out_time, browserNav, access, img, template_style_id, params, lft, rgt, '
            . 'home, language, client_id) '
            . "VALUES ('main', ?, ?, '', ?, ?, 'component', 1, ?, ?, ?, 0, '0000-00-00 00:00:00', 0, 1, ?, 0, '', ?, ?, 0, '*', 1)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $menu['text'],
            $alias,
            $alias,
            $link,
            $parentId,
            $menu['level'],
            $componentId,
            $menu['img'],
            $lft,
            $rgt,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractMenus(string $manifestPath): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_file($manifestPath);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$xml instanceof \SimpleXMLElement) {
            return [];
        }

        $menus = [];
        $admin = $xml->administration;

        if (!$admin) {
            return [];
        }

        if (isset($admin->menu)) {
            $main    = $admin->menu;
            $menus[] = [
                'text'  => trim((string) $main),
                'link'  => (string) ($main['link'] ?? ''),
                'view'  => (string) ($main['view'] ?? ''),
                'img'   => (string) ($main['img'] ?? ''),
                'alt'   => (string) ($main['alt'] ?? ''),
                'level' => 1,
            ];

            if (isset($admin->submenu->menu)) {
                foreach ($admin->submenu->menu as $sub) {
                    $menus[] = [
                        'text'   => trim((string) $sub),
                        'link'   => (string) ($sub['link'] ?? ''),
                        'view'   => (string) ($sub['view'] ?? ''),
                        'img'    => (string) ($sub['img'] ?? ''),
                        'alt'    => (string) ($sub['alt'] ?? ''),
                        'parent' => trim((string) $main),
                        'level'  => 2,
                    ];
                }
            }
        }

        return $menus;
    }

    private function findComponentManifest(string $componentName): ?string
    {
        // 1. Check build.manifest
        $buildManifest = $this->config['build']['manifest'] ?? null;

        if ($buildManifest && is_file($this->projectRoot . '/' . $buildManifest)) {
            return $this->projectRoot . '/' . $buildManifest;
        }

        // 2. Check <componentName>.xml in root
        $nameXml = $this->projectRoot . '/' . $componentName . '.xml';

        if (is_file($nameXml)) {
            return $nameXml;
        }

        // 3. Check stripped name.xml (e.g. proclaim.xml for com_proclaim)
        $stripped    = preg_replace('/^com_/', '', $componentName) ?? $componentName;
        $strippedXml = $this->projectRoot . '/' . $stripped . '.xml';

        if (is_file($strippedXml)) {
            return $strippedXml;
        }

        return null;
    }
}
