<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * Configuration for one Joomla installation referenced from build.properties.
 *
 * Each install has a short id ("j5", "j6", "j7"), a filesystem path, an
 * optional URL, a target Joomla version (used by cwm-joomla-install), and
 * DB + admin credential blocks used by cwm-verify and the optional install
 * step inside cwm-setup.
 *
 * Each install also has a `role` — `dev` (the default; the symlink-style
 * working install where `cwm-link` deploys) or `test` (the artifact-style
 * install where `cwm-install-zip` deploys a built zip). Multiple installs
 * may share a role (e.g. j5 and j6 both as dev), in which case commands
 * apply to each in turn.
 */
final class InstallConfig
{
    public const ROLE_DEV  = 'dev';
    public const ROLE_TEST = 'test';

    /**
     * @param  string  $id           Short identifier ("j5", "j6", ...).
     * @param  string  $path         Absolute path to the Joomla document root.
     * @param  string|null  $url     Public dev URL (informational; not required).
     * @param  string|null  $version Default Joomla release tag for installer.
     * @param  array<string, string>  $db    DB credentials (host, user, pass, name).
     * @param  array<string, string>  $admin Admin credentials (user, pass, email).
     * @param  string $role          Either 'dev' (default — symlink target) or
     *                               'test' (artifact-install target).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly ?string $url = null,
        public readonly ?string $version = null,
        public readonly array $db = [],
        public readonly array $admin = [],
        public readonly string $role = self::ROLE_DEV,
    ) {
    }

    public function dbHost(): string
    {
        return $this->db['host'] ?? 'localhost';
    }

    public function dbUser(): string
    {
        return $this->db['user'] ?? '';
    }

    public function dbPass(): string
    {
        return $this->db['pass'] ?? '';
    }

    public function dbName(): string
    {
        return $this->db['name'] ?? '';
    }

    public function adminUser(): string
    {
        return $this->admin['user'] ?? 'admin';
    }

    public function adminPass(): string
    {
        return $this->admin['pass'] ?? 'admin';
    }

    public function adminEmail(): string
    {
        return $this->admin['email'] ?? 'admin@example.com';
    }
}
