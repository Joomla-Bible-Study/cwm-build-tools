<?php

declare(strict_types=1);

namespace CWM\BuildTools\Release;

/**
 * Expands `{date}` / `{date:FORMAT}` placeholders against a given moment.
 *
 * Shared between {@see VersionTracker}'s `expandDevSuffix()` (dev-suffix
 * templates, e.g. `-{date:Y-m-d}-dev`) and {@see TokenSubstituter}'s token
 * map (e.g. a `manifestTokens` entry's `{date:Y-m-d}`), so both read the
 * exact same format convention instead of drifting apart with their own
 * regexes. Bare `{date}` is `Ymd`, matching how SQL migration filenames are
 * dated (`10.5.3-20260801.sql`); `{date:FORMAT}` takes any PHP `date()`
 * format.
 */
final class DateTokenExpander
{
    public static function expand(string $template, \DateTimeImmutable $at): string
    {
        if (!str_contains($template, '{date')) {
            return $template;
        }

        return (string) preg_replace_callback(
            '/\{date(?::([^}]+))?\}/',
            static fn (array $m): string => $at->format($m[1] ?? 'Ymd'),
            $template,
        );
    }
}
