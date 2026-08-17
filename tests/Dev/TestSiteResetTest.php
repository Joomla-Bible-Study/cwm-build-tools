<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\TestSite;
use CWM\BuildTools\Dev\TestSiteReset;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Family selection and the retained-set override, against a real database.
 *
 * The selection is the whole risk here: a family expressed as
 * `element LIKE '%scripture%'` sweeps up extensions its author never
 * considered, and the only symptom is a later run starting from a state nobody
 * described. Asserting on generated SQL would not catch that.
 */
final class TestSiteResetTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->pdo->exec(
            'CREATE TABLE jos_extensions (
                extension_id INTEGER PRIMARY KEY,
                type TEXT, element TEXT, folder TEXT, client_id INTEGER
            )'
        );
        $this->pdo->exec('CREATE TABLE jos_schemas (extension_id INTEGER, version_id TEXT)');
        $this->pdo->exec('CREATE TABLE jos_modules (id INTEGER PRIMARY KEY, module TEXT)');
        $this->pdo->exec('CREATE TABLE jos_modules_menu (moduleid INTEGER, menuid INTEGER)');
        $this->pdo->exec('CREATE TABLE jos_update_sites (update_site_id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec('CREATE TABLE jos_update_sites_extensions (update_site_id INTEGER, extension_id INTEGER)');

        $this->pdo->exec("INSERT INTO jos_modules VALUES (10, 'mod_proclaim'), (11, 'mod_menu')");
        $this->pdo->exec('INSERT INTO jos_modules_menu VALUES (10, 1), (11, 1)');
        $this->pdo->exec("INSERT INTO jos_update_sites VALUES (20, 'Proclaim Updates'), (21, 'Joomla Core')");
        $this->pdo->exec('INSERT INTO jos_update_sites_extensions VALUES (20, 1), (21, 99)');

        $rows = [
            [1, 'component', 'com_proclaim', '', 1],
            [2, 'library', 'lib_cwmscripture', '', 0],
            [3, 'plugin', 'proclaim', 'system', 0],
            [4, 'module', 'mod_proclaim', '', 0],
            // Not the family: a third-party extension that a loose pattern
            // could plausibly sweep up.
            [5, 'component', 'com_content', '', 1],
            [6, 'plugin', 'scripturelinks', 'content', 0],
        ];

        $stmt = $this->pdo->prepare('INSERT INTO jos_extensions VALUES (?, ?, ?, ?, ?)');

        foreach ($rows as $row) {
            $stmt->execute($row);
        }

        foreach ([1, 2, 3, 4, 5, 6] as $id) {
            $this->pdo->prepare('INSERT INTO jos_schemas VALUES (?, ?)')->execute([$id, '1.0.0']);
        }
    }

    private function reset(array $plan): TestSiteReset
    {
        return new TestSiteReset(TestSite::fromPdo($this->pdo, 'jos_'), $plan);
    }

    #[Test]
    public function selects_the_family_by_exact_element_and_by_pattern(): void
    {
        $rows = $this->reset([
            'elements'        => ['com_proclaim'],
            'elementPatterns' => ['%scripture%'],
        ])->familyRows();

        // Ordered by type, folder, element: component, library, plugin.
        self::assertSame(
            ['com_proclaim', 'lib_cwmscripture', 'scripturelinks'],
            array_column($rows, 'element')
        );
    }

    #[Test]
    public function leaves_everything_outside_the_family_alone(): void
    {
        $rows = $this->reset(['elements' => ['com_proclaim']])->familyRows();

        self::assertSame(['com_proclaim'], array_column($rows, 'element'));
    }

    #[Test]
    public function retain_overrides_a_family_match(): void
    {
        // The escape hatch that makes a shared family definition safe: a
        // pattern broad enough to be useful can still spare a named extension.
        $rows = $this->reset([
            'elementPatterns' => ['%scripture%'],
            'retain'          => ['lib_cwmscripture'],
        ])->familyRows();

        self::assertSame(['scripturelinks'], array_column($rows, 'element'));
    }

    #[Test]
    public function an_empty_family_selects_nothing_rather_than_everything(): void
    {
        // A missing config block must not read as "match all". This is the
        // difference between a no-op and wiping a site.
        self::assertSame([], $this->reset([])->familyRows());
    }

    #[Test]
    public function purging_removes_the_family_and_its_schema_rows(): void
    {
        $counts = $this->reset(['elements' => ['com_proclaim', 'lib_cwmscripture']])->purgeDatabase();

        self::assertSame(2, $counts['extensions']);
        self::assertSame(2, $counts['schemas']);

        $remaining = $this->pdo->query('SELECT element FROM jos_extensions ORDER BY extension_id')
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame(['proclaim', 'mod_proclaim', 'com_content', 'scripturelinks'], $remaining);

        // The schema rows for survivors must not have gone with them.
        self::assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM jos_schemas')->fetchColumn());
    }

    #[Test]
    public function purging_does_not_touch_a_retained_extension(): void
    {
        $reset = $this->reset([
            'elementPatterns' => ['%scripture%'],
            'retain'          => ['lib_cwmscripture'],
        ]);

        $reset->purgeDatabase();

        self::assertSame(
            ['lib_cwmscripture' => true],
            $reset->retainedStatus(),
            'a retained extension is reported as surviving, so nobody has to infer it'
        );
    }

    #[Test]
    public function removes_module_instances_and_their_menu_assignments(): void
    {
        // A module's #__extensions row is not the module. Removing the
        // extension while instances remain leaves rows pointing at a module
        // Joomla can no longer resolve.
        $counts = $this->reset(['modulePatterns' => ['mod_proclaim%']])->purgeDatabase();

        self::assertSame(1, $counts['modules']);
        self::assertSame(
            ['mod_menu'],
            $this->pdo->query('SELECT module FROM jos_modules')->fetchAll(PDO::FETCH_COLUMN)
        );
        self::assertSame(
            [11],
            array_map('intval', $this->pdo->query('SELECT moduleid FROM jos_modules_menu')->fetchAll(PDO::FETCH_COLUMN)),
            'the assignment goes with the instance'
        );
    }

    #[Test]
    public function removes_update_sites_and_leaves_core_alone(): void
    {
        // A stale update site keeps Joomla polling a stream for something that
        // is not installed, and a reinstall finds it already registered and
        // does not re-add it.
        $counts = $this->reset(['updateSiteNamePatterns' => ['%Proclaim%']])->purgeDatabase();

        self::assertSame(1, $counts['updateSites']);
        self::assertSame(
            ['Joomla Core'],
            $this->pdo->query('SELECT name FROM jos_update_sites')->fetchAll(PDO::FETCH_COLUMN)
        );
        self::assertSame(
            [21],
            array_map('intval', $this->pdo->query('SELECT update_site_id FROM jos_update_sites_extensions')->fetchAll(PDO::FETCH_COLUMN))
        );
    }

    #[Test]
    public function unconfigured_cleanups_are_no_ops(): void
    {
        // A project that declares none of these must not have its modules or
        // update sites touched by a default.
        $counts = $this->reset(['elements' => ['com_proclaim']])->purgeDatabase();

        self::assertSame(0, $counts['modules']);
        self::assertSame(0, $counts['updateSites']);
        self::assertSame(0, $counts['actionLogs']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM jos_modules')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM jos_update_sites')->fetchColumn());
    }

    #[Test]
    public function a_retained_extension_swept_up_anyway_is_reported_as_missing(): void
    {
        /*
         * The case the printed retained set exists for. Here the family names
         * the library explicitly AND retains it — a contradiction a reader
         * would not spot in config. retain wins, so it survives; if a future
         * change made it not win, this is what would say so.
         */
        $reset = $this->reset(['elements' => ['lib_cwmscripture'], 'retain' => ['lib_cwmscripture']]);
        $reset->purgeDatabase();

        self::assertSame(['lib_cwmscripture' => true], $reset->retainedStatus());

        // And with no retain, the same family does remove it.
        $plain = $this->reset(['elements' => ['lib_cwmscripture']]);
        $plain->purgeDatabase();

        self::assertSame(
            ['lib_cwmscripture' => false],
            $this->reset(['retain' => ['lib_cwmscripture']])->retainedStatus()
        );
    }
}
