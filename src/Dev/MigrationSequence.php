<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

use RuntimeException;
use SimpleXMLElement;

/**
 * Which migration files run, in which order, for a given starting version.
 *
 * This is the half of Joomla's `Installer::parseSchemaUpdates()` that decides
 * *what* to run, separated from executing it so it can be tested without a
 * database. {@see SchemaReplay} does the executing.
 *
 * ## Order is version_compare, not alphabetical
 *
 * The installer strips `.sql`, sorts with `usort($files, 'version_compare')`,
 * and skips every file that is `<=` the version recorded in `#__schemas`.
 * Reproducing that exactly is the whole point of this class: sorting
 * Proclaim's files as strings puts `10.10.0` before `10.5.8`, which would
 * replay them in an order no site has ever experienced and report failures
 * that cannot happen in the field.
 *
 * `version_compare` is what makes the date-suffixed names Proclaim uses work —
 * `10.1.0-20260207` sorts below `10.1.0-20260208`, and both below `10.1.1`.
 *
 * ## `<schemapath type="mysql">`, and the aliases
 *
 * The installer maps driver names before matching: `mysqli` and `pdomysql` both
 * mean `mysql`, and `pgsql` means `postgresql`. A manifest declaring
 * `type="mysqli"` is matched by a MySQL site, so it is matched here too.
 */
final class MigrationSequence
{
    /**
     * @param string       $schemaPath Absolute path to the directory of `.sql` migrations.
     * @param list<string> $versions   Migration versions (no `.sql`), in installer order.
     * @param string|null  $installSql Absolute path to the install SQL, when the manifest declares one.
     */
    private function __construct(
        private readonly string $schemaPath,
        private readonly array $versions,
        private readonly ?string $installSql,
    ) {
    }

    /**
     * Build the sequence a manifest declares.
     *
     * @param  string $manifestPath Absolute path to the extension manifest XML.
     * @param  string $driver       Server type as Joomla reports it — `mysql`.
     * @throws RuntimeException     When the manifest is unreadable or declares no schemapath.
     */
    public static function fromManifest(string $manifestPath, string $driver = 'mysql'): self
    {
        if (!is_file($manifestPath)) {
            throw new RuntimeException(sprintf('Manifest not found: %s', $manifestPath));
        }

        // No LIBXML_NOENT / LIBXML_DTDLOAD — those re-enable external entity
        // expansion. libxml >= 2.9 disables it by default and it stays that way.
        $xml = simplexml_load_file($manifestPath);

        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException(sprintf('Manifest is not valid XML: %s', $manifestPath));
        }

        $base       = \dirname($manifestPath);
        $schemaPath = self::schemaPath($xml, $driver);

        if ($schemaPath === null) {
            throw new RuntimeException(sprintf(
                'Manifest declares no <schemapath type="%s">: %s',
                $driver,
                $manifestPath,
            ));
        }

        $dir = $base . '/' . $schemaPath;

        if (!is_dir($dir)) {
            throw new RuntimeException(sprintf('Schema path does not exist: %s', $dir));
        }

        $install = self::declaredInstallSql($xml, $driver);

        return new self(
            $dir,
            self::order(self::sqlFilesIn($dir)),
            $install === null ? null : $base . '/' . $install,
        );
    }

    /**
     * Every migration version the extension ships, in installer order.
     *
     * @return list<string>
     */
    public function versions(): array
    {
        return $this->versions;
    }

    /**
     * The migrations a site at `$from` would run, in order.
     *
     * `$from` is what `#__schemas.version_id` holds. The installer uses
     * `0.0.0` when there is no row, and that is the right value for replaying
     * everything from the beginning.
     *
     * @return list<string>
     */
    public function after(string $from): array
    {
        return array_values(array_filter(
            $this->versions,
            static fn(string $v): bool => version_compare($v, $from) > 0,
        ));
    }

    /**
     * Absolute path to the file a version came from.
     */
    public function fileFor(string $version): string
    {
        return $this->schemaPath . '/' . $version . '.sql';
    }

    /**
     * Absolute path to the install SQL, or null when the manifest declares none.
     */
    public function installSql(): ?string
    {
        return $this->installSql;
    }

    /**
     * Directory the migrations live in.
     */
    public function schemaPathDir(): string
    {
        return $this->schemaPath;
    }

    /**
     * Sort migration basenames the way the installer does.
     *
     * Pure, and public because it is the part most worth testing directly:
     * `.sql` stripped, then `usort(..., 'version_compare')`.
     *
     * @param  list<string> $filenames Basenames, with or without `.sql`.
     * @return list<string>            Versions, no extension, in run order.
     */
    public static function order(array $filenames): array
    {
        $versions = array_map(
            static fn(string $f): string => str_replace('.sql', '', $f),
            $filenames,
        );

        usort($versions, 'version_compare');

        return array_values($versions);
    }

    /**
     * `.sql` basenames in a directory.
     *
     * @return list<string>
     */
    private static function sqlFilesIn(string $dir): array
    {
        $found = [];

        foreach ((array) scandir($dir) as $entry) {
            if (\is_string($entry) && preg_match('/\.sql$/i', $entry) === 1) {
                $found[] = $entry;
            }
        }

        return $found;
    }

    /**
     * The `<schemapath>` matching this driver, applying the installer's aliases.
     */
    private static function schemaPath(SimpleXMLElement $xml, string $driver): ?string
    {
        foreach ($xml->xpath('//schemas/schemapath') ?: [] as $entry) {
            if (self::normaliseDriver((string) ($entry['type'] ?? '')) === $driver) {
                return trim((string) $entry);
            }
        }

        return null;
    }

    /**
     * The `<sql><file driver="...">` matching this driver.
     */
    private static function declaredInstallSql(SimpleXMLElement $xml, string $driver): ?string
    {
        foreach ($xml->xpath('//install/sql/file') ?: [] as $entry) {
            if (self::normaliseDriver((string) ($entry['driver'] ?? '')) === $driver) {
                return trim((string) $entry);
            }
        }

        return null;
    }

    /**
     * Map a manifest driver name onto the server type Joomla compares against.
     *
     * Same aliases as `Installer::parseSchemaUpdates()`.
     */
    private static function normaliseDriver(string $type): string
    {
        return match (strtolower($type)) {
            'mysqli', 'pdomysql' => 'mysql',
            'pgsql'              => 'postgresql',
            default              => strtolower($type),
        };
    }
}
