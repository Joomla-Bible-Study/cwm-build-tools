<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * Split a `.sql` file into statements exactly as Joomla's installer does.
 *
 * ## Why this is a port and not an implementation
 *
 * A replay harness that splits SQL differently from the installer is testing a
 * different program. `explode(';', $sql)` is the obvious version and it is
 * wrong in ways that matter here: it breaks on a semicolon inside a string
 * literal, inside a `--` or `/* *\/` comment, and it has no idea that
 * `/*! ... *\/` is a MySQL conditional-execution comment that must be *kept*
 * rather than stripped.
 *
 * So the scanner below is `Joomla\CMS\Installer\Installer::splitSql()` copied
 * character for character (Joomla 4.2+, GPL-2.0-or-later, same licence as this
 * project). It is deliberately not tidied, reformatted or "improved" — the
 * value is that it is byte-identical in behaviour, and every edit is a chance
 * for it to stop being. Fix bugs upstream and re-copy.
 *
 * Two of its subtleties are easy to miss when reading:
 *
 * - `#` opens a comment, but `#__` does not — that is Joomla's table prefix
 *   placeholder, and treating it as a comment would swallow the rest of the
 *   line on nearly every statement in a Joomla migration.
 * - `/*!` and `/*+` are not comments. The first is MySQL conditional SQL, the
 *   second an optimizer hint; both are executable and are left in the
 *   statement.
 *
 * One line is deliberately not copied: the upstream loop ends with
 * `$endComment = false;`, assigning a variable nothing ever reads. It is dead
 * in Joomla too. Recorded here so "verbatim" stays true rather than
 * approximately true.
 *
 * ## The CAN FAIL marker
 *
 * A statement ending `/** CAN FAIL *\/;` is one Joomla runs and tolerates
 * failing. Migrations use it for changes that may already be present — dropping
 * an index a later version might have removed, adding a column a hotfix already
 * added.
 *
 * The scanner keeps that marker attached to the statement (it would otherwise
 * be stripped as a comment) precisely so the caller can see it. {@see canFail()}
 * reads it back with the same test the installer uses.
 *
 * This matters more than it looks: without it a replay reports failures Joomla
 * would have shrugged off, and a harness that fails on statements production
 * tolerates is one people learn to ignore.
 */
final class SqlSplitter
{
    /**
     * The marker a statement carries to say the installer tolerates its failure.
     *
     * Value and length are Joomla's `Installer::CAN_FAIL_MARKER` and
     * `CAN_FAIL_MARKER_LENGTH`. The length is a separate constant there and is
     * kept separate here rather than derived, so the port stays comparable.
     */
    public const CAN_FAIL_MARKER = '/** CAN FAIL **/';

    private const CAN_FAIL_MARKER_LENGTH = 16;

    /**
     * Split SQL text into individual statements.
     *
     * Ported verbatim from Joomla's Installer::splitSql(). See the class
     * docblock before changing anything in here.
     *
     * @param  string|null $sql Raw contents of a `.sql` file.
     * @return list<string>     Statements, each still terminated with `;`.
     */
    public static function split(?string $sql): array
    {
        if (empty($sql)) {
            return [];
        }

        $start     = 0;
        $open      = false;
        $comment   = false;
        $endString = '';
        $end       = \strlen($sql);
        $queries   = [];
        $query     = '';

        for ($i = 0; $i < $end; $i++) {
            $current      = substr($sql, $i, 1);
            $current2     = substr($sql, $i, 2);
            $current3     = substr($sql, $i, 3);
            $lenEndString = \strlen($endString);
            $testEnd      = substr($sql, $i, $lenEndString);

            if (
                $current === '"' || $current === "'" || $current2 === '--'
                || ($current2 === '/*' && $current3 !== '/*!' && $current3 !== '/*+')
                || ($current === '#' && $current3 !== '#__')
                || ($comment && $testEnd === $endString)
            ) {
                // Check if quoted with previous backslash
                $n = 2;

                while (substr($sql, $i - $n + 1, 1) === '\\' && $n < $i) {
                    $n++;
                }

                // Not quoted
                if ($n % 2 === 0) {
                    if ($open) {
                        if ($testEnd === $endString) {
                            if ($comment) {
                                $comment = false;

                                if ($lenEndString > 1) {
                                    $i      += ($lenEndString - 1);
                                    $current = substr($sql, $i, 1);
                                }

                                $start = $i + 1;
                            }

                            $open      = false;
                            $endString = '';
                        }
                    } else {
                        $open = true;

                        if ($current2 === '--') {
                            $endString = "\n";
                            $comment   = true;
                        } elseif ($current2 === '/*') {
                            $endString = '*/';
                            $comment   = true;
                        } elseif ($current === '#') {
                            $endString = "\n";
                            $comment   = true;
                        } else {
                            $endString = $current;
                        }

                        if ($comment && $start < $i) {
                            $query .= substr($sql, $start, $i - $start);
                        }
                    }
                }
            }

            if ($comment) {
                $start = $i + 1;
            }

            if (($current === ';' && !$open) || $i === $end - 1) {
                if ($current === ';' && !$open && $start <= $i && $start > self::CAN_FAIL_MARKER_LENGTH) {
                    $possibleMarker = substr($sql, $start - self::CAN_FAIL_MARKER_LENGTH, $i - $start + self::CAN_FAIL_MARKER_LENGTH);

                    if (strtoupper($possibleMarker) === self::CAN_FAIL_MARKER) {
                        $start -= self::CAN_FAIL_MARKER_LENGTH;
                    }
                }

                if ($start <= $i) {
                    $query .= substr($sql, $start, $i - $start + 1);
                }

                $query = trim($query);

                if ($query) {
                    if (($i === $end - 1) && ($current !== ';')) {
                        $query .= ';';
                    }

                    $queries[] = $query;
                }

                $query = '';
                $start = $i + 1;
            }
        }

        return $queries;
    }

