<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * Tears one extension family off a test site so the next install starts clean.
 *
 * A repeatable install test has to begin from a known state. A stale
 * `#__extensions` row makes Joomla route a fresh install to `update()`, so the
 * "clean install" phase quietly becomes an upgrade; stale tables mask
 * `CREATE TABLE` and `ADD COLUMN` failures. Both consumers wrote this, and their
 * copies had drifted 356 differing lines apart.
 *
 * ## The retained set is declared, not inferred
 *
 * What a reset leaves behind is the load-bearing part, and it is the part
 * nobody can see. A family expressed as `element LIKE '%scripture%'` sweeps up
 * extensions its author never considered, and anything a run damages outside
 * the family persists into the next run — silently, because the output only
 * ever said how many rows went.
 *
 * So both halves are configuration and both are printed: the family that will
 * be removed, and the `retain` list that overrides it. A retained extension is
 * checked afterwards and reported as surviving, so "this survived on purpose"
 * is distinguishable from "this survived because nobody looked".
 *
 * ## Safety
 *
 * This drops tables and deletes directories. The caller is responsible for
 * passing only sites it is willing to destroy — `cwm-reset-testsite` restricts
 * itself to installs marked `role = test` in build.properties. Nothing here
 * re-checks that, because a class that silently declined to act would be worse:
 * the caller would report a clean slate it never got.
 *
 * Directory removal never follows a symlink into a directory (it unlinks the
 * link instead), so a link inside the test site cannot escape it.
 */
final class TestSiteReset
{
    /**
     * @param  array{
     *     elements?: list<string>,
     *     elementPatterns?: list<string>,
     *     tablePrefixes?: list<string>,
     *     components?: list<string>,
     *     menuLinkPatterns?: list<string>,
     *     modulePatterns?: list<string>,
     *     updateSiteNamePatterns?: list<string>,
     *     actionLogPatterns?: list<string>,
     *     directories?: list<string>,
     *     fileGlobs?: list<string>,
     *     retain?: list<string>
     * }  $plan  The project's `testSite.reset` block.
     */
    public function __construct(
        private readonly TestSite $site,
        private readonly array $plan,
    ) {
    }

