<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\TestSite;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * hasTable(), hasColumn() and hasIndex() against a real MySQL server.
 *
 * These three read `information_schema`, which the in-memory SQLite the rest of
 * the suite uses does not have — so until this file they had no coverage at all,
 * while Proclaim and CWMLivingWord came to depend on them for deciding whether a
 * migration landed, whether a table survived an uninstall, and whether an index
 * was created. `hasIndex()` shipped verified only by hand against one live site.
 *
 * ## Tables, not databases
 *
 * Everything is created inside whatever database the DSN names, under a
 * `cwmtest_` prefix, and dropped again in tearDown(). Creating a database per
 * run would need a privilege a developer's local root may not have, and would
 * make a mistake in this file cost a schema rather than three tables.
 *
 * ## Skipping locally, failing in CI
 *
 * Without CWM_TEST_MYSQL_DSN this file skips, so `composer test` still works on
 * a machine with no MySQL. In CI it fails instead: a suite that silently covers
 * nothing reads exactly like one that passes, which is the defect this project
 * has now found in five different harnesses.
 */
final class TestSiteIntrospectionTest extends TestCase
{
    private const PREFIX = 'cwmtest_';

    private ?PDO $pdo = null;

    private TestSite $site;

    protected function setUp(): void
    {
        $dsn = getenv('CWM_TEST_MYSQL_DSN');

        if ($dsn === false || $dsn === '') {
            if (getenv('CI') !== false && getenv('CI') !== '') {
                self::fail(
                    'CWM_TEST_MYSQL_DSN is not set. In CI this is a failure rather than a skip: '
                    . 'these are the only tests covering hasTable/hasColumn/hasIndex, and a silent '
                    . 'skip would leave the suite green while covering nothing.'
                );
            }

            self::markTestSkipped('Set CWM_TEST_MYSQL_DSN to run the MySQL introspection tests.');
        }

        $this->pdo = new PDO(
            $dsn,
            getenv('CWM_TEST_MYSQL_USER') ?: 'root',
            getenv('CWM_TEST_MYSQL_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $this->dropFixtures();

        // The table under test.
        $this->pdo->exec(
            'CREATE TABLE ' . self::PREFIX . 'bsms_studies (
                id INT PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(100),
                book_nr INT,
                chapter INT,
                reference VARCHAR(50),
                INDEX idx_title (title),
                INDEX idx_book_chapter (book_nr, chapter),
                UNIQUE INDEX uniq_reference (reference)
            )'
        );

        // A second table carrying an index of the SAME name, so a check that
        // forgets to bind the table name resolves the wrong one and passes.
        $this->pdo->exec(
            'CREATE TABLE ' . self::PREFIX . 'other (
                id INT PRIMARY KEY,
                title VARCHAR(100),
                INDEX idx_title (title)
            )'
        );

        // ⚠️ A decoy: `SHOW TABLES LIKE 'cwmtest_bsms_studies'` matches this,
        // because `_` is a single-character wildcard and the name is the same
        // length. That is the bug hasTable() exists to not have.
        $this->pdo->exec('CREATE TABLE cwmtestXbsmsXstudies (id INT PRIMARY KEY)');

        $this->site = TestSite::fromPdo($this->pdo, self::PREFIX);
    }

    protected function tearDown(): void
    {
        $this->dropFixtures();
        $this->pdo = null;
    }

