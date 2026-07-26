<?php

declare(strict_types=1);

namespace CWM\BuildTools\Release;

/**
 * Converts release notes written in Markdown into the HTML that Akeeba Release
 * System stores and renders verbatim.
 *
 * ARS treats a release's `notes` as an HTML fragment: it is echoed into the
 * public download page without any Markdown processing. Feeding it the body of
 * a GitHub release — which is Markdown, and which GitHub generates
 * automatically unless a human replaces it — produced a download page reading
 *
 *     ## What's Changed * fix(api): ... by @bcordis in https://... **Full
 *     Changelog**: https://...
 *
 * with the markers literal and every line collapsed into one paragraph, because
 * HTML folds newlines into spaces. That is the entire visible changelog for
 * anyone following an update link, so it has to be real markup.
 *
 * This is deliberately a small subset — the constructs GitHub's generated notes
 * and our hand-written notes actually use — rather than a Markdown engine. It
 * takes no dependency, which matters for a script that runs during a release.
 */
final class ReleaseNotesFormatter
{
    /**
     * Render a Markdown document as an HTML fragment.
     */
    public function toHtml(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($markdown)));

        $html      = [];
        $paragraph = [];
        $list      = [];

        $flush = function () use (&$html, &$paragraph, &$list): void {
            if ($paragraph !== []) {
                $html[]    = '<p>' . implode("<br>\n", $paragraph) . '</p>';
                $paragraph = [];
            }

            if ($list !== []) {
                $html[] = "<ul>\n" . implode("\n", array_map(
                    static fn(string $item): string => '<li>' . $item . '</li>',
                    $list
                )) . "\n</ul>";
                $list = [];
            }
        };

        foreach ($lines as $line) {
            $line = rtrim($line);

            // A blank line closes whatever block is open.
            if (trim($line) === '') {
                $flush();
                continue;
            }

            // Headings. GitHub's generated notes open with "## What's Changed";
            // h2 is demoted to h3 so the notes sit under the page's own title
            // rather than competing with it.
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
                $flush();
                $level  = min(\strlen($m[1]) + 1, 6);
                $html[] = '<h' . $level . '>' . $this->inline(trim($m[2])) . '</h' . $level . '>';
                continue;
            }

            // Horizontal rule.
            if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
                $flush();
                $html[] = '<hr>';
                continue;
            }

            // List items. Nesting is flattened: these notes do not use it, and a
            // wrong guess about depth is worse than a flat list.
            if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m) === 1) {
                if ($paragraph !== []) {
                    $html[]    = '<p>' . implode("<br>\n", $paragraph) . '</p>';
                    $paragraph = [];
                }

                $list[] = $this->inline(trim($m[1]));
                continue;
            }

            // Anything else is paragraph text. A list is closed first so trailing
            // prose does not get swallowed into the last bullet.
            if ($list !== []) {
                $flush();
            }

            $paragraph[] = $this->inline(trim($line));
        }

        $flush();

        return implode("\n", $html);
    }

    /**
     * Apply inline formatting to one line of already-block-classified text.
     *
     * Everything is escaped first, so no Markdown source can inject markup. The
     * constructs that produce their own tags — code spans and links — are then
     * stashed behind placeholders before emphasis is applied, which stops a URL
     * containing underscores or asterisks from being mangled into <em>.
     */
    private function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        /** @var array<string, string> $stashed */
        $stashed = [];
        $stash   = static function (string $html) use (&$stashed): string {
            $key           = "\x00" . \count($stashed) . "\x00";
            $stashed[$key] = $html;

            return $key;
        };

        // Code spans are opaque: nothing inside them is formatting.
        $text = preg_replace_callback(
            '/`([^`]+)`/',
            static fn(array $m): string => $stash('<code>' . $m[1] . '</code>'),
            $text
        ) ?? $text;

        // [label](url)
        $text = preg_replace_callback(
            '/\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/',
            static fn(array $m): string => $stash(
                '<a href="' . $m[2] . '" rel="noopener">' . ($m[1] !== '' ? $m[1] : $m[2]) . '</a>'
            ),
            $text
        ) ?? $text;

        // Bare URLs — GitHub's generated notes are full of them. Trailing
        // sentence punctuation is left outside the link.
        $text = preg_replace_callback(
            '~https?://[^\s<>()\[\]]+[^\s<>()\[\].,;:!?\'"]~',
            static fn(array $m): string => $stash('<a href="' . $m[0] . '" rel="noopener">' . $m[0] . '</a>'),
            $text
        ) ?? $text;

        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*(?!\*)([^*]+)\*(?!\*)/', '<em>$1</em>', $text) ?? $text;

        return strtr($text, $stashed);
    }
}