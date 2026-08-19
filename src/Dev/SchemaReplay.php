<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Replay an extension's migration files against a scratch schema.
 *
 * ## The gap this fills
 *
 * Nothing else in the release gate ever *executes* a migration file.
 *
 * A fresh install runs `install.sql` and Joomla then stamps `#__schemas` at the
 * newest version without running a single update file — so `test-install.sh`,
 * `verify-migrations.php` and every other check that starts from a fresh site
 * proves the *destination* schema is right while the files that get a real
 * site there have never been run. `verify-schema-check.php` is closer but
 * still only reads: Joomla's ChangeSet derives a check query per statement and
 * compares it against the current schema, which is a different question from
 * "does this statement apply".
 *
 * The consequence is that a migration can ship with a syntax error, a
 * reference to a table a later version dropped, or a column type the server
 * rejects, and every gate stays green — because the only machine that ever
 * runs it is a user's, mid-upgrade.
 *
 * ## Prefixed tables in a shared scratch schema, not a database per run
 *
 * Everything is created under a prefix inside whatever database the DSN names,
 * and dropped again afterwards. Creating a database would need a privilege a
 * developer's local root may not have, and would make a mistake here cost a
 * schema rather than a set of tables. Same posture as the introspection tests.
 *
 * ## Fidelity to the installer
 *
 * Statement splitting, the `/** CAN FAIL *\/` marker and prefix substitution
 * all go through {@see SqlSplitter}, which ports Joomla's own implementations
 * rather than approximating them. File ordering and the skip rule go through
 * {@see MigrationSequence}. A harness that splits, orders or substitutes
 * differently from the installer is testing a program nobody runs.
 *
 * Two divergences from `Installer::parseSchemaUpdates()`, both because a
 * scratch replay has no site:
 *
 * - the starting version is configured rather than read from
 *   `#__schemas.version_id`
 * - `#__schemas` is not stamped after each file (`updateSchemaTable()`), since
 *   there is no extension row to stamp
 *
 * Neither changes which files run or in what order.
 */
final class SchemaReplay
{
    /**
     * @param PDO    $pdo    Connection to the scratch database.
     * @param string $prefix Table prefix every replayed object is created under.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix = 'cwmreplay_',
    ) {
        if ($this->prefix === '' || preg_match('/^[A-Za-z0-9_]+$/', $this->prefix) !== 1) {
            // The prefix is interpolated into DROP TABLE, so it is constrained
            // rather than escaped. Config is trusted here, but a typo that made
            // it empty would turn teardown into "drop everything".
            throw new RuntimeException(sprintf(
                'Replay prefix must be non-empty and match [A-Za-z0-9_]+, got "%s".',
                $this->prefix,
            ));
        }

        // A DSN with no `dbname` connects happily and then DATABASE() is NULL.
        // Everything downstream degrades quietly rather than failing: tables()
        // filters on TABLE_SCHEMA = DATABASE() and finds nothing, so teardown
        // reports dropping zero tables and leaves the previous run's tables in
        // place. The next run's CREATE TABLE then fails, blaming the baseline
        // for a mistake in the DSN.
        //
        // Refusing here costs one query and makes the DSN the thing the error
        // names.
        if ($this->pdo->query('SELECT DATABASE()')?->fetchColumn() === null) {
            throw new RuntimeException(
                'The connection has no default database — add `dbname=` to the DSN. '
                . 'Without it nothing can be created and teardown would silently drop nothing.'
            );
        }
    }

    /**
     * The prefix replayed tables are created under.
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Apply one SQL file, returning what happened.
     *
     * @param  string $path  Absolute path to a `.sql` file.
     * @param  string $label How to name it in output — the version, usually.
     * @return array{label: string, statements: int, tolerated: int, error: array{statement: string, message: string}|null}
     */
    public function apply(string $path, string $label): array
    {
        $sql = @file_get_contents($path);

        if ($sql === false) {
            throw new RuntimeException(sprintf('Cannot read SQL file: %s', $path));
        }

        $statements = 0;
        $tolerated  = 0;

        foreach (SqlSplitter::split($sql) as $raw) {
            $canFail = SqlSplitter::canFail($raw);
            $query   = SqlSplitter::replacePrefix(SqlSplitter::strip($raw), $this->prefix);

            try {
                $this->execute($query);
                $statements++;
            } catch (PDOException $e) {
                if ($canFail) {
                    // Joomla runs these and shrugs. Counting them separately
                    // keeps that visible rather than silent — a file whose
                    // every statement is tolerated has proved nothing.
                    $tolerated++;

                    continue;
                }

                return [
                    'label'      => $label,
                    'statements' => $statements,
                    'tolerated'  => $tolerated,
                    'error'      => [
                        'statement' => $this->excerpt($query),
                        'message'   => $e->getMessage(),
                    ],
                ];
            }
        }

        return [
            'label'      => $label,
            'statements' => $statements,
            'tolerated'  => $tolerated,
            'error'      => null,
        ];
    }

