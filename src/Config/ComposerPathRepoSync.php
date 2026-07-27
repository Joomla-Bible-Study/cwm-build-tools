<?php

declare(strict_types=1);

namespace CWM\BuildTools\Config;

/**
 * Synchronise composer.json's `repositories[]` with the developer's CWM
 * sibling paths, extracted from scripts/setup.php so the mutation can be
 * tested (#32).
 *
 * This is the highest-consequence code cwm-setup runs: it rewrites a
 * consuming project's composer.json. The class works on the decoded
 * array and reports whether anything changed; reading, encoding and
 * writing the file stay with the caller.
 *
 * Matching is by basename of the existing path-repo URL against the
 * package name's basename (dash and underscore variants), because the
 * composer name ("cwm/lib-cwmscripture") and the checkout directory
 * ("lib_cwmscripture") routinely disagree about separators. Entries of
 * other types (vcs, composer, …) are never touched.
 */
final class ComposerPathRepoSync
{
    /**
     * @param  array<string, mixed>   $composerData  Decoded composer.json.
     * @param  array<string, string>  $resolved      Package name → absolute path.
     *
     * @return array{data: array<string, mixed>, changed: bool}
     */
    public function sync(array $composerData, array $resolved): array
    {
        $composerData['repositories'] = $composerData['repositories'] ?? [];

        if (!is_array($composerData['repositories'])) {
            // Somebody made repositories an object/string we don't
            // understand — leave it alone rather than guess.
            return ['data' => $composerData, 'changed' => false];
        }

        $existingByBase = [];

        foreach ($composerData['repositories'] as $i => $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'path') {
                $existingByBase[basename((string) ($entry['url'] ?? ''))] = $i;
            }
        }

        $changed = false;

        foreach ($resolved as $package => $absolutePath) {
            $base = basename($package);

            $candidates = array_unique([
                $base,
                str_replace('-', '_', $base),
                str_replace('_', '-', $base),
            ]);

            $existingIndex = null;

            foreach ($candidates as $candidate) {
                if (isset($existingByBase[$candidate])) {
                    $existingIndex = $existingByBase[$candidate];
                    break;
                }
            }

            if ($existingIndex !== null) {
                if (($composerData['repositories'][$existingIndex]['url'] ?? null) !== $absolutePath) {
                    $composerData['repositories'][$existingIndex]['url'] = $absolutePath;
                    $changed = true;
                }

                continue;
            }

            $composerData['repositories'][] = [
                'type'    => 'path',
                'url'     => $absolutePath,
                'options' => ['symlink' => true],
            ];
            $changed = true;
        }

        return ['data' => $composerData, 'changed' => $changed];
    }
}