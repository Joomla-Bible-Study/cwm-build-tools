<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * A site as it is recorded in build.properties, so the other dev commands
 * (`cwm-link`, `cwm-verify`, `cwm-reset-testsite`) can find it.
 */
final class RegisteredSite
{
    /**
     * @param  string  $id       Becomes the `builder.<id>.*` key prefix; letters, digits, hyphen, underscore.
     * @param  string  $role     "dev" (source is linked in) or "test" (the built package stays installed).
     * @param  string  $dbHost   As the host reaches the database, e.g. "127.0.0.1:33061".
     */
    public function __construct(
        public readonly string $id,
        public readonly string $role,
        public readonly string $path,
        public readonly string $url,
        public readonly ?string $version,
        public readonly string $dbHost,
        public readonly string $dbUser,
        public readonly string $dbPass,
        public readonly string $dbName,
        public readonly string $adminUser,
        public readonly string $adminPass,
        public readonly string $adminEmail,
    ) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $id)) {
            throw new \InvalidArgumentException(sprintf('Site id "%s" can only use letters, digits, hyphen and underscore.', $id));
        }

        // PropertiesReader strips a trailing "dev" from every id it reads (Proclaim's old
        // `j5dev` -> `j5`), so such a site would be written under one name and reported under
        // another, and `--install <id>` would never match it.
        if (str_ends_with($id, 'dev')) {
            throw new \InvalidArgumentException(sprintf(
                'Site id "%s" ends in "dev", which build.properties readers strip, so the site would be reported as "%s". Pick an id that does not end in "dev".',
                $id,
                preg_replace('/dev$/', '', $id)
            ));
        }

        if (!\in_array($role, ['dev', 'test'], true)) {
            throw new \InvalidArgumentException(sprintf('Role "%s" must be dev or test.', $role));
        }

        foreach (['path' => $path, 'url' => $url, 'dbHost' => $dbHost, 'dbUser' => $dbUser, 'dbPass' => $dbPass, 'dbName' => $dbName, 'adminUser' => $adminUser, 'adminPass' => $adminPass, 'adminEmail' => $adminEmail] as $field => $value) {
            if (preg_match('/[\r\n]/', $value)) {
                throw new \InvalidArgumentException(sprintf('%s must not contain a line break; it is written into a properties file.', $field));
            }
        }
    }
}
