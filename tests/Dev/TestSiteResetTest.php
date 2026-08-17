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
