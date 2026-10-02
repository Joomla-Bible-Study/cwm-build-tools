<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * What Joomla is installed with. The database half is fixed by the
 * environment, so it is not here.
 */
final class JoomlaSettings
{
    /**
     * @param  string|null  $version        A release tag such as "6.1.4", or null for the latest stable.
     * @param  string       $siteName
     * @param  string       $adminUsername
     * @param  string       $adminPassword  Joomla requires at least 12 characters.
     * @param  string       $adminEmail
     * @param  string       $dbPrefix       Letters and digits then an underscore, starting with a letter.
     */
    public function __construct(
        public readonly ?string $version,
        public readonly string $siteName,
        public readonly string $adminUsername,
        public readonly string $adminPassword,
        public readonly string $adminEmail,
        public readonly string $dbPrefix,
    ) {
        if (strlen($adminPassword) < 12) {
            throw new \InvalidArgumentException('The admin password must be at least 12 characters (Joomla refuses shorter ones).');
        }

        if (!preg_match('/^[a-z][a-z0-9]{1,9}_$/', $dbPrefix)) {
            throw new \InvalidArgumentException(sprintf('Database prefix "%s" must be a letter, then letters or digits, then an underscore.', $dbPrefix));
        }

        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an email address.', $adminEmail));
        }
    }

    /**
     * A random password: letters and digits only, so it survives any shell and
     * any form field.
     */
    public static function randomPassword(int $length = 16): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out      = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /**
     * A random table prefix that satisfies Joomla's rule: starts with a letter.
     */
    public static function randomPrefix(): string
    {
        return 'j' . substr(bin2hex(random_bytes(3)), 0, 4) . '_';
    }
}
