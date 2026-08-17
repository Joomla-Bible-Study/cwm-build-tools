<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\WorkflowPathScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Extraction and matching, against committed fixture workflows.
 *
 * Both halves can be wrong quietly. An extractor that misses a block reports a
 * clean filter for one it never read, and a matcher that is too generous reports
 * every stale entry as fine — either way the check passes and proves nothing,
 * which is the failure it exists to catch.
 */
final class WorkflowPathScannerTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/workflows/' . $name);
    }

    #[Test]
    public function reads_every_filter_in_a_file(): void
    {
        // Two blocks: push and pull_request. Reading only the first would
        // report a clean filter for one it never opened.
        $patterns = array_column(WorkflowPathScanner::extractPatterns($this->fixture('typical.yml')), 'pattern');

        self::assertSame([
            'admin/**',
            'site/**',
            'api/**',
            'tests/e2e/**',
            'build/test-install.sh',
            'composer.lock',
            'build/reset-testsite.php',
            '.github/workflows/e2e.yml',
        ], $patterns);
    }

    #[Test]
    public function handles_the_shapes_real_workflows_use(): void
    {
        $entries = WorkflowPathScanner::extractPatterns($this->fixture('typical.yml'));

        // Comments between entries are skipped, not read as patterns.
        self::assertNotContains('# A comment between entries, as the real files carry.', array_column($entries, 'pattern'));

        // Single-quoted, double-quoted and bare scalars all resolve to the value.
        self::assertContains('tests/e2e/**', array_column($entries, 'pattern'), 'double-quoted');
        self::assertContains('build/test-install.sh', array_column($entries, 'pattern'), 'unquoted');
    }

    #[Test]
    public function reads_flow_sequences_and_paths_ignore(): void
    {
        $entries = WorkflowPathScanner::extractPatterns($this->fixture('flow-and-ignore.yml'));

        self::assertSame(['src/**', 'docs/**', 'docs/legacy/**'], array_column($entries, 'pattern'));

        // The key is kept: a stale paths-ignore excludes nothing, which is the
        // same defect but reads differently in a report.
        self::assertSame(['paths', 'paths', 'paths-ignore'], array_column($entries, 'key'));
    }

    #[Test]
    public function a_workflow_with_no_filters_yields_nothing(): void
    {
        // 'paths:' appearing inside a run step must not be read as a filter.
        self::assertSame([], WorkflowPathScanner::extractPatterns($this->fixture('no-filters.yml')));
    }

    #[Test]
    public function double_star_crosses_directories_and_single_star_does_not(): void
    {
        self::assertTrue(WorkflowPathScanner::matches('admin/**', 'admin/src/Model/X.php'));
        self::assertTrue(WorkflowPathScanner::matches('admin/**', 'admin/x.php'));
        self::assertFalse(WorkflowPathScanner::matches('admin/*.php', 'admin/src/X.php'), 'single star stops at /');
        self::assertTrue(WorkflowPathScanner::matches('admin/*.php', 'admin/X.php'));
    }

    #[Test]
    public function a_bare_path_matches_itself_or_anything_beneath(): void
    {
        // GitHub treats a directory entry as covering its contents.
        self::assertTrue(WorkflowPathScanner::matches('composer.lock', 'composer.lock'));
        self::assertTrue(WorkflowPathScanner::matches('tests', 'tests/Dev/XTest.php'));
        self::assertFalse(WorkflowPathScanner::matches('composer.lock', 'composer.json'));
    }

    #[Test]
    public function a_negation_is_judged_on_whether_it_still_matches(): void
    {
        // An exclusion that excludes nothing is as stale as an inclusion that
        // includes nothing, so the ! is stripped for this question.
        self::assertTrue(WorkflowPathScanner::matches('!docs/**', 'docs/index.md'));
    }

    #[Test]
    public function finds_the_entry_that_matches_nothing(): void
    {
        // The case this class exists for: build/reset-testsite.php was deleted
        // when the shared command replaced it, and the filter entry outlived it.
        $files = [
            'admin/src/Model/X.php',
            'site/view.php',
            'api/src/X.php',
            'tests/e2e/spec.js',
            'build/test-install.sh',
            'composer.lock',
            '.github/workflows/e2e.yml',
        ];

        $stale = WorkflowPathScanner::stalePatterns($this->fixture('typical.yml'), $files);

        self::assertCount(1, $stale);
        self::assertSame('build/reset-testsite.php', $stale[0]['pattern']);
        self::assertSame('paths', $stale[0]['key']);
        self::assertGreaterThan(0, $stale[0]['line'], 'the line number locates it for the reader');
    }

    #[Test]
    public function a_filter_that_all_resolves_reports_nothing(): void
    {
        $files = ['src/a.php', 'docs/index.md', 'docs/legacy/old.md'];

        self::assertSame([], WorkflowPathScanner::stalePatterns($this->fixture('flow-and-ignore.yml'), $files));
    }
}
