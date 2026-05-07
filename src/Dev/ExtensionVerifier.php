<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

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
     * extensions.
     *
     * @return array{ok: int, fixed: int, errors: int}
     */
    public function verify(InstallConfig $install, bool $reconcile = true): array
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
        $expected     = $this->expectedExtensions();

        $ok     = 0;
        $fixed  = 0;
        $errors = 0;

        foreach ($expected as $ext) {
            $row = $this->lookup($pdo, $prefix, $ext);

            if ($row !== null) {
                $drift = $this->computeDrift($row, $ext);

                if ($drift === []) {
                    if ($this->verbose) {
                        echo "  OK:     {$ext['name']} ({$ext['type']})\n";
                    }
                    $ok++;

                    continue;
                }

                if (!$reconcile) {
                    echo "  DRIFT:  {$ext['name']} — needs " . implode(', ', $drift) . "\n";
                    $errors++;

                    continue;
                }

                $sql  = "UPDATE {$prefix}extensions SET " . implode(', ', $drift)
                    . ' WHERE extension_id = ' . (int) $row['extension_id'];
                $pdo->prepare($sql)->execute();
                echo "  FIXED:  {$ext['name']} — " . implode(', ', $drift) . "\n";
                $fixed++;

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
        }

        echo "  Summary: {$ok} OK, {$fixed} fixed, {$errors} errors\n";

        return ['ok' => $ok, 'fixed' => $fixed, 'errors' => $errors];
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
                $out[] = [
                    'type'      => 'component',
                    'element'   => $name,
                    'folder'    => '',
                    'name'      => $name,
                    'enabled'   => 1,
                    'locked'    => 0,
                    'namespace' => null,
                ];
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
            'library' => $this->describeLibrary($xml, $manifestPath, $namespace),
            'plugin'  => $this->describePlugin($xml, $manifestPath, $rawName, $namespace),
            default   => null,
        };
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
    private function lookup(\PDO $pdo, string $prefix, array $ext): ?array
    {
        $sql    = "SELECT extension_id, enabled, locked FROM {$prefix}extensions WHERE type = ? AND element = ?";
        $params = [$ext['type'], $ext['element']];

        if ($ext['type'] === 'plugin' && ($ext['folder'] ?? '') !== '') {
            $sql .= ' AND folder = ?';
            $params[] = $ext['folder'];
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
}
