<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\DeprecationScanner;
use CWM\BuildTools\Dev\QueryStyleRules;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The query-style ruleset, run through the scanner that consumes it.
 *
 * Testing the pattern in isolation would pass on a ruleset the scanner never
 * applies — the extensions list has to line up with the files being walked, and
 * that pairing is the part worth pinning.
 */
final class QueryStyleRulesTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-query-lint-tests-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    private function scan(?array $excluded = null): array
    {
        return (new DeprecationScanner(QueryStyleRules::rules()))->scan($this->tmpDir, $excluded);
    }

    #[Test]
    public function flags_get_query_true(): void
    {
        $this->write('admin/src/Model/SeriesModel.php', "<?php\n\$query = \$db->getQuery(true);\n");

        $findings = $this->scan();

        self::assertCount(1, $findings);
        self::assertSame('get-query-true', $findings[0]['rule']);
        self::assertSame(2, $findings[0]['line']);
        self::assertStringContainsString('createQuery', $findings[0]['message']);
    }

    #[Test]
    public function does_not_flag_the_no_argument_form(): void
    {
        // getQuery() returns the CURRENT query rather than a new one. Different
        // operation, deliberately allowed — flagging it would send people to
        // rewrite working code into something that does something else.
        $this->write('admin/src/Model/SeriesModel.php', "<?php\n\$existing = \$db->getQuery();\n");

        self::assertSame([], $this->scan());
    }

    #[Test]
    public function does_not_flag_create_query(): void
    {
        $this->write('admin/src/Model/SeriesModel.php', "<?php\n\$query = \$db->createQuery();\n");

        self::assertSame([], $this->scan());
    }

    #[Test]
    public function tolerates_whitespace_inside_the_call(): void
    {
        // Formatting varies across a 600-file sweep; a rule that only matches
        // the tightest spelling reports a clean tree that is not clean.
        $this->write('admin/src/Model/A.php', "<?php\n\$q = \$db -> getQuery( true );\n");
        $this->write('admin/src/Model/B.php', "<?php\n\$q = \$db->getQuery(  true);\n");

        self::assertCount(2, $this->scan());
    }

    #[Test]
    public function scans_php_only(): void
    {
        // The rule is about PHP query building. A JS file mentioning the string
        // is not a query and must not be reported.
        $this->write('media/js/admin.js', "// see getQuery(true) in the model\n");

        self::assertSame([], $this->scan());
    }

    #[Test]
    public function excluded_directories_are_not_scanned(): void
    {
        // A submodule is a separate repository and not this project's standard
        // to enforce — the reason Proclaim's original script excluded one.
        $this->write('plugins/content/scripturelinks/Helper.php', "<?php\n\$q = \$db->getQuery(true);\n");
        $this->write('admin/src/Model/Clean.php', "<?php\n\$q = \$db->createQuery();\n");

        $findings = $this->scan(['vendor', 'node_modules', '.git', 'build', 'dist', 'scripturelinks']);

        self::assertSame([], $findings);
    }

    #[Test]
    public function every_occurrence_is_reported_not_just_the_first(): void
    {
        // Reporting one per file turns a 666-hit sweep into 200 runs.
        $this->write('admin/src/Model/Many.php', "<?php\n\$a = \$db->getQuery(true);\n\$b = \$db->getQuery(true);\n");

        self::assertCount(2, $this->scan());
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->tmpDir . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $contents);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->rrmdir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