    /**
     * Apply the baseline, then every migration a site at that version would run.
     *
     * Stops at the first failing file, because everything after it would be
     * running against a schema no site has.
     *
     * ## Why the baseline is a list
     *
     * An extension's own install SQL is not a site. Proclaim's migrations write
     * to `#__action_log_config`, `#__action_logs_extensions`, `#__assets` and
     * `#__schemas` — all Joomla core tables that no extension manifest creates.
     * Replaying against the extension's tables alone fails on the first such
     * statement, for a reason that says nothing about the migration.
     *
     * So the baseline is however many files it takes to describe the site a
     * migration actually lands on: the core schema, then the extension's, in
     * the order given.
     *
     * @param  list<string>      $baselinePaths Absolute paths, applied in order.
     * @param  string            $from          Version the baseline represents.
     * @return list<array{label: string, statements: int, tolerated: int, error: array{statement: string, message: string}|null}>
     */
    public function replay(array $baselinePaths, MigrationSequence $sequence, string $from): array
    {
        $results = [];

        foreach ($baselinePaths as $path) {
            $result    = $this->apply($path, sprintf('baseline %s (%s)', basename($path), $from));
            $results[] = $result;

            if ($result['error'] !== null) {
                return $results;
            }
        }

        foreach ($sequence->after($from) as $version) {
            $result    = $this->apply($sequence->fileFor($version), $version);
            $results[] = $result;

            if ($result['error'] !== null) {
                break;
            }
        }

        return $results;
    }

    /**
     * Drop every table under the replay prefix.
     *
     * Scoped by prefix and nothing else — this never issues DROP DATABASE, so
     * pointing it at the wrong DSN costs the replay's own tables rather than
     * someone's schema.
     *
     * @return int Number of tables dropped.
     */
    public function teardown(): int
    {
        $dropped = 0;

        // FOREIGN_KEY_CHECKS is off for the duration so tables can go in any
        // order; a migration that added a constraint would otherwise make
        // teardown order-dependent and leave residue behind on failure.
        $this->execute('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($this->tables() as $table) {
                $this->execute(sprintf('DROP TABLE IF EXISTS `%s`', $table));
                $dropped++;
            }
        } finally {
            $this->execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $dropped;
    }

    /**
     * Tables currently present under the replay prefix.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        // LIKE with the wildcards escaped: `_` matches any single character,
        // so an unescaped `cwmreplay_%` would also match `cwmreplayX...`.
        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ESCAPE ?'
        );
        $stmt->execute([str_replace(['_', '%'], ['!_', '!%'], $this->prefix) . '%', '!']);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Run one statement, including ones that return rows.
     *
     * Not `PDO::exec()`. A migration file is not all DDL: `SELECT 1;` is the
     * conventional no-op placeholder for a migration whose work moved
     * elsewhere, and six of Proclaim's contain a SELECT of some kind. `exec()`
     * runs those but never fetches, leaving an open result set that makes the
     * *next* statement fail with "Cannot execute queries while other
     * unbuffered queries are active" — a failure reported against an innocent
     * statement in a later file.
     *
     * `query()` plus `closeCursor()` consumes the result and frees the
     * connection, so a SELECT in a migration behaves the way it does under the
     * installer: it runs, and nothing downstream notices.
     */
    private function execute(string $query): void
    {
        $statement = $this->pdo->query($query);

        if ($statement !== false) {
            $statement->closeCursor();
        }
    }

    /**
     * A one-line excerpt of a statement, for an error message.
     *
     * Same shape the installer logs: newlines flattened, first 80 characters.
     */
    private function excerpt(string $query): string
    {
        return trim(str_replace(["\r", "\n"], ['', ' '], substr($query, 0, 80)));
    }
}
