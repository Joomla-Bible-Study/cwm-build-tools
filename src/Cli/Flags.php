<?php

declare(strict_types=1);

namespace CWM\BuildTools\Cli;

/**
 * Command-line flag extraction shared by the scripts/ entry points.
 *
 * Three scripts carried their own copy of this loop (#32); a bug in one
 * copy would have been invisible to the others' behaviour and to CI.
 */
final class Flags
{
    /**
     * The value of `--name value` or `--name=value` in $argv, or null when
     * the flag is absent (or present with no value).
     *
     * @param  list<string>  $argv
     */
    public static function value(array $argv, string $flag): ?string
    {
        foreach ($argv as $i => $arg) {
            if ($arg === $flag) {
                return $argv[$i + 1] ?? null;
            }

            if (str_starts_with($arg, $flag . '=')) {
                return substr($arg, strlen($flag) + 1);
            }
        }

        return null;
    }

    /**
     * Whether any of the given switches is present.
     *
     * @param  list<string>  $argv
     * @param  list<string>  $switches  e.g. ['-v', '--verbose']
     */
    public static function has(array $argv, array $switches): bool
    {
        foreach ($switches as $switch) {
            if (in_array($switch, $argv, true)) {
                return true;
            }
        }

        return false;
    }
}