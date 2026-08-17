<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * Finds workflow path filters that no longer match anything.
 *
 * A `paths:` filter decides whether a job runs. When an entry names a file that
 * has moved or been deleted, the entry silently guards nothing — and in review
 * it is indistinguishable from one that works, which is how a filter rots into
 * false coverage. The job does not run, the pull request is green, and nothing
 * reports that a check was skipped.
 *
 * That has happened five times across two CWM repositories. Proclaim's own
 * `e2e.yml` carries comments recording two of them; a third was a `paths:` entry
 * left behind after `build/reset-testsite.php` was deleted in favour of the
 * shared command, and it sat there until this check was written.
 *
 * ## Why not a YAML parser
 *
 * This package has no YAML dependency and adding one for a single lint would put
 * a runtime dependency into every consumer's tree. The block being read is
 * highly regular — a `paths:` key followed by a `- 'pattern'` list — so it is
 * extracted directly, and the extractor is a pure function tested against
 * fixture workflows including the awkward shapes (comments between entries,
 * unquoted and double-quoted values, several filters in one file).
 *
 * ## Why not glob()
 *
 * PHP's `glob()` has no `**`, which is most of what these filters use. Patterns
 * are translated to regular expressions and matched against the repository's
 * tracked files, which is also more accurate than the filesystem: a pattern
 * matching only ignored build output is not real coverage.
 */
final class WorkflowPathScanner
{
    /**
     * Every path pattern a workflow filters on, with the key it came from.
     *
     * Both `paths` and `paths-ignore` are read, but they are not the same
     * defect and the caller is expected to treat them differently.
     *
     * A stale `paths` entry fails CLOSED: the job it should have triggered does
     * not run and the pull request is green. A stale `paths-ignore` entry fails
     * OPEN: it excludes nothing, so the job merely runs more often than needed.
     *
     * The second is also not reliably a defect at all. Excluding a directory
     * that is gitignored — `.claude/**`, say — is deliberate and correct, and
     * indistinguishable here from an exclusion whose target was deleted, because
     * neither appears in the tracked file list. Reporting those as errors is how
     * a linter starts crying wolf and gets switched off.
     *
     * @return list<array{key: string, pattern: string, line: int}>
     */
    public static function extractPatterns(string $yaml): array
    {
        $found  = [];
        $key    = null;
        $indent = 0;

        foreach (explode("\n", $yaml) as $index => $raw) {
            $line = rtrim($raw);

            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            $lineIndent = \strlen($line) - \strlen(ltrim($line));

            if (preg_match('/^\s*(paths|paths-ignore)\s*:\s*(.*)$/', $line, $m) === 1) {
                $inline = trim($m[2]);

                // Flow form: paths: [ 'a', 'b' ]
                if ($inline !== '' && $inline !== '|' && $inline !== '>') {
                    foreach (self::splitFlowSequence($inline) as $pattern) {
                        $found[] = ['key' => $m[1], 'pattern' => $pattern, 'line' => $index + 1];
                    }

                    $key = null;

                    continue;
                }

                $key    = $m[1];
                $indent = $lineIndent;

                continue;
            }

            if ($key === null) {
                continue;
            }

            // A list item belonging to the filter, i.e. indented past its key.
            if ($lineIndent > $indent && preg_match('/^\s*-\s*(.+)$/', $line, $m) === 1) {
                $found[] = ['key' => $key, 'pattern' => self::unquote(trim($m[1])), 'line' => $index + 1];

                continue;
            }

            // Anything at or left of the key's indentation ends the block.
            if ($lineIndent <= $indent) {
                $key = null;
            }
        }

        return $found;
    }

    /**
     * Whether a GitHub path pattern matches a repository-relative file.
     *
     * Translated to a regular expression rather than passed to `glob()`, which
     * has no `**` — and `**` is most of what these filters use.
     *
     * A leading `!` is a negation in GitHub's syntax. For "does this still match
     * anything" the negation is irrelevant, so it is stripped: an exclusion that
     * excludes nothing is exactly as stale as an inclusion that includes
     * nothing.
     */
    public static function matches(string $pattern, string $file): bool
    {
        return preg_match(self::toRegex($pattern), $file) === 1;
    }

    /**
     * The patterns in $yaml that match none of $files.
     *
     * @param  list<string>  $files  Repository-relative paths, e.g. from `git ls-files`.
     *
     * @return list<array{key: string, pattern: string, line: int}>
     */
    public static function stalePatterns(string $yaml, array $files): array
    {
        $stale = [];

        foreach (self::extractPatterns($yaml) as $entry) {
            foreach ($files as $file) {
                if (self::matches($entry['pattern'], $file)) {
                    continue 2;
                }
            }

            $stale[] = $entry;
        }

        return $stale;
    }

    /**
     * Compile a GitHub path pattern to a PCRE.
     *
     * `**` crosses directory separators, `*` does not, `?` is one character
     * other than a separator. A pattern with no wildcard matches the path itself
     * or anything beneath it, which is how GitHub treats a bare directory.
     */
    private static function toRegex(string $pattern): string
    {
        $pattern = ltrim($pattern, '!');
        $pattern = ltrim($pattern, '/');
        $out     = '';
        $length  = \strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '*') {
                if ($i + 1 < $length && $pattern[$i + 1] === '*') {
                    $out .= '.*';
                    $i++;

                    // `**/` should also match zero directories.
                    if ($i + 1 < $length && $pattern[$i + 1] === '/') {
                        $i++;
                    }

                    continue;
                }

                $out .= '[^/]*';

                continue;
            }

            if ($char === '?') {
                $out .= '[^/]';

                continue;
            }

            $out .= preg_quote($char, '~');
        }

        // A bare path matches itself or anything under it: 'admin' covers
        // 'admin/src/X.php', which is what GitHub does with a directory.
        if (!str_contains($pattern, '*') && !str_contains($pattern, '?')) {
            return '~^' . $out . '(?:/.*)?$~';
        }

        return '~^' . $out . '$~';
    }

    /** Split `[ 'a', "b", c ]` into its members. */
    private static function splitFlowSequence(string $inline): array
    {
        $inline = trim($inline, "[] \t");

        if ($inline === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $part): string => self::unquote(trim($part)),
            explode(',', $inline)
        ), static fn (string $p): bool => $p !== ''));
    }

    /** Strip one layer of matching quotes, and any trailing comment. */
    private static function unquote(string $value): string
    {
        if (preg_match('/^([\'"])(.*?)\1/', $value, $m) === 1) {
            return $m[2];
        }

        // Unquoted scalar: a ` #` starts a comment.
        $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;

        return trim($value);
    }
}
