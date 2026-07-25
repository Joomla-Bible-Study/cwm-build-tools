<?php

declare(strict_types=1);

namespace CWM\BuildTools\Config;

/**
 * Inspects `build.dist.properties` content before `cwm-sync-configs` replaces it.
 *
 * Background: `build.dist.properties` is the committed template each consumer
 * ships so a fresh clone has a starting point, while per-machine values live in
 * the gitignored `build.properties`. The two filenames differ by four characters,
 * and the wrong one gets edited often enough to matter — a developer's database
 * credentials end up in a file destined for a public repository.
 *
 * `cwm-sync-configs` overwrites the consumer's copy from the cwm-build-tools
 * template, which quietly disposes of the evidence: the credentials vanish from
 * the working tree while remaining in whatever commit already captured them, and
 * any legitimate local additions vanish with them. This class supplies the two
 * things worth saying out loud before that write happens.
 *
 * @since 1.6.2
 */
final class DistPropertiesInspector
{
    /**
     * Key suffixes whose populated value should be treated as a secret.
     *
     * Matching is on the key name rather than the value. The point is to catch a
     * local database credential sitting in a committed file, and those rarely
     * look distinctive — the case that prompted this was
     * `db_user = root` / `db_pass = root` / `db_name = j5_dev`.
     *
     * `admin_pass` is deliberately absent: the shipped template documents
     * `admin_pass = admin` as a placeholder for a throwaway local install.
     */
    private const SECRET_KEY_PATTERN = '/(db_user|db_pass|db_name|password|secret|token|api_key)$/i';

    /**
     * Keys holding a non-empty value that looks like a secret.
     *
     * @param string $properties Raw properties-file content.
     *
     * @return list<string> Fully-qualified keys, e.g. `builder.j5.db_pass`.
     */
    public function populatedSecretKeys(string $properties): array
    {
        $found = [];

        foreach (self::pairs($properties) as [$key, $value]) {
            if ($value !== '' && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                $found[] = $key;
            }
        }

        return $found;
    }

    /**
     * Keys the consumer has that the template does not — about to be removed.
     *
     * Usually that is correct (stale hand-edits), but occasionally the consumer
     * is ahead of the template: a project that added a `role = test` install
     * before the shared schema caught up. Deleting that silently is unhelpful,
     * so the caller reports it and the schema gets fixed instead.
     *
     * @param string $existing Current consumer file content.
     * @param string $template Authoritative template content.
     *
     * @return list<string>
     */
    public function keysMissingFromTemplate(string $existing, string $template): array
    {
        $keysOf = static fn (string $body): array => array_map(
            static fn (array $pair): string => $pair[0],
            self::pairs($body)
        );

        return array_values(array_diff($keysOf($existing), $keysOf($template)));
    }

    /**
     * Key/value pairs from a properties file, skipping blanks, section headers
     * and comments. Both `#` and `;` open a comment here: the template uses `#`
     * so Java-properties-aware IDEs accept it, and PHP tolerates both.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function pairs(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $pairs = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || $line[0] === ';' || $line[0] === '[') {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $pairs[] = [trim($key), trim($value)];
        }

        return $pairs;
    }
}