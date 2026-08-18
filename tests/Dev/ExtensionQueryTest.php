<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\ExtensionQuery;
use CWM\BuildTools\Dev\TestSite;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Extension presence queries, run against a real (in-memory) database.
 *
 * Asserting on generated SQL would pass for a query that never matches
 * anything. The rows below are what #__extensions actually holds, including the
 * two collisions that make `folder` and `client_id` load-bearing: two plugins
 * sharing an element in different groups, and a module present as both site and
 * administrator.
 */
final class ExtensionQueryTest extends TestCase
{
    private ExtensionQuery $query;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $pdo->exec(
            'CREATE TABLE jos_extensions (
                extension_id INTEGER PRIMARY KEY,
                type TEXT, element TEXT, folder TEXT, client_id INTEGER,
                enabled INTEGER, manifest_cache TEXT
            )'
        );

        $rows = [
            [1, 'component', 'com_proclaim', '', 1, 1, '{"version":"10.5.9"}'],
            [2, 'library', 'cwmscripture', '', 0, 1, '{"version":"2.1.0"}'],
            // Same element, different groups — the collision folder exists for.
            [3, 'plugin', 'cwmscripture', 'system', 0, 1, '{"version":"2.1.0"}'],
            [4, 'plugin', 'cwmscripture', 'task', 0, 0, '{"version":"2.0.0"}'],
            // Same element, both clients — the collision client_id exists for.
            [5, 'module', 'mod_proclaim', '', 0, 1, '{"version":"10.5.9"}'],
            [6, 'module', 'mod_proclaim', '', 1, 1, '{"version":"10.5.9"}'],
            [7, 'plugin', 'brokencache', 'system', 0, 1, 'not json'],
        ];

        $stmt = $pdo->prepare('INSERT INTO jos_extensions VALUES (?, ?, ?, ?, ?, ?, ?)');

        foreach ($rows as $row) {
            $stmt->execute($row);
        }

        // #__schemas holds the highest schema file Joomla actually ran. Row 1 is
        // deliberately behind its manifest_cache version (10.5.9 vs 10.5.7):
        // files copied, SQL did not run. Row 2 has no schema row at all, which
        // is normal for an extension shipping no SQL and must not read as
        // failure.
        $pdo->exec('CREATE TABLE jos_schemas (extension_id INTEGER, version_id TEXT)');
        $pdo->exec("INSERT INTO jos_schemas VALUES (1, '10.5.7'), (3, '2.1.0')");

        $this->query = new ExtensionQuery(TestSite::fromPdo($pdo, 'jos_'));
    }

    #[Test]
    public function finds_an_extension_by_type_and_element(): void
    {
        self::assertSame(1, $this->query->id('component', 'com_proclaim'));
    }

    #[Test]
    public function absent_extensions_report_null_rather_than_throwing(): void
    {
        self::assertNull($this->query->id('component', 'com_nothing'));
        self::assertFalse($this->query->exists('component', 'com_nothing'));
    }

    #[Test]
    public function folder_distinguishes_two_plugins_sharing_an_element(): void
    {
        // The defect #102 names: a probe omitting folder finds whichever row the
        // server returns first and reports confidently about the wrong one.
        self::assertSame(3, $this->query->id('plugin', 'cwmscripture', 'system'));
        self::assertSame(4, $this->query->id('plugin', 'cwmscripture', 'task'));
    }

    #[Test]
    public function omitting_folder_asks_a_different_question_than_matching_empty(): void
    {
        // "Is element x installed anywhere" must not be answered by
        // `folder = ''`, or every plugin looks absent.
        self::assertTrue($this->query->exists('plugin', 'cwmscripture'));
        self::assertNull($this->query->id('plugin', 'cwmscripture', ''));
    }

    #[Test]
    public function client_id_distinguishes_site_from_administrator(): void
    {
        self::assertSame(5, $this->query->id('module', 'mod_proclaim', null, 0));
        self::assertSame(6, $this->query->id('module', 'mod_proclaim', null, 1));
    }

    #[Test]
    public function a_library_and_a_plugin_sharing_an_element_are_not_confused(): void
    {
        self::assertSame(2, $this->query->id('library', 'cwmscripture'));
    }

    #[Test]
    public function enabled_is_distinct_from_installed(): void
    {
        // A disabled extension IS installed. Conflating the two reports a failed
        // install for a site where somebody switched something off.
        self::assertTrue($this->query->exists('plugin', 'cwmscripture', 'task'));
        self::assertFalse($this->query->isEnabled('plugin', 'cwmscripture', 'task'));
        self::assertTrue($this->query->isEnabled('plugin', 'cwmscripture', 'system'));
    }

    #[Test]
    public function reads_the_version_the_site_believes_it_has(): void
    {
        self::assertSame('10.5.9', $this->query->version('component', 'com_proclaim'));
        self::assertSame('2.0.0', $this->query->version('plugin', 'cwmscripture', 'task'));
    }

    #[Test]
    public function an_unparseable_manifest_cache_yields_null_not_an_error(): void
    {
        self::assertNull($this->query->version('plugin', 'brokencache', 'system'));
    }

    #[Test]
    public function find_all_surfaces_a_shared_element_collision(): void
    {
        $rows = $this->query->findAll('plugin', 'cwmscripture');

        self::assertCount(2, $rows);
        self::assertSame(['system', 'task'], array_column($rows, 'folder'));
    }

    #[Test]
    public function reads_the_schema_version_joomla_recorded(): void
    {
        self::assertSame('10.5.7', $this->query->schemaVersion('component', 'com_proclaim'));
    }

    #[Test]
    public function schema_version_and_manifest_version_can_disagree(): void
    {
        // The whole reason this method exists. manifest_cache says the package
        // is 10.5.9; #__schemas says the SQL only ever ran up to 10.5.7. A
        // harness reading version() alone reports a clean upgrade here.
        self::assertSame('10.5.9', $this->query->version('component', 'com_proclaim'));
        self::assertSame('10.5.7', $this->query->schemaVersion('component', 'com_proclaim'));
    }

    #[Test]
    public function an_extension_with_no_schema_row_reads_as_null(): void
    {
        // Registered, but ships no SQL — null is "nothing recorded", not "absent".
        self::assertTrue($this->query->exists('library', 'cwmscripture'));
        self::assertNull($this->query->schemaVersion('library', 'cwmscripture'));
    }

    #[Test]
    public function an_absent_extension_has_no_schema_version(): void
    {
        self::assertNull($this->query->schemaVersion('component', 'com_nothing'));
    }

    #[Test]
    public function schema_version_honours_the_folder_that_disambiguates(): void
    {
        // Both plugins share the element; only the system one ran schema SQL.
        // Resolving through id() is what keeps these apart.
        self::assertSame('2.1.0', $this->query->schemaVersion('plugin', 'cwmscripture', 'system'));
        self::assertNull($this->query->schemaVersion('plugin', 'cwmscripture', 'task'));
    }
}
