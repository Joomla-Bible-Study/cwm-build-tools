<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * The decisions inside cwm-setup's install prompt loop, extracted from
 * scripts/setup.php so they can be tested (#32). The script keeps the
 * prompts and echoes; every judgement about the answers lives here.
 *
 * The extraction also fixed a real defect: the loop rebuilt each
 * InstallConfig without passing `role`, so re-running `composer setup` on
 * a project with a `role = test` install silently flipped it back to
 * `dev`. A dev-role site is what `cwm-link` symlinks — and a symlinked
 * "test" site is the precondition for the teardown-deletes-the-repo
 * incident role separation exists to prevent. assemble() preserves the
 * existing role unless an explicit answer changes it.
 */
final class InstallIntake
{
    /**
     * Validate and normalise an install id as typed.
     *
     * Section names in build.properties are lowercase by convention; the
     * legacy Proclaim mapping (j5dev → j5) and the example template both
     * assume lowercase. Normalising here means a user typing "J5" still
     * ends up matching what LinkResolver and ExtensionVerifier expect.
     *
     * @return string|null  The normalised id, or null when invalid.
     */
    public function normaliseId(string $id): ?string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $id)) {
            return null;
        }

        return strtolower($id);
    }

    /**
     * Validate a role answer.
     *
     * @return string|null  'dev' or 'test', or null when unrecognised.
     */
    public function normaliseRole(string $role): ?string
    {
        $role = strtolower(trim($role));

        return in_array($role, [InstallConfig::ROLE_DEV, InstallConfig::ROLE_TEST], true) ? $role : null;
    }

    /**
     * Build the InstallConfig for one prompted install.
     *
     * @param  string  $id  Already through normaliseId().
     * @param  array{
     *     path: string,
     *     url?: string|null,
     *     version?: string|null,
     *     role?: string|null,
     *     dbHost?: string|null,
     *     dbUser?: string|null,
     *     dbPass?: string|null,
     *     dbName?: string|null,
     *     adminUser?: string|null,
     *     adminPass?: string|null,
     *     adminEmail?: string|null,
     * }  $answers  Trimmed prompt answers; null/'' means "no answer".
     * @param  InstallConfig|null  $existing  The install as currently
     *                                        configured, when re-running.
     */
    public function assemble(string $id, array $answers, ?InstallConfig $existing = null): InstallConfig
    {
        // Role precedence: an explicit answer, else whatever the install
        // already was, else dev. Losing the existing role is never right —
        // see the class docblock.
        $role = $answers['role'] ?? null;

        if ($role === null || $role === '') {
            $role = $existing?->role ?? InstallConfig::ROLE_DEV;
        }

        return new InstallConfig(
            id:      $id,
            path:    $answers['path'],
            url:     ($answers['url'] ?? '') !== '' ? $answers['url'] : null,
            version: ($answers['version'] ?? '') !== '' ? $answers['version'] : null,
            db:      [
                'host' => ($answers['dbHost'] ?? '') !== '' ? $answers['dbHost'] : 'localhost',
                'user' => $answers['dbUser'] ?? '',
                'pass' => $answers['dbPass'] ?? '',
                'name' => $answers['dbName'] ?? '',
            ],
            admin:   [
                'user'  => ($answers['adminUser'] ?? '') !== '' ? $answers['adminUser'] : 'admin',
                'pass'  => ($answers['adminPass'] ?? '') !== '' ? $answers['adminPass'] : 'admin',
                'email' => ($answers['adminEmail'] ?? '') !== '' ? $answers['adminEmail'] : 'admin@example.com',
            ],
            role:    $role,
        );
    }
}
