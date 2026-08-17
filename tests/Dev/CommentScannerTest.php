<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\CommentScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The comment extractor is the part worth pinning.
 *
 * The rule itself is a regex, but the value of the tool is that it searches
 * only the comment part of a line — a grep over whole lines flags every hex
 * colour and every issue number inside a string, and a tool that cries wolf
 * gets switched off.
 */
final class CommentScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-comment-lint-tests-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    /** Read a single line's comment text, starting outside a block. */
    private function comment(string $line, bool $lineForms = true): string
    {
        $inBlock = false;

        return (new CommentScanner())->commentText($line, $inBlock, $lineForms);
    }

    #[Test]
    public function reads_a_line_comment(): void
    {
        self::assertSame(' fixes the thing', $this->comment('// fixes the thing'));
    }

    #[Test]
    public function reads_an_inline_comment_after_code(): void
    {
        self::assertSame(' see #1234', $this->comment('$x = 1; // see #1234'));
    }

    #[Test]
    public function a_url_in_code_is_not_a_comment(): void
    {
        // The `//` in https:// is preceded by ':' and must not open a comment,
        // or every line carrying a URL becomes a false positive.
        self::assertSame('', $this->comment('$url = "https://example.test/a";'));
    }

    #[Test]
    public function a_line_comment_marker_inside_a_string_is_code(): void
    {
        self::assertSame('', $this->comment('$s = "not // a comment";'));
    }

    #[Test]
    public function an_escaped_quote_does_not_end_the_string(): void
    {
        self::assertSame('', $this->comment('$s = "he said \\" // still a string";'));
    }

    #[Test]
    public function block_state_carries_across_lines(): void
    {
        // Docblock continuation lines do not start with '/*', so without the
        // by-reference state a citation on line 2 of a docblock is invisible.
        $scanner = new CommentScanner();
        $inBlock = false;

        $scanner->commentText('/**', $inBlock, true);
        self::assertTrue($inBlock, 'the block is open after its opening line');

        $second = $scanner->commentText(' * see #1234 for the history', $inBlock, true);
        self::assertStringContainsString('#1234', $second);

        $scanner->commentText(' */', $inBlock, true);
        self::assertFalse($inBlock, 'the block closes');
    }

    #[Test]
    public function css_has_no_line_comments(): void
    {
        // `//` is not a comment in CSS. Treating it as one would read the rest
        // of a `background: url(//cdn/...)` line as commentary.
        self::assertSame('', $this->comment('background: url(//cdn.test/a.png);', false));
    }

    #[Test]
    public function cites_a_bare_issue_number(): void
    {
        self::assertTrue((new CommentScanner())->citesIssue(' see #1234'));
    }

    #[Test]
    public function cites_an_issue_number_introduced_by_a_word(): void
    {
        self::assertTrue((new CommentScanner())->citesIssue(' fixed in PR#1234'));
    }

    #[Test]
    public function another_projects_tracker_is_allowed(): void
    {
        // Blame cannot lead anyone to another repository's issue, so the
        // citation is the only way to find it — and it is how a cross-repo
        // reference should be written rather than deleted.
        self::assertFalse((new CommentScanner())->citesIssue(' see Joomla-Bible-Study/Proclaim#1704'));
    }

    #[Test]
    public function a_hex_colour_is_not_an_issue_number(): void
    {
        self::assertFalse((new CommentScanner())->citesIssue(' brand colour #1234ab'));
        self::assertFalse((new CommentScanner())->citesIssue(' brand colour #ffffff'));
    }

    #[Test]
    public function the_digit_threshold_is_configurable(): void
    {
        // A repository numbering in the hundreds gets no coverage at the
        // default, silently — which is the failure this tool exists to prevent
        // elsewhere.
        self::assertFalse((new CommentScanner())->citesIssue(' see #123'));
        self::assertTrue((new CommentScanner(3))->citesIssue(' see #123'));
    }

    #[Test]
    public function rejects_a_digit_threshold_below_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CommentScanner(0);
    }

    #[Test]
    public function scan_reports_the_whole_source_line(): void
    {
        $this->write('src/Model/A.php', "<?php\n\$x = 1; // see #1234\n");

        $findings = (new CommentScanner())->scan($this->tmpDir, ['src']);

        self::assertCount(1, $findings);
        self::assertSame(2, $findings[0]['line']);
        self::assertSame('$x = 1; // see #1234', $findings[0]['snippet']);
    }

    #[Test]
    public function scan_skips_tests_by_default(): void
    {
        // A regression test's docblock naming the issue it guards is the one
        // place the number is the subject rather than a pointer away from it.
        $this->write('tests/Unit/ThingTest.php', "<?php\n// guards #1234\n");

        self::assertSame([], (new CommentScanner())->scan($this->tmpDir, ['tests']));
    }

    #[Test]
    public function scan_does_not_flag_a_number_in_a_string(): void
    {
        $this->write('src/Model/A.php', "<?php\n\$msg = 'see #1234';\n");

        self::assertSame([], (new CommentScanner())->scan($this->tmpDir, ['src']));
    }

    #[Test]
    public function scan_reads_each_file_once_through_a_symlink(): void
    {
        // A manifest script is commonly a symlink to the repository-root copy.
        // Without the realpath guard the same line is reported twice.
        $this->write('src/script.php', "<?php\n// see #1234\n");
        symlink($this->tmpDir . '/src/script.php', $this->tmpDir . '/src/alias.php');

        self::assertCount(1, (new CommentScanner())->scan($this->tmpDir, ['src']));
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

            if (is_link($path) || !is_dir($path)) {
                unlink($path);

                continue;
            }

            $this->rrmdir($path);
        }

        rmdir($dir);
    }
}