    private function dropFixtures(): void
    {
        foreach ([self::PREFIX . 'bsms_studies', self::PREFIX . 'other', 'cwmtestXbsmsXstudies'] as $table) {
            $this->pdo?->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    #[Test]
    public function finds_a_table_that_is_there(): void
    {
        self::assertTrue($this->site->hasTable('#__bsms_studies'));
    }

    #[Test]
    public function does_not_find_a_table_that_is_not(): void
    {
        self::assertFalse($this->site->hasTable('#__no_such_table'));
    }

    #[Test]
    public function matches_the_name_exactly_rather_than_as_a_like_pattern(): void
    {
        // The whole reason this is not `SHOW TABLES LIKE`. MySQL agrees the
        // decoy matches the pattern; hasTable() must still say no.
        $matches = $this->pdo->query(
            "SELECT 'cwmtestXbsmsXstudies' LIKE '" . self::PREFIX . "bsms_studies'"
        )->fetchColumn();

        self::assertEquals(1, $matches, 'the decoy does match a LIKE pattern — otherwise this proves nothing');
        self::assertTrue($this->site->hasTable('#__bsms_studies'));
        self::assertFalse($this->site->hasTable('#__no_such_table'));

        // And the decoy itself is not reachable through the prefixed token.
        self::assertFalse($this->site->hasTable('#__bsmsXstudies'));
    }

    #[Test]
    public function finds_a_column_that_is_there(): void
    {
        self::assertTrue($this->site->hasColumn('#__bsms_studies', 'title'));
        self::assertTrue($this->site->hasColumn('#__bsms_studies', 'book_nr'));
    }

    #[Test]
    public function does_not_find_a_column_that_is_not(): void
    {
        self::assertFalse($this->site->hasColumn('#__bsms_studies', 'no_such_column'));
    }

    #[Test]
    public function a_column_is_scoped_to_its_table(): void
    {
        // book_nr exists on bsms_studies and not on other. A check that binds
        // only the column name answers true for both.
        self::assertTrue($this->site->hasColumn('#__bsms_studies', 'book_nr'));
        self::assertFalse($this->site->hasColumn('#__other', 'book_nr'));
    }

    #[Test]
    public function a_column_on_a_missing_table_is_absent_not_an_error(): void
    {
        self::assertFalse($this->site->hasColumn('#__no_such_table', 'id'));
    }

    #[Test]
    public function finds_the_primary_key_and_a_single_column_index(): void
    {
        self::assertTrue($this->site->hasIndex('#__bsms_studies', 'PRIMARY'));
        self::assertTrue($this->site->hasIndex('#__bsms_studies', 'idx_title'));
    }

    #[Test]
    public function finds_a_composite_index(): void
    {
        // information_schema.statistics holds one row per column, so a
        // multi-column index is two rows. The query has to answer "yes" from
        // the first of them rather than tripping over the second.
        $rows = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = '" . self::PREFIX . "bsms_studies'
               AND index_name = 'idx_book_chapter'"
        )->fetchColumn();

        self::assertEquals(2, $rows, 'the composite index should be two rows — otherwise this proves nothing');
        self::assertTrue($this->site->hasIndex('#__bsms_studies', 'idx_book_chapter'));
    }

    #[Test]
    public function finds_a_unique_index(): void
    {
        // hasIndex() answers presence, not shape: UNIQUE is still an index.
        self::assertTrue($this->site->hasIndex('#__bsms_studies', 'uniq_reference'));
    }

    #[Test]
    public function does_not_find_an_index_that_is_not_there(): void
    {
        self::assertFalse($this->site->hasIndex('#__bsms_studies', 'idx_nothing'));
    }

    #[Test]
    public function an_index_is_scoped_to_its_table(): void
    {
        // Both tables carry an index called idx_title; only bsms_studies
        // carries idx_book_chapter. Without binding the table name, the second
        // assertion answers true.
        self::assertTrue($this->site->hasIndex('#__other', 'idx_title'));
        self::assertFalse($this->site->hasIndex('#__other', 'idx_book_chapter'));
    }

    #[Test]
    public function an_index_on_a_missing_table_is_absent_not_an_error(): void
    {
        self::assertFalse($this->site->hasIndex('#__no_such_table', 'PRIMARY'));
    }

    #[Test]
    public function the_prefix_is_applied_to_every_introspection(): void
    {
        // Passing an already-expanded name resolves the same way a token does,
        // which is what lets callers hold either.
        self::assertTrue($this->site->hasTable(self::PREFIX . 'bsms_studies'));
        self::assertTrue($this->site->hasColumn(self::PREFIX . 'bsms_studies', 'title'));
        self::assertTrue($this->site->hasIndex(self::PREFIX . 'bsms_studies', 'idx_title'));
    }
}