    /**
     * Extensions the family matches, minus anything explicitly retained.
     *
     * Resolved as a query rather than assumed, so the caller can report exactly
     * which rows are about to go — and so a dry run is the same code path as a
     * real one up to the point of deletion.
     *
     * @return list<array{extension_id: int, type: string, element: string, folder: string}>
     */
    public function familyRows(): array
    {
        $clauses = [];
        $params  = [];

        foreach ($this->plan['elements'] ?? [] as $element) {
            $clauses[] = 'element = ?';
            $params[]  = $element;
        }

        foreach ($this->plan['elementPatterns'] ?? [] as $pattern) {
            $clauses[] = 'element LIKE ?';
            $params[]  = $pattern;
        }

        if ($clauses === []) {
            return [];
        }

        $stmt = $this->site->db()->prepare(
            'SELECT extension_id, type, element, folder FROM ' . $this->site->table('#__extensions')
            . ' WHERE ' . implode(' OR ', $clauses) . ' ORDER BY type, folder, element'
        );
        $stmt->execute($params);

        $retain = $this->plan['retain'] ?? [];
        $rows   = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            if (in_array($row['element'], $retain, true)) {
                continue;
            }

            $rows[] = [
                'extension_id' => (int) $row['extension_id'],
                'type'         => (string) $row['type'],
                'element'      => (string) $row['element'],
                'folder'       => (string) ($row['folder'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Tables the configured prefixes match, in this site's schema.
     *
     * Asked of `information_schema` rather than assumed from a list, because a
     * table added by a migration nobody remembered is exactly the residue that
     * masks a later `CREATE TABLE` failure.
     *
     * @return list<string>
     */
    public function familyTables(): array
    {
        $prefixes = $this->plan['tablePrefixes'] ?? [];

        if ($prefixes === []) {
            return [];
        }

        $clauses = [];
        $params  = [$this->schemaName()];

        foreach ($prefixes as $prefix) {
            $clauses[] = 'table_name LIKE ?';
            $params[]  = $this->site->prefix() . $prefix . '%';
        }

        $stmt = $this->site->db()->prepare(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND ('
            . implode(' OR ', $clauses) . ') ORDER BY table_name'
        );
        $stmt->execute($params);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Which retained extensions are still registered.
     *
     * Run after the reset. A retained extension that vanished means the family
     * matched more than its author thought — the failure this reports rather
     * than leaves for the next run to trip over.
     *
     * @return array<string, bool> element => still present
     */
    public function retainedStatus(): array
    {
        $status = [];

        foreach ($this->plan['retain'] ?? [] as $element) {
            // Any type: retaining is about the element surviving at all, and a
            // family pattern does not respect type boundaries either.
            $status[$element] = $this->anyTypeHolds($element);
        }

        return $status;
    }

    /**
     * Remove the family from the database.
     *
     * Deletes `#__schemas` rows before `#__extensions` rows, so a failure
     * part-way leaves an extension row without its schema row rather than a
     * schema row pointing at nothing — the former is what a reinstall repairs.
     *
     * @return array{extensions: int, schemas: int, tables: list<string>, assets: int, categories: int, menus: int, modules: int, updateSites: int, actionLogs: int}
     */
    public function purgeDatabase(): array
    {
        $db     = $this->site->db();
        $rows   = $this->familyRows();
        $ids    = array_column($rows, 'extension_id');
        $counts = [
            'extensions' => 0, 'schemas' => 0, 'tables' => [], 'assets' => 0,
            'categories' => 0, 'menus' => 0, 'modules' => 0, 'updateSites' => 0, 'actionLogs' => 0,
        ];

        if ($ids !== []) {
            $in = implode(',', array_map('intval', $ids));

            $counts['schemas']    = $db->exec('DELETE FROM ' . $this->site->table('#__schemas') . " WHERE extension_id IN ({$in})") ?: 0;
            $counts['extensions'] = $db->exec('DELETE FROM ' . $this->site->table('#__extensions') . " WHERE extension_id IN ({$in})") ?: 0;
        }

        foreach ($this->familyTables() as $table) {
            // Identifiers cannot be bound. The value came from
            // information_schema filtered by this site's own prefix, so it is
            // a table that exists here, not caller input.
            $db->exec('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table));
            $counts['tables'][] = $table;
        }

        foreach ($this->plan['components'] ?? [] as $component) {
            $stmt = $db->prepare('DELETE FROM ' . $this->site->table('#__assets') . ' WHERE name = ? OR name LIKE ?');
            $stmt->execute([$component, $component . '.%']);
            $counts['assets'] += $stmt->rowCount();

            $stmt = $db->prepare('DELETE FROM ' . $this->site->table('#__categories') . ' WHERE extension = ?');
            $stmt->execute([$component]);
            $counts['categories'] += $stmt->rowCount();
        }

        foreach ($this->plan['menuLinkPatterns'] ?? [] as $pattern) {
            $stmt = $db->prepare('DELETE FROM ' . $this->site->table('#__menu') . ' WHERE link LIKE ?');
            $stmt->execute([$pattern]);
            $counts['menus'] += $stmt->rowCount();
        }

        $counts['modules']     = $this->purgeModules();
        $counts['updateSites'] = $this->purgeUpdateSites();
        $counts['actionLogs']  = $this->purgeActionLogs();

        return $counts;
    }

    /**
     * Remove the family's installed directories from the site tree.
     *
     * Paths are resolved under the site root and refused if they escape it, so
     * a mistyped `../` in config cannot delete outside the install.
     *
     * @return array{removed: list<string>, unlinked: list<string>}
     */
    public function purgeDirectories(): array
    {
        $root      = realpath($this->site->path) ?: $this->site->path;
        $removed   = [];
        $unlinked  = [];

        foreach ($this->plan['directories'] ?? [] as $relative) {
            $path = $root . '/' . ltrim($relative, '/');

            if (is_link($path)) {
                unlink($path);
                $unlinked[] = $relative;

                continue;
            }

            if (!is_dir($path)) {
                continue;
            }

            $real = realpath($path);

            if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $this->removeDirectory($real);
            $removed[] = $relative;
        }

        return ['removed' => $removed, 'unlinked' => $unlinked];
    }

    /**
     * Remove the family's module instances and their menu assignments.
     *
     * A module's `#__extensions` row is not the module: instances live in
     * `#__modules`, and removing the extension while instances remain leaves
     * rows pointing at a module Joomla can no longer resolve. The assignments in
     * `#__modules_menu` go first, since they are keyed on the instance id.
     */
    private function purgeModules(): int
    {
        $patterns = $this->plan['modulePatterns'] ?? [];

        if ($patterns === []) {
            return 0;
        }

        $db      = $this->site->db();
        $clauses = [];
        $params  = [];

        foreach ($patterns as $pattern) {
            $clauses[] = 'module LIKE ?';
            $params[]  = $pattern;
        }

        $where = implode(' OR ', $clauses);

        $stmt = $db->prepare('SELECT id FROM ' . $this->site->table('#__modules') . " WHERE {$where}");
        $stmt->execute($params);

        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);

        if ($ids === []) {
            return 0;
        }

        $in = implode(',', $ids);
        $db->exec('DELETE FROM ' . $this->site->table('#__modules_menu') . " WHERE moduleid IN ({$in})");

        return $db->exec('DELETE FROM ' . $this->site->table('#__modules') . " WHERE id IN ({$in})") ?: 0;
    }

    /**
     * Remove the family's update sites.
     *
     * A stale `#__update_sites` row keeps Joomla polling a stream for an
     * extension that is no longer installed, and a reinstall then finds the site
     * already registered and does not re-add it — so the fresh install ends up
     * with an update site nobody wrote this run.
     */
    private function purgeUpdateSites(): int
    {
        $patterns = $this->plan['updateSiteNamePatterns'] ?? [];

        if ($patterns === []) {
            return 0;
        }

        $db      = $this->site->db();
        $clauses = [];
        $params  = [];

        foreach ($patterns as $pattern) {
            $clauses[] = 'name LIKE ?';
            $params[]  = $pattern;
        }

        $where = implode(' OR ', $clauses);

        $stmt = $db->prepare('SELECT update_site_id FROM ' . $this->site->table('#__update_sites') . " WHERE {$where}");
        $stmt->execute($params);

        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);

        if ($ids === []) {
            return 0;
        }

        $in = implode(',', $ids);
        $db->exec('DELETE FROM ' . $this->site->table('#__update_sites_extensions') . " WHERE update_site_id IN ({$in})");

        return $db->exec('DELETE FROM ' . $this->site->table('#__update_sites') . " WHERE update_site_id IN ({$in})") ?: 0;
    }

    /**
     * Remove the family's action-log registrations.
     *
     * Cosmetic next to the rest, but a duplicate row here surfaces in the admin
     * UI as a repeated entry after every reinstall, which reads as a bug in the
     * extension being tested.
     */
    private function purgeActionLogs(): int
    {
        $patterns = $this->plan['actionLogPatterns'] ?? [];

        if ($patterns === []) {
            return 0;
        }

        $db      = $this->site->db();
        $removed = 0;

        foreach ($patterns as $pattern) {
            foreach ([['#__action_logs_extensions', 'extension'], ['#__action_log_config', 'type_alias']] as [$token, $column]) {
                if (!$this->site->hasTable($token)) {
                    continue;
                }

                $stmt = $db->prepare('DELETE FROM ' . $this->site->table($token) . " WHERE {$column} LIKE ?");
                $stmt->execute([$pattern]);
                $removed += $stmt->rowCount();
            }
        }

        return $removed;
    }

    /**
     * Remove loose files the family leaves outside its own directories.
     *
     * Install manifests are the load-bearing ones: their presence makes a fresh
     * install register as an update and run ALTER SQL against tables that do not
     * exist yet, which is the failure the whole reset exists to prevent. Language
     * files and their cache are scattered across every locale, so these are
     * globs rather than a hand-maintained list that would miss a locale.
     *
     * @return list<string> Paths removed, relative to the site root.
     */
    public function purgeFiles(): array
    {
        $globs = $this->plan['fileGlobs'] ?? [];

        if ($globs === []) {
            return [];
        }

        $root    = realpath($this->site->path) ?: $this->site->path;
        $removed = [];

        foreach ($globs as $pattern) {
            foreach (glob($root . '/' . ltrim($pattern, '/')) ?: [] as $path) {
                $real = realpath($path);

                // Refuse anything resolving outside the site: a mistyped ../ in
                // config must not reach the rest of the disk.
                if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                    continue;
                }

                if (is_link($path) || !is_dir($path)) {
                    @unlink($path);
                } else {
                    $this->removeDirectory($real);
                }

                $removed[] = substr($real, strlen($root) + 1);
            }
        }

        return $removed;
    }

    /** Whether any extension row holds this element, of any type. */
    private function anyTypeHolds(string $element): bool
    {
        $stmt = $this->site->db()->prepare(
            'SELECT 1 FROM ' . $this->site->table('#__extensions') . ' WHERE element = ?'
        );
        $stmt->execute([$element]);

        return $stmt->fetchColumn() !== false;
    }

    /** The schema `information_schema` queries filter on, per driver. */
    private function schemaName(): string
    {
        if ($this->site->isPostgres()) {
            $current = $this->site->db()->query('SELECT current_schema()')?->fetchColumn();

            return is_string($current) ? $current : 'public';
        }

        if ($this->site->database() !== '') {
            return $this->site->database();
        }

        $current = $this->site->db()->query('SELECT DATABASE()')?->fetchColumn();

        return is_string($current) ? $current : '';
    }

    /** Quote a table identifier for the site's driver. */
    private function quoteIdentifier(string $name): string
    {
        $clean = str_replace(['`', '"'], '', $name);

        return $this->site->isPostgres() ? '"' . $clean . '"' : '`' . $clean . '`';
    }

    /**
     * Delete a directory tree.
     *
     * Symlinked directories are unlinked rather than recursed into — the guard
     * that stops a link inside the site escaping it. Same posture as
     * `removeDirectory()` in the clean command.
     */
    private function removeDirectory(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;

            if (is_link($path) || !is_dir($path)) {
                @unlink($path);

                continue;
            }

            $this->removeDirectory($path);
        }

        @rmdir($dir);
    }
}