    /**
     * Whether the installer would tolerate this statement failing.
     *
     * Same test as `Installer::parseSchemaUpdates()`: the marker must be the
     * last thing before the terminating semicolon, and the comparison is
     * case-insensitive because the installer's is.
     */
    public static function canFail(string $query): bool
    {
        return \strlen($query) > self::CAN_FAIL_MARKER_LENGTH + 1
            && strtoupper(substr($query, -self::CAN_FAIL_MARKER_LENGTH - 1)) === (self::CAN_FAIL_MARKER . ';');
    }

    /**
     * The statement with any CAN FAIL marker removed, ready to execute.
     *
     * The installer strips it before running, so a driver never sees it.
     */
    public static function strip(string $query): string
    {
        return self::canFail($query)
            ? substr($query, 0, -self::CAN_FAIL_MARKER_LENGTH - 1) . ';'
            : $query;
    }

    /**
     * Substitute the table prefix the way Joomla's driver does.
     *
     * Ported from `DatabaseDriver::replacePrefix()`, and ported for the same
     * reason as {@see split()}: a naive `str_replace('#__', $prefix, $sql)`
     * differs from the installer on one case that migrations really contain —
     * `#__` **inside a string literal**. Joomla leaves those alone; str_replace
     * rewrites them.
     *
     * That is not hypothetical for a Joomla extension: a migration that inserts
     * a menu link, a params JSON blob or a help URL mentioning `#__` would be
     * rewritten by the naive version, and the replay would then be exercising
     * SQL no site ever ran.
     *
     * @param string $sql    Statement containing `#__` placeholders.
     * @param string $prefix Prefix to substitute in, e.g. `cwmreplay_`.
     */
    public static function replacePrefix(string $sql, string $prefix): string
    {
        $needle    = '#__';
        $escaped   = false;
        $startPos  = 0;
        $quoteChar = '';
        $literal   = '';

        $sql = trim($sql);
        $n   = \strlen($sql);

        while ($startPos < $n) {
            $ip = strpos($sql, $needle, $startPos);

            if ($ip === false) {
                break;
            }

            $j = strpos($sql, "'", $startPos);
            $k = strpos($sql, '"', $startPos);

            if (($k !== false) && (($k < $j) || ($j === false))) {
                $quoteChar = '"';
                $j         = $k;
            } else {
                $quoteChar = "'";
            }

            if ($j === false) {
                $j = $n;
            }

            $literal .= str_replace($needle, $prefix, substr($sql, $startPos, $j - $startPos));
            $startPos = $j;

            $j = $startPos + 1;

            if ($j >= $n) {
                break;
            }

            // Quote comes first, find end of quote
            while (true) {
                $k       = strpos($sql, $quoteChar, $j);
                $escaped = false;

                if ($k === false) {
                    break;
                }

                $l = $k - 1;

                while ($l >= 0 && $sql[$l] === '\\') {
                    $l--;
                    $escaped = !$escaped;
                }

                if ($escaped) {
                    $j = $k + 1;

                    continue;
                }

                break;
            }

            if ($k === false) {
                // Error in the query - no end quote; ignore it
                break;
            }

            $literal .= substr($sql, $startPos, $k - $startPos + 1);
            $startPos = $k + 1;
        }

        if ($startPos < $n) {
            $literal .= substr($sql, $startPos, $n - $startPos);
        }

        return $literal;
    }
}
