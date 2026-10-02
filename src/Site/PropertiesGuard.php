<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Refuses to put credentials into a build.properties that git would commit.
 *
 * The file holds database and admin passwords, which is what it is for, on the
 * understanding that it is gitignored. A project that forgot to ignore it would
 * have those passwords one `git add .` from a public repository.
 */
final class PropertiesGuard
{
    public function __construct(private readonly CommandRunner $runner)
    {
    }

    /**
     * @throws SiteException  when $file is inside a git repository and not ignored by it
     */
    public function assertSafeToHoldCredentials(string $projectRoot, string $file = 'build.properties'): void
    {
        // 0 ignored, 1 not ignored, 128 not a repository (or git is unavailable).
        $result = $this->runner->run(['git', 'check-ignore', '--quiet', '--', $file], $projectRoot);

        if ($result->exitCode !== 1) {
            return;
        }

        throw new SiteException(
            "{$file} is not gitignored in {$projectRoot}, and this command writes the site's database and admin\n"
            . "passwords into it. Ignore it first, then run this again:\n"
            . "  echo '{$file}' >> .gitignore\n"
            . 'Or pass --no-register to leave it alone.'
        );
    }
}
