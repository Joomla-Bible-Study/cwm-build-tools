<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Release;

use CWM\BuildTools\Release\ReleaseNotesFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ARS renders a release's notes as HTML. Publishing Markdown into that field
 * put this on the public Proclaim 10.3.6 download page — the only changelog a
 * site administrator following an update link ever sees:
 *
 *     ## What's Changed * fix(api): make the API switchable ... **Full
 *     Changelog**: https://...
 *
 * markers literal, everything on one line.
 */
class ReleaseNotesFormatterTest extends TestCase
{
    private ReleaseNotesFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ReleaseNotesFormatter();
    }

    /**
     * The exact body GitHub generated for Proclaim 10.3.6.
     */
    public function testGitHubGeneratedNotesBecomeStructuredHtml(): void
    {
        $markdown = <<<'MD'
            ## What's Changed
            * fix(api): make the API switchable by @bcordis in https://github.com/Joomla-Bible-Study/Proclaim/pull/1331
            * chore: bump active_development to 10.3.6 by @bcordis in https://github.com/Joomla-Bible-Study/Proclaim/pull/1332

            **Full Changelog**: https://github.com/Joomla-Bible-Study/Proclaim/compare/v10.3.5...v10.3.6
            MD;

        $html = $this->formatter->toHtml($markdown);

        $this->assertStringContainsString("<h3>What&#039;s Changed</h3>", $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertSame(2, substr_count($html, '<li>'), 'One bullet per changed item');
        $this->assertStringContainsString('<strong>Full Changelog</strong>', $html);

        // Nothing may survive as a literal Markdown marker.
        $this->assertStringNotContainsString('##', $html);
        $this->assertStringNotContainsString('**', $html);
        $this->assertDoesNotMatchRegularExpression('/(^|\n)\s*\*\s/', $html, 'No literal bullet markers');
    }

    public function testBareUrlsBecomeLinks(): void
    {
        $html = $this->formatter->toHtml('See https://example.org/a_b for details.');

        $this->assertStringContainsString(
            '<a href="https://example.org/a_b" rel="noopener">https://example.org/a_b</a>',
            $html
        );
    }

    /**
     * A URL is stashed before emphasis runs, so its punctuation is not eaten.
     */
    public function testUrlWithUnderscoresIsNotItalicised(): void
    {
        $html = $this->formatter->toHtml('https://example.org/a_b_c');

        $this->assertStringNotContainsString('<em>', $html);
    }

    public function testMarkdownLinkUsesItsLabel(): void
    {
        $html = $this->formatter->toHtml('[the issue](https://example.org/1)');

        $this->assertStringContainsString('<a href="https://example.org/1" rel="noopener">the issue</a>', $html);
    }

    /**
     * Trailing sentence punctuation belongs outside the anchor.
     */
    public function testTrailingPunctuationIsNotPartOfTheLink(): void
    {
        $html = $this->formatter->toHtml('Read https://example.org/x.');

        $this->assertStringContainsString('>https://example.org/x</a>.', $html);
    }

    /**
     * Notes come from a GitHub release body, which anyone with write access can
     * edit — it must not be able to inject markup into our site.
     */
    public function testHtmlInSourceIsEscaped(): void
    {
        $html = $this->formatter->toHtml('Fixed <script>alert(1)</script> handling');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCodeSpansAreNotTreatedAsFormatting(): void
    {
        $html = $this->formatter->toHtml('Use `**not bold**` here');

        $this->assertStringContainsString('<code>**not bold**</code>', $html);
        $this->assertStringNotContainsString('<strong>', $html);
    }

    /**
     * Notes are written as wrapped Markdown, so consecutive lines are one
     * paragraph — joined, as Markdown joins them, not hard-broken at whatever
     * column the author's editor happened to wrap at.
     */
    public function testWrappedProseLinesJoinIntoOneParagraph(): void
    {
        $html = $this->formatter->toHtml("First line\nSecond line");

        $this->assertSame('<p>First line Second line</p>', $html);
    }

    /**
     * A bullet whose text ran past the margin is still one bullet. Treating the
     * continuation as a new block split every wrapped item in the 10.3.6 notes
     * into a one-line list followed by an orphaned paragraph.
     */
    public function testWrappedListItemStaysOneItem(): void
    {
        $html = $this->formatter->toHtml("* an item whose text\n  continues on the next line\n* second");

        $this->assertSame(2, substr_count($html, '<li>'));
        $this->assertStringContainsString('<li>an item whose text continues on the next line</li>', $html);
        $this->assertSame(1, substr_count($html, '<ul>'), 'One list, not one per line');
        $this->assertStringNotContainsString('<p>', $html);
    }

    /**
     * Emphasis that spans the wrap still resolves, because the item is joined
     * before inline formatting runs.
     */
    public function testEmphasisSpanningAWrapIsResolved(): void
    {
        $html = $this->formatter->toHtml("* the *quoted\n  phrase* here");

        $this->assertStringContainsString('<em>quoted phrase</em>', $html);
        $this->assertStringNotContainsString('*', $html);
    }

    public function testBlankLineStartsANewParagraph(): void
    {
        $html = $this->formatter->toHtml("One\n\nTwo");

        $this->assertSame(2, substr_count($html, '<p>'));
    }

    /**
     * A blank line is what ends a list — the same rule Markdown uses, and the
     * one that keeps a wrapped bullet intact.
     */
    public function testBlankLineEndsAListSoProseFollowsIt(): void
    {
        $html = $this->formatter->toHtml("- item\n\nTrailing note");

        $this->assertStringContainsString('</ul>', $html);
        $this->assertStringContainsString('<p>Trailing note</p>', $html);
        $this->assertStringNotContainsString('Trailing note</li>', $html);
    }

    #[DataProvider('headingProvider')]
    public function testHeadingsAreDemotedOneLevel(string $markdown, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->formatter->toHtml($markdown));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function headingProvider(): array
    {
        return [
            'h1 becomes h2' => ['# Title', '<h2>Title</h2>'],
            'h2 becomes h3' => ['## Title', '<h3>Title</h3>'],
            'h3 becomes h4' => ['### Title', '<h4>Title</h4>'],
            'h6 stays h6'   => ['###### Title', '<h6>Title</h6>'],
        ];
    }

    #[DataProvider('bulletMarkerProvider')]
    public function testEachBulletMarkerProducesAListItem(string $marker): void
    {
        $html = $this->formatter->toHtml("$marker one\n$marker two");

        $this->assertSame(2, substr_count($html, '<li>'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function bulletMarkerProvider(): array
    {
        return ['asterisk' => ['*'], 'hyphen' => ['-'], 'plus' => ['+']];
    }

    /**
     * Hand-written release bullets are plain prose lines with no Markdown at
     * all — the other thing we feed this. Each must become its own paragraph.
     */
    public function testPlainProseBulletsFileBecomesParagraphs(): void
    {
        $notes = "The REST API now works.\n\nTurning it on is one step.\n";

        $html = $this->formatter->toHtml($notes);

        $this->assertSame('<p>The REST API now works.</p>' . "\n" . '<p>Turning it on is one step.</p>', $html);
    }

    public function testEmptyInputProducesEmptyOutput(): void
    {
        $this->assertSame('', $this->formatter->toHtml(''));
        $this->assertSame('', $this->formatter->toHtml("\n\n  \n"));
    }
}