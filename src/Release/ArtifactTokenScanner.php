<?php

declare(strict_types=1);

namespace CWM\BuildTools\Release;

use ZipArchive;

/**
 * Scans a built artifact for placeholder tokens that release-time substitution
 * should already have replaced, recursing into nested zips.
 *
 * `TokenSubstituter` rewrites `__DEPLOY_VERSION__` across the directories a
 * project lists in `substituteTokens.paths`. That list is hand-maintained and
 * validated against nothing, so whether a release ships the literal token comes
 * down to whether someone remembered every directory that ships. Three times it
 * did not:
 *
 * - #75 — `package.installer` lives in `build/`, which no project lists because
 *   the rest of that directory is tooling. The one shipped file in there was the
 *   one never substituted.
 * - CWMScriptureLinks#27 — `src/` resolves from the repo root, so a bare `src/`
 *   entry covered one of three sub-extensions. Two zips shipped literal tokens
 *   through two releases with nobody noticing.
 * - CWMScriptureLinks#29 — that repo's vendored copy of these tools predated
 *   #75's fix, so the installer shipped ten literal tags even after #27.
 *
 * Every one of those is invisible to a check that reads configuration. This
 * class answers the only question that survives a stale vendor tree, a moved
 * directory or a new sub-extension: **is the token in the bytes we are about to
 * publish?**
 *
 * It deliberately does NOT filter by `substituteTokens.extensions`. That option
 * defaults to `['php']`, which is exactly why a token in an XML manifest or a JS
 * file would ship unnoticed — the assertion has to be independent of what the
 * substituter covers, or it inherits the blind spot it exists to catch.
 */
final class ArtifactTokenScanner
{
    private const DEFAULT_TOKEN = '__DEPLOY_VERSION__';

    /**
     * Entries larger than this are skipped unread.
     *
     * Source files carrying `@since` tags are kilobytes. Anything in the
     * megabytes is a bundled asset, a sprite sheet or a vendored library, and
     * reading it costs more than the finding is worth.
     */
    private const MAX_ENTRY_BYTES = 4 * 1024 * 1024;

    /**
     * How many levels of nested zip to open.
     *
     * `pkg_proclaim.zip` → `packages/pkg_cwmscripture.zip` → `lib_cwmscripture.zip`
     * is three, so the default clears the deepest real case with room to spare
     * while still refusing to follow a maliciously self-nesting archive forever.
     */
    private const MAX_DEPTH = 5;

    public function __construct(
        private readonly string $token = self::DEFAULT_TOKEN,
    ) {
    }

    /**
     * @return list<array{path: string, line: int, text: string}>
     *         Every occurrence, in archive order. Empty means the artifact is clean.
     */
    public function scan(string $archivePath): array
    {
        return $this->scanArchive($archivePath, basename($archivePath), 1);
    }

    /**
     * @return list<array{path: string, line: int, text: string}>
     */
    private function scanArchive(string $archivePath, string $displayPath, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        $zip = new ZipArchive();

        if ($zip->open($archivePath) !== true) {
            // A child that will not open is reported rather than thrown: the
            // caller is a release gate, and "one entry was unreadable" should
            // not be indistinguishable from "the artifact is clean".
            return [[
                'path' => $displayPath,
                'line' => 0,
                'text' => 'could not be opened as a zip archive',
            ]];
        }

        $findings = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false || str_ends_with($stat['name'], '/')) {
                continue;
            }

            $entryPath = $displayPath . '!' . $stat['name'];

            if (str_ends_with(strtolower($stat['name']), '.zip')) {
                $findings = array_merge(
                    $findings,
                    $this->scanNestedZip($zip, $i, $entryPath, $depth),
                );
                continue;
            }

            if ($stat['size'] > self::MAX_ENTRY_BYTES) {
                continue;
            }

            $contents = $zip->getFromIndex($i);

            if ($contents === false || !$this->isText($contents)) {
                continue;
            }

            $findings = array_merge($findings, $this->scanText($contents, $entryPath));
        }

        $zip->close();

        return $findings;
    }

    /**
     * Nested archives have to hit disk — ZipArchive cannot open a stream, and a
     * package's children are themselves zips.
     *
     * @return list<array{path: string, line: int, text: string}>
     */
    private function scanNestedZip(ZipArchive $parent, int $index, string $entryPath, int $depth): array
    {
        $contents = $parent->getFromIndex($index);

        if ($contents === false) {
            return [];
        }

        $temp = tempnam(sys_get_temp_dir(), 'cwm-artifact-scan-');

        if ($temp === false) {
            return [];
        }

        try {
            file_put_contents($temp, $contents);

            return $this->scanArchive($temp, $entryPath, $depth + 1);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @return list<array{path: string, line: int, text: string}>
     */
    private function scanText(string $contents, string $entryPath): array
    {
        if (!str_contains($contents, $this->token)) {
            return [];
        }

        $findings = [];

        foreach (explode("\n", $contents) as $number => $line) {
            if (str_contains($line, $this->token)) {
                $findings[] = [
                    'path' => $entryPath,
                    'line' => $number + 1,
                    'text' => trim($line),
                ];
            }
        }

        return $findings;
    }

    /**
     * Binary entries are skipped rather than filtered by extension, so a token
     * in an unexpected file type is still caught.
     */
    private function isText(string $contents): bool
    {
        if ($contents === '') {
            return false;
        }

        if (str_contains($contents, "\0")) {
            return false;
        }

        return mb_check_encoding($contents, 'UTF-8');
    }
}
