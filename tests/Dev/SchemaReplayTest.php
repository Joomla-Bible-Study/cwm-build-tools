<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\MigrationSequence;
use CWM\BuildTools\Dev\SchemaReplay;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Replaying migrations against a real MySQL server.
 *
 * ## Why this needs a real server
 *
 * The whole point of the class is that a statement is *executed*. SQLite would
 * accept and reject a different set of statements from the engine consumers
 * run, so a passing SQLite test would be evidence about nothing.
 *
 * ## Tables, not databases
 *
 * Everything is created under a prefix inside the database the DSN names and
 * dropped again in tearDown(). Same posture as TestSiteIntrospectionTest: no
 * CREATE DATABASE, so a mistake here costs a handful of tables rather than a
 * schema.
 *
 * ## Skipping locally, failing in CI
 *
 * Without CWM_TEST_MYSQL_DSN this file skips so `composer test` still works on
 * a machine with no MySQL. In CI it fails instead — a suite that silently
 * covers nothing reads exactly like one that passes.
 */
final class SchemaReplayTest extends TestCase
{
    private const PREFIX = 'cwmreplaytest_';

    private PDO $pdo;

    private string $work;

    protected function setUp(): void
    {
        $dsn = getenv('CWM_TEST_MYSQL_DSN');

        if ($dsn === false || $dsn === '') {
            if (getenv('CI') !== false && getenv('CI') !== '') {
                self::fail(
                    'CWM_TEST_MYSQL_DSN is not set. In CI this is a failure rather than a skip: '
                    . 'these are the only tests that execute a migration, and a silent skip would '
                    . 'leave the suite green while covering nothing.'
                );
            }

            self::markTestSkipped('Set CWM_TEST_MYSQL_DSN to run the schema replay tests.');
        }

        $this->pdo = new PDO(
            $dsn,
            getenv('CWM_TEST_MYSQL_USER') ?: 'root',
            getenv('CWM_TEST_MYSQL_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $this->work = sys_get_temp_dir() . '/cwm-replay-' . getmypid();
        @mkdir($this->work . '/sql/updates/mysql', 0o777, true);

        (new SchemaReplay($this->pdo, self::PREFIX))->teardown();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            (new SchemaReplay($this->pdo, self::PREFIX))->teardown();
        }

        if (isset($this->work) && is_dir($this->work)) {
            foreach ((array) glob($this->work . '/sql/updates/mysql/*') as $f) {
                @unlink((string) $f);
            }

            @unlink($this->work . '/sql/baseline.sql');
            @unlink($this->work . '/manifest.xml');
            @rmdir($this->work . '/sql/updates/mysql');
            @rmdir($this->work . '/sql/updates');
            @rmdir($this->work . '/sql');
            @rmdir($this->work);
        }
    }

    #[Test]
    public function it_applies_a_baseline(): void
    {
        $replay = new SchemaReplay($this->pdo, self::PREFIX);
        $result = $replay->apply($this->baseline(), 'baseline');

        self::assertNull($result['error']);
        self::assertSame(1, $result['statements']);
        self::assertContains(self::PREFIX . 'items', $replay->tables());
    }

    #[Test]
    public function it_replays_migrations_in_order(): void
    {
        // b depends on a: if the order were wrong this fails, which is a
        // stronger check than asserting a sorted list.
        $this->migration('1.1.0', 'ALTER TABLE `#__items` ADD `a` INT NULL;');
        $this->migration('1.2.0', 'ALTER TABLE `#__items` ADD `b` INT NULL AFTER `a`;');

        $results = (new SchemaReplay($this->pdo, self::PREFIX))
            ->replay([$this->baseline()], $this->sequence(), '1.0.0');

        self::assertCount(3, $results);
        self::assertNull($results[2]['error']);
    }

    #[Test]
    public function it_skips_migrations_at_or_below_the_starting_version(): void
    {
        // 1.1.0 would fail if it ran — the column already exists in the
        // baseline. A site that has already had it must not run it again.
        $this->migration('1.1.0', 'ALTER TABLE `#__items` ADD `title` VARCHAR(10) NULL;');
        $this->migration('1.2.0', 'ALTER TABLE `#__items` ADD `fresh` INT NULL;');

        $results = (new SchemaReplay($this->pdo, self::PREFIX))
            ->replay([$this->baseline()], $this->sequence(), '1.1.0');

        self::assertCount(2, $results);
        self::assertSame('1.2.0', $results[1]['label']);
    }

    #[Test]
    public function it_reports_the_file_and_statement_that_failed(): void
    {
        // A column added with no type: a real syntax error, and one a review
        // can miss because it reads almost like the statement above it.
        $this->migration('1.1.0', 'ALTER TABLE `#__items` ADD `no_type_given`;');

        $results = (new SchemaReplay($this->pdo, self::PREFIX))
            ->replay([$this->baseline()], $this->sequence(), '1.0.0');

        $last = $results[count($results) - 1];

        self::assertSame('1.1.0', $last['label']);
        self::assertNotNull($last['error']);
        self::assertStringContainsString('no_type_given', $last['error']['statement']);
    }

    #[Test]
    public function it_stops_at_the_first_failure(): void
    {
        // Everything after a failure would run against a schema no site has,
        // so continuing would report failures that mean nothing.
        $this->migration('1.1.0', 'THIS IS NOT SQL;');
        $this->migration('1.2.0', 'ALTER TABLE `#__items` ADD `later` INT NULL;');

        $results = (new SchemaReplay($this->pdo, self::PREFIX))
            ->replay([$this->baseline()], $this->sequence(), '1.0.0');

        self::assertCount(2, $results);
        self::assertSame('1.1.0', $results[1]['label']);
    }

    #[Test]
    public function it_tolerates_a_can_fail_statement(): void
    {
        // Joomla runs these and shrugs when they fail. A harness that did not
        // would report failures production tolerates.
        $this->migration('1.1.0', "ALTER TABLE `#__items` DROP INDEX `never_existed` /** CAN FAIL **/;");

        $results = (new SchemaReplay($this->pdo, self::PREFIX))
            ->replay([$this->baseline()], $this->sequence(), '1.0.0');

        self::assertNull($results[1]['error']);
        self::assertSame(1, $results[1]['tolerated']);
        self::assertSame(0, $results[1]['statements']);
    }

    #[Test]
    public function a_select_in_a_migration_does_not_break_the_next_statement(): void
    {
        // "SELECT 1;" is the conventional no-op placeholder for a migration
        // whose work moved elsewhere, and six of Proclaim's files contain a
        // SELECT. Running one with PDO::exec() leaves an open result set and
        // the NEXT statement fails — an error reported against an innocent
        // file, which is exactly the class of defect this tool exists to catch.
        $this->migration('1.1.0', 'SELECT 1;');
        $this->migration('1.2.0', 'ALTER TABLE `#__items` ADD `after_select` INT NULL;');

        $results = (new SchemaReplay($this->pdo, self::PREFIX))
            ->replay([$this->baseline()], $this->sequence(), '1.0.0');

        self::assertNull($results[2]['error']);
    }

    #[Test]
    public function it_applies_several_baseline_files_in_order(): void
    {
        // The second references a table the first creates, so a wrong order
        // fails rather than merely looking different.
        file_put_contents(
            $this->work . '/sql/second.sql',
            'ALTER TABLE `#__items` ADD `from_second` INT NULL;',
        );

        $results = (new SchemaReplay($this->pdo, self::PREFIX))
            ->replay([$this->baseline(), $this->work . '/sql/second.sql'], $this->sequence(), '9.9.9');

        self::assertNull($results[0]['error']);
        self::assertNull($results[1]['error']);

        @unlink($this->work . '/sql/second.sql');
    }

    #[Test]
    public function teardown_drops_only_tables_under_the_prefix(): void
    {
        $replay = new SchemaReplay($this->pdo, self::PREFIX);
        $replay->apply($this->baseline(), 'baseline');

        // A decoy whose name would be matched by an unescaped LIKE, since `_`
        // is a single-character wildcard: cwmreplaytest_% also matches
        // cwmreplaytestX...
        $decoy = str_replace('_', 'X', self::PREFIX) . 'decoy';
        $this->pdo->exec(sprintf('CREATE TABLE `%s` (id INT)', $decoy));

        try {
            self::assertGreaterThan(0, $replay->teardown());
            self::assertSame([], $replay->tables());

            $found = $this->pdo->query(
                sprintf("SHOW TABLES LIKE %s", $this->pdo->quote($decoy))
            );

            self::assertNotFalse($found?->fetchColumn(), 'the decoy table was dropped');
        } finally {
            $this->pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $decoy));
        }
    }

    #[Test]
    public function it_refuses_an_empty_prefix(): void
    {
        // The prefix is interpolated into DROP TABLE. An empty one would turn
        // teardown into "drop everything in this database".
        $this->expectException(RuntimeException::class);

        new SchemaReplay($this->pdo, '');
    }

    #[Test]
    public function it_refuses_a_prefix_with_punctuation(): void
    {
        $this->expectException(RuntimeException::class);

        new SchemaReplay($this->pdo, 'bad`prefix');
    }

    /**
     * Write the fixture baseline and return its path.
     */
    private function baseline(): string
    {
        $path = $this->work . '/sql/baseline.sql';

        file_put_contents(
            $path,
            "CREATE TABLE `#__items` (\n"
            . "  `id` INT NOT NULL AUTO_INCREMENT,\n"
            . "  `title` VARCHAR(10) NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB;\n",
        );

        return $path;
    }

    /**
     * Write one migration file into the fixture schemapath.
     */
    private function migration(string $version, string $sql): void
    {
        file_put_contents($this->work . '/sql/updates/mysql/' . $version . '.sql', $sql . "\n");
    }

    /**
     * A sequence over whatever migrations this test has written.
     */
    private function sequence(): MigrationSequence
    {
        file_put_contents($this->work . '/manifest.xml', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <extension type="component" method="upgrade">
                <name>com_replayfixture</name>
                <update>
                    <schemas>
                        <schemapath type="mysql">sql/updates/mysql</schemapath>
                    </schemas>
                </update>
            </extension>
            XML);

        return MigrationSequence::fromManifest($this->work . '/manifest.xml');
    }
}
