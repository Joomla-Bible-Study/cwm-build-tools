<?php

declare(strict_types=1);

namespace CWM\BuildTools\Config;

/**
 * Insert, replace or remove a marker-delimited block inside a hand-edited file.
 *
 * `cwm-sync-configs` maintains sections of a consumer's `.gitignore` while
 * leaving everything the consumer wrote alone. The contract is the pair of
 * marker comments: what sits between them belongs to the tool, and everything
 * outside them is none of its business.
 *
 * Extracted from scripts/sync-configs.php for #32. This rewrites a file the
 * consumer owns and has edited, so the failure modes are losing their content
 * or accumulating duplicate blocks on every sync — neither loud, both bad, and
 * neither previously covered by a test.
 */
final class ManagedBlock
{
    public static function startMarker(string $blockId): string
    {
        return "# === cwm-build-tools: {$blockId} (do not edit between markers) ===";
    }

    public static function endMarker(string $blockId): string
    {
        return "# === cwm-build-tools: end {$blockId} ===";
    }

    /**
     * Return $content with the named block set to $blockBody.
     *
     * Three cases, each of which has to be right or a consumer's file drifts:
     *
     *   - **Body is empty.** The block no longer applies to this project, so an
     *     existing one is removed rather than left as an empty pair of markers
     *     that looks like a bug.
     *   - **Block already present.** Replaced in place, preserving its position
     *     in the file — moving it to the end on every sync would produce a diff
     *     each time and train people to ignore them.
     *   - **Block absent.** Appended, separated by a blank line.
     *
     * Markers are matched literally via preg_quote, so a block id containing
     * regex metacharacters cannot turn the search into a wildcard that eats the
     * rest of the file.
     */
    public static function upsert(string $content, string $blockId, string $blockBody): string
    {
        $startMarker = self::startMarker($blockId);
        $endMarker   = self::endMarker($blockId);
        $blockBody   = trim($blockBody, "\n");

        $pattern = '/' . preg_quote($startMarker, '/') . '.*?' . preg_quote($endMarker, '/') . "\n?/s";

        if (trim($blockBody) === '') {
            return preg_replace($pattern, '', $content) ?? $content;
        }

        $newBlock = "{$startMarker}\n{$blockBody}\n{$endMarker}\n";

        if (str_contains($content, $startMarker)) {
            return preg_replace($pattern, $newBlock, $content) ?? $content;
        }

        return rtrim($content, "\n") . "\n\n" . $newBlock;
    }

    /**
     * Whether the named block is present in $content.
     */
    public static function has(string $content, string $blockId): bool
    {
        return str_contains($content, self::startMarker($blockId));
    }
}
