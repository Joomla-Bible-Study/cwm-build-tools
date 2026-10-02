<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

use CWM\BuildTools\Config\CwmPackage;

/**
 * The decisions `cwm-link` makes, separated from the printing and the symlinking.
 *
 * Those decisions used to live in scripts/link.php, where nothing could reach
 * them. That is how the v1.6.1 defect shipped: the script called
 * `PropertiesReader::installs()` instead of `installsFor(ROLE_DEV)`, so
 * `composer symlink` linked test installs as well as dev ones — pointing a
 * file-backed test install's extension directories back at the working repo,
 * and putting a consumer's source inside the blast radius of any teardown that
 * deletes extension dirs.
 *
 * `installsFor()` itself was thoroughly covered. The bug was in the caller, and
 * 214 tests passed for as long as it existed. Moving the choice here is what
 * makes it assertable — see issue #32.
 */
final class LinkPlanner
{
    /**
     * Split configured installs into the ones cwm-link should touch and the
     * ones it must not.
     *
     * Role filtering happens here rather than at the call site precisely
     * because it is the part that was wrong before. `role=test` installs are
     * excluded by design, not by omission: they are real file-backed
     * installations that the release harness wipes and reinstalls, so a symlink
     * into the working repo means the harness deletes the repo's source.
     *
     * A configured path that does not exist is reported separately rather than
     * dropped. It is usually a stale entry or a machine that has not been set
     * up yet, and silence there reads as "nothing to do".
     *
     * @param   PropertiesReader  $reader  Reader for the consumer's build.properties.
     *
     * @return  array{linkable: list<InstallConfig>, missing: list<InstallConfig>}
     */
    public static function selectInstalls(PropertiesReader $reader): array
    {
        $linkable = [];
        $missing  = [];

        foreach ($reader->installsFor(InstallConfig::ROLE_DEV) as $install) {
            if (is_dir($install->path)) {
                $linkable[] = $install;

                continue;
            }

            $missing[] = $install;
        }

        return ['linkable' => $linkable, 'missing' => $missing];
    }

    /**
     * Select one install by id, for `cwm-link --install <id>`.
     *
     * The role rule still applies: asking for a `role=test` install by name is
     * refused, not honoured. Naming it is not a reason to link it; that is the
     * defect {@see self::selectInstalls()} exists to prevent.
     *
     * @throws  \RuntimeException  when the id is unknown, the install is not role=dev,
     *                             or its folder is missing; the message says which
     */
    public static function selectInstall(PropertiesReader $reader, string $id): InstallConfig
    {
        $all = $reader->installs();

        foreach ($all as $install) {
            if ($install->id !== $id) {
                continue;
            }

            if ($install->role !== InstallConfig::ROLE_DEV) {
                throw new \RuntimeException(sprintf(
                    'Install "%s" is role=%s. Only role=dev installs are linked: a test install is wiped and reinstalled by the release harness, and a link would put your source in its path.',
                    $id,
                    $install->role
                ));
            }

            if (!is_dir($install->path)) {
                throw new \RuntimeException(sprintf('Install "%s" points at %s, which does not exist.', $id, $install->path));
            }

            return $install;
        }

        $known = array_map(static fn (InstallConfig $i): string => $i->id, $all);

        throw new \RuntimeException(sprintf(
            'No install "%s" in build.properties. Known installs: %s.',
            $id,
            $known === [] ? '(none)' : implode(', ', $known)
        ));
    }

    /**
     * Group dependency links by the package that owns them.
     *
     * Preserves both the order packages first appear and the order of links
     * within a package, so the script's output is stable between runs. Unstable
     * ordering in a tool people read line by line is its own small bug.
     *
     * @param   list<array{package: string, source: string, target: string}>  $depLinks
     *
     * @return  array<string, list<array{package: string, source: string, target: string}>>
     */
    public static function groupByPackage(array $depLinks): array
    {
        $grouped = [];

        foreach ($depLinks as $link) {
            $grouped[$link['package']][] = $link;
        }

        return $grouped;
    }

    /**
     * Find a package by name among those installed.
     *
     * @param   list<CwmPackage>  $packages
     */
    public static function findPackage(array $packages, string $name): ?CwmPackage
    {
        foreach ($packages as $package) {
            if ($package->name === $name) {
                return $package;
            }
        }

        return null;
    }

    /**
     * The " @ version (path|registry)" suffix shown beside a dependency.
     *
     * Whether a package came from a path repository or the registry decides
     * whether editing it locally has any effect, so it is worth stating in the
     * output rather than leaving someone to wonder why their changes vanished.
     *
     * Returns an empty string for a package that is not installed, so the
     * caller can concatenate unconditionally.
     */
    public static function describePackage(?CwmPackage $package): string
    {
        if ($package === null) {
            return '';
        }

        return ' @ ' . $package->version . ' (' . ($package->isPathRepo ? 'path' : 'registry') . ')';
    }
}
