<?php

declare(strict_types=1);

namespace CWM\BuildTools\Build;

/**
 * Interactive prompt with a countdown timer, ANSI redraw, and CI/non-interactive detection.
 *
 * Extracted from the version in Proclaim's `build/proclaim_build.php` so the fixed
 * countdown-redraw behavior (ANSI clear-to-EOL — without it, "(10s):" being replaced
 * by "(9s):" leaves a stray "0" on the line) doesn't have to be patched separately
 * in every consumer's build script.
 *
 * Non-interactive callers (CI, redirected stdio, `$CWM_NONINTERACTIVE`) take
 * `$default` immediately with a single diagnostic line — `cwm-release` chains
 * builds via `bash -c "$BUILD_CMD"` and sets `CWM_NONINTERACTIVE=1` so the
 * countdown doesn't leave half-drawn fragments behind in release logs.
 */
final class Prompt
{
    /**
     * Prompt for input with optional default and countdown timer.
     *
     * Behavior:
     *   - Non-interactive + default given     → echoes one diagnostic line, returns default
     *   - Non-interactive + no default        → returns null (caller decides)
     *   - Interactive, $timeout > 0, default  → countdown w/ single-keypress detection
     *   - Interactive otherwise               → fgets() w/ trim, default on empty input
     *
     * @param  string      $question  Prompt text. Will be appended with " [$default]" when default is supplied.
     * @param  string|null $default   Value returned on Enter, timeout, or non-interactive.
     * @param  int         $timeout   Seconds for the countdown; 0 disables the countdown.
     * @return string|null            User input, default on timeout/Enter/non-interactive, or null when no default and no input.
     */
    public static function ask(string $question, ?string $default = null, int $timeout = 0): ?string
    {
        $prompt = $question . ($default !== null ? " [$default]" : '');

        if (self::isNonInteractive()) {
            if ($default !== null) {
                echo $prompt . ': ' . $default . " (auto, non-interactive)\n";

                return $default;
            }

            return null;
        }

        if ($timeout > 0 && $default !== null) {
            return self::askWithCountdown($prompt, $default, $timeout);
        }

        echo $prompt . ': ';

        $handle = fopen('php://stdin', 'rb');

        if ($handle === false) {
            return $default;
        }

        $line = fgets($handle);
        fclose($handle);

        $line = is_string($line) ? trim($line) : '';

        return $line === '' ? $default : $line;
    }

    /**
     * Whether the current process should skip interactive prompts.
     *
     * True when STDIN/STDOUT are not TTYs (piped/redirected), `$CI` is set
     * (set by GitHub Actions, GitLab CI, etc.), or `$CWM_NONINTERACTIVE` is set
     * (set by `cwm-release` when chaining builds).
     */
    public static function isNonInteractive(): bool
    {
        return !stream_isatty(STDIN)
            || !stream_isatty(STDOUT)
            || getenv('CI') !== false
            || getenv('CWM_NONINTERACTIVE') !== false;
    }

    /**
     * Render the countdown loop with single-keypress capture.
     *
     * `stty cbreak -echo` is the only portable way to get single-character reads
     * without echo (no PHP-native equivalent). The original stty state is
     * captured first and restored on every exit path so the user's terminal
     * isn't left in raw mode if the caller traps Ctrl-C.
     */
    private static function askWithCountdown(string $prompt, string $default, int $timeout): string
    {
        $oldStty = self::captureSttyState();
        self::runStty(['cbreak', '-echo']);

        // ANSI clear-to-end-of-line wipes any leftover characters from a previous,
        // longer redraw (e.g. "(10s):" being replaced by "(9s):" would otherwise
        // leave a stray "0" on the line). This is the fix Proclaim's e6fad8700
        // applied during the 10.3.2 release.
        $clear = "\r\033[K";

        for ($remaining = $timeout; $remaining > 0; $remaining--) {
            echo $clear . $prompt . " ({$remaining}s): ";

            $read   = [STDIN];
            $write  = null;
            $except = null;
            $ready  = @stream_select($read, $write, $except, 1);

            if ($ready > 0) {
                $char = fread(STDIN, 1);
                $char = is_string($char) ? $char : '';
                self::restoreStty($oldStty);
                echo $clear . $prompt . ': ' . $char . "\n";

                return $char === '' ? $default : $char;
            }
        }

        self::restoreStty($oldStty);
        echo $clear . $prompt . ': ' . $default . " (auto)\n";

        return $default;
    }

    /**
     * Capture the terminal's current stty state so we can restore it later.
     *
     * Uses `proc_open` array form (no shell, no metachar interpretation). The
     * `stty -g` output is a single line of opaque tokens that's safe to pass
     * straight back to `stty` to restore — no parsing, no concatenation.
     */
    private static function captureSttyState(): string
    {
        $proc = @proc_open(
            ['stty', '-g'],
            [
                0 => STDIN,
                1 => ['pipe', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes
        );

        if (!is_resource($proc)) {
            return '';
        }

        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($proc);

        return is_string($out) ? trim($out) : '';
    }

    /**
     * Run `stty <args...>` with array-form proc_open.
     *
     * @param  list<string> $args
     */
    private static function runStty(array $args): void
    {
        $proc = @proc_open(
            array_merge(['stty'], $args),
            [
                0 => STDIN,
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes
        );

        if (is_resource($proc)) {
            proc_close($proc);
        }
    }

    /**
     * Restore stty state captured by captureSttyState().
     *
     * The state string is a sequence of whitespace-separated tokens; we split
     * on whitespace and pass the tokens as individual array args, so even if a
     * platform put unexpected characters in the state string, no shell metachars
     * get interpreted.
     */
    private static function restoreStty(string $oldStty): void
    {
        if ($oldStty === '') {
            return;
        }

        $tokens = preg_split('/\s+/', $oldStty) ?: [];
        $tokens = array_values(array_filter($tokens, static fn ($t) => $t !== ''));

        if ($tokens === []) {
            return;
        }

        self::runStty($tokens);
    }
}
