<?php

declare(strict_types=1);

namespace CWM\BuildTools\Config;

/**
 * Stamp and recognise files that `cwm-sync-configs` owns outright.
 *
 * The sibling to {@see ManagedBlock}. A managed *block* shares a file with
 * content the consumer wrote; a managed *file* has no such content — the
 * whole thing comes from `templates/` and the sync replaces it wholesale.
 * `.editorconfig` is the first of these: there is no sensible way to merge
 * two sets of `[*.php]` sections, so the file is either ours or theirs.
 *
 * The header is what makes "either ours or theirs" decidable. A consumer
 * that predates the sync has a hand-written `.editorconfig` with no header,
 * and the sync must leave it alone rather than silently reformatting their
 * whole tree on the next `composer sync-configs`. Overwriting is only safe
 * for a file this tool wrote, and the header is the evidence it did.
 */
final class ManagedFile
{
    /**
     * The sentinel searched for by {@see isManaged}. Kept short and free of
     * regex metacharacters — matching is a substring test, and a consumer
     * pasting this line by hand should be able to type it exactly.
     */
    public const MARKER = 'cwm-build-tools: managed file';

    /**
     * The header prepended to a managed file.
     *
     * @param string $comment Comment leader for the target syntax — `#` for
     *                        .editorconfig and .gitignore, `//` for JS.
     */
    public static function header(string $comment = '#'): string
    {
        $lines = [
            self::MARKER . ' — do not edit',
            '',
            'This file is written by `composer sync-configs` from the copy in',
            'cwm-build-tools/templates/. Local edits are lost on the next sync.',
            'Change it upstream, or delete this header to take ownership of the',
            'file locally (the sync will then leave it alone).',
        ];

        $out = '';

        foreach ($lines as $line) {
            $out .= $line === '' ? rtrim($comment) . "\n" : $comment . ' ' . $line . "\n";
        }

        return $out;
    }

    /**
     * Whether $contents carries the managed-file header.
     *
     * Deliberately a plain substring test over the whole file rather than a
     * check of the first line: the templates lead with their own explanatory
     * comment block, and requiring the marker at offset 0 would make the
     * check fail the moment a template grew a preamble.
     */
    public static function isManaged(string $contents): bool
    {
        return str_contains($contents, self::MARKER);
    }

    /**
     * Return $body with the managed header prepended.
     *
     * Idempotent: stamping an already-stamped body returns it unchanged, so
     * a template that has been through a previous sync round-trips instead
     * of accumulating a header per run.
     */
    public static function stamp(string $body, string $comment = '#'): string
    {
        if (self::isManaged($body)) {
            return $body;
        }

        return self::header($comment) . "\n" . ltrim($body, "\n");
    }
}
