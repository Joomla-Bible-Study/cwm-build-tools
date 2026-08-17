<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds issue references written inside code comments.
 *
 * A comment carries what a maintainer needs at the line. An issue number is a
 * pointer to the investigation instead, and git log / git blame already connect
 * any line to its commit, its pull request and its issue — so the citation adds
 * nothing to the file and goes stale the moment the numbering context does.
 *
 * The rule is worth enforcing rather than agreeing to, because the debt grows
 * back: a sweep cleared it across three repositories once, and within a week
 * new citations had been written. The same shape returns whenever someone
 * explains a fix at the line instead of in the commit body.
 *
 * Two things make this a parser rather than a grep, and both were load-bearing
 * in the original:
 *
 *   - only the comment part of a line is searched, so an inline comment after
 *     code is covered while a `#` inside a string literal or a hex colour is
 *     not;
 *   - `owner/repo#123` names another project's tracker, which blame cannot lead
 *     anyone to, so it earns its place and is left alone.
 *
 * Inputs are project-controlled source files (trusted, per the build-tools
 * threat model) — this only reads, never executes.
 */
final class CommentScanner
{
    /**
     * Directory path fragments skipped wholesale.
     *
     * `tests` is deliberate rather than incidental: a regression test's docblock
     * naming the issue it guards is the one place the number is the subject
     * rather than a pointer away from it.
     *
     * @var list<string>
     */
    public const DEFAULT_SKIP = ['/vendor/', '/node_modules/', '/.git/', '/tests/', '/libraries/', '.min.'];

    /**
     * Conventional source roots, matching the layout `LinkResolver` assumes.
     *
     * @var list<string>
     */
    public const DEFAULT_PATHS = ['admin', 'api', 'site', 'modules', 'plugins', 'components', 'src'];

    /**
     * How many digits make a token an issue number.
     *
     * Four by default: three would collide with CSS hex colours (`#fff` is
     * three, `#ffffff` six, and the pattern's trailing guard rejects a
     * hex-looking run), and every citation the original sweep found had four
     * because the project's numbering had long passed 1000. A younger
     * repository numbering in the hundreds needs this lowered or it gets no
     * coverage at all — silently, which is the failure this class exists to
     * prevent elsewhere.
     */
    private int $digits;

    public function __construct(int $digits = 4)
    {
        if ($digits < 1) {
            throw new \InvalidArgumentException("digits must be at least 1, got {$digits}");
        }

        $this->digits = $digits;
    }

    /**
     * Return only the comment text on a line.
     *
     * `$inBlock` carries `/* … *\/` state across lines by reference, so
     * continuation lines of a docblock are read as comment without relying on
     * them starting with `*`. Quotes are tracked so a `//` inside a string is
     * code, and a `//` preceded by `:` is left alone so a URL in code does not
     * read as a comment.
     *
     * PSR-12 forbids `#` line comments and PHP CS Fixer enforces PSR-12 across
     * these projects, so that form is not handled — `#[` attributes would be
     * the ambiguous case.
     *
     * @param  string  $line       The line to read.
     * @param  bool    $inBlock    Whether a block comment is already open.
     * @param  bool    $lineForms  Whether `//` opens a comment (false for CSS).
     *
     * @return string  The comment text, empty when the line carries none.
     */
    public function commentText(string $line, bool &$inBlock, bool $lineForms): string
    {
        $out    = '';
        $len    = \strlen($line);
        $quote  = null;
        $i      = 0;

        while ($i < $len) {
            $c    = $line[$i];
            $next = $i + 1 < $len ? $line[$i + 1] : '';

            if ($inBlock) {
                if ($c === '*' && $next === '/') {
                    $inBlock = false;
                    $i += 2;

                    continue;
                }

                $out .= $c;
                $i++;

                continue;
            }

            if ($quote !== null) {
                if ($c === '\\') {
                    $i += 2;

                    continue;
                }

                if ($c === $quote) {
                    $quote = null;
                }

                $i++;

                continue;
            }

            if ($c === '"' || $c === "'") {
                $quote = $c;
                $i++;

                continue;
            }

            if ($c === '/' && $next === '*') {
                $inBlock = true;
                $i += 2;

                continue;
            }

            if ($lineForms && $c === '/' && $next === '/' && ($i === 0 || $line[$i - 1] !== ':')) {
                $out .= substr($line, $i + 2);

                break;
            }

            $i++;
        }

        return $out;
    }

    /**
     * Whether a comment cites an issue in this project's tracker.
     *
     * A bare `#` followed by the configured run of digits, or one introduced by
     * a word such as `PR#`, is a pointer into this project's history and is
     * reported. `owner/repo#123` points at somebody else's tracker, which blame
     * cannot lead anyone to, so it is allowed — the slash in the token before
     * `#` is what distinguishes them.
     *
     * (Written without a literal example number on purpose: this file is
     * scanned like any other, and an illustration of the rule would trip it.)
     */
    public function citesIssue(string $comment): bool
    {
        $pattern = '~#\d{' . $this->digits . '}(?![0-9a-fA-F])~';

        if (!preg_match_all($pattern, $comment, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        foreach ($matches[0] as [$_, $offset]) {
            preg_match('~[\w./-]*$~', substr($comment, 0, $offset), $preceding);

            if (!str_contains($preceding[0], '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk the given roots and return every citation found.
     *
     * Findings carry the whole source line rather than the comment alone, so a
     * reader can see what the comment was attached to without opening the file.
     *
     * @param  string        $projectRoot  Directory the roots are relative to.
     * @param  list<string>  $roots        Source roots to walk.
     * @param  list<string>  $skip         Path fragments to skip. Defaults to self::DEFAULT_SKIP.
     *
     * @return list<array{file: string, line: int, snippet: string}>
     */
    public function scan(string $projectRoot, array $roots, ?array $skip = null): array
    {
        $skip      = $skip ?? self::DEFAULT_SKIP;
        $offenders = [];

        /*
         * Real paths already read. A manifest script is commonly a symlink to
         * the repository root copy, so without this a file reachable twice is
         * reported twice — and a symlinked directory would recurse forever.
         */
        $seen = [];

        foreach ($roots as $root) {
            $absolute = str_starts_with($root, '/') ? $root : $projectRoot . '/' . $root;

            if (!is_dir($absolute)) {
                continue;
            }

            $walker = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($walker as $file) {
                $path = str_replace('\\', '/', $file->getPathname());

                if (!preg_match('~\.(php|js|mjs|css)$~', $path)) {
                    continue;
                }

                foreach ($skip as $fragment) {
                    if (str_contains($path, $fragment)) {
                        continue 2;
                    }
                }

                $real = realpath($path);

                if ($real === false || isset($seen[$real])) {
                    continue;
                }

                $seen[$real] = true;

                $lines = file($path, FILE_IGNORE_NEW_LINES);

                if ($lines === false) {
                    continue;
                }

                $lineForms = !str_ends_with($path, '.css');
                $inBlock   = false;

                foreach ($lines as $index => $line) {
                    $comment = $this->commentText($line, $inBlock, $lineForms);

                    if ($comment !== '' && $this->citesIssue($comment)) {
                        $offenders[] = [
                            'file'    => $path,
                            'line'    => $index + 1,
                            'snippet' => trim($line),
                        ];
                    }
                }
            }
        }

        return $offenders;
    }
}
