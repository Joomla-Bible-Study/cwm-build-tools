<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

use CWM\BuildTools\Config\ManagedBlock;

/**
 * Records a site in a developer's build.properties without disturbing the rest
 * of the file.
 *
 * `PropertiesReader::write()` is not used: it regenerates the whole file, drops
 * comments and keys it does not know, and does not re-emit database
 * credentials. This file is hand-edited and holds a developer's other sites, so
 * the site goes in as a marker-delimited block (which a re-run replaces in
 * place) and the only line touched outside it is the `builder.installs` list.
 */
final class SiteRegistrar
{
    public function blockId(string $siteId): string
    {
        return 'site ' . $siteId;
    }

    /**
     * The block's body, in the flat `builder.<id>.*` form PropertiesReader reads.
     */
    public function block(RegisteredSite $site): string
    {
        $p = 'builder.' . $site->id;

        return implode("\n", [
            "# {$site->id}: created by cwm-site-create ({$site->role}). Replaced when it is run again for this id.",
            "{$p}.role={$site->role}",
            "{$p}.path={$site->path}",
            "{$p}.url={$site->url}",
            "{$p}.version=" . ($site->version ?? ''),
            "{$p}.db_host={$site->dbHost}",
            "{$p}.db_user={$site->dbUser}",
            "{$p}.db_pass={$site->dbPass}",
            "{$p}.db_name={$site->dbName}",
            "{$p}.admin_user={$site->adminUser}",
            "{$p}.admin_pass={$site->adminPass}",
            "{$p}.admin_email={$site->adminEmail}",
        ]);
    }

    /**
     * Whether the id is already defined by lines that are not this tool's own
     * block. Registering over them would leave two definitions of the same keys.
     */
    public function definedElsewhere(string $content, string $siteId): bool
    {
        $outside = $this->withoutBlock($content, $siteId);

        return (bool) preg_match('/^[ \t]*builder\.' . preg_quote($siteId, '/') . '\.[A-Za-z_]+[ \t]*=/m', $outside);
    }

    /**
     * Add $siteId to the `builder.installs` list if there is one and it is not
     * already in it. Without that list, PropertiesReader discovers installs from
     * their keys; with it, an id left off the list is invisible.
     */
    public function withInstallListed(string $content, string $siteId): string
    {
        $replaced = preg_replace_callback(
            '/^([ \t]*builder\.installs[ \t]*=[ \t]*)(.*?)([ \t]*)$/m',
            static function (array $m) use ($siteId): string {
                $ids = array_values(array_filter(array_map('trim', explode(',', $m[2])), static fn (string $s): bool => $s !== ''));

                if (\in_array($siteId, $ids, true)) {
                    return $m[0];
                }

                $ids[] = $siteId;

                return $m[1] . implode(', ', $ids) . $m[3];
            },
            $content,
            1
        );

        return $replaced ?? $content;
    }

    /**
     * The file's new contents with $site recorded.
     *
     * @throws SiteException  when the id is already defined outside this tool's block
     */
    public function apply(string $content, RegisteredSite $site): string
    {
        if ($this->definedElsewhere($content, $site->id)) {
            throw new SiteException(sprintf(
                'build.properties already defines "%s" by hand (builder.%s.* outside a cwm-site-create block). '
                . 'Pick another site id, or remove those lines first.',
                $site->id,
                $site->id
            ));
        }

        $content = $content === '' ? "# Local installs for cwm-build-tools dev commands. Gitignored; per-developer.\n" : $content;

        return $this->withInstallListed(ManagedBlock::upsert($content, $this->blockId($site->id), $this->block($site)), $site->id);
    }

    /**
     * Register $site in the file at $path, creating it when it is absent.
     *
     * Written to a temporary file and renamed, so an interrupted write cannot
     * leave a half-written file in place of the developer's.
     *
     * @throws SiteException
     */
    public function register(string $path, RegisteredSite $site): void
    {
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $updated  = $this->apply($existing, $site);

        if ($updated === $existing) {
            return;
        }

        $tmp = $path . '.cwm-tmp-' . bin2hex(random_bytes(3));

        if (@file_put_contents($tmp, $updated) === false) {
            throw new SiteException('Could not write ' . $tmp . '.');
        }

        if (is_file($path)) {
            @chmod($tmp, fileperms($path) & 0o777);
        } else {
            @chmod($tmp, 0o600);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw new SiteException('Could not replace ' . $path . '.');
        }
    }

    private function withoutBlock(string $content, string $siteId): string
    {
        return ManagedBlock::upsert($content, $this->blockId($siteId), '');
    }
}
