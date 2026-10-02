<?php

declare(strict_types=1);

namespace CWM\BuildTools\Seed;

use CWM\BuildTools\Dev\InstallConfig;
use CWM\BuildTools\Dev\PropertiesReader;

/**
 * Chooses which installs a seed run touches.
 *
 * Seeding writes rows into a site's database, so what it may aim at is decided
 * here and not left to the layer scripts. Only an install listed in the
 * developer's build.properties is ever a target, so a site the developer never
 * registered (a production one, say) cannot be reached by mistake.
 *
 * With no name, the targets are the `role=test` installs: sites that exist to be
 * wiped and filled. A `role=dev` site is somebody's working copy, so it is seeded
 * only when named. Naming it is the consent.
 */
final class SeedTarget
{
    /**
     * @return list<InstallConfig>
     *
     * @throws SeedException  when nothing can be targeted, with what to do about it
     */
    public static function select(PropertiesReader $reader, ?string $id): array
    {
        if (!$reader->exists()) {
            throw new SeedException("build.properties not found. Run this from the project, after cwm-setup or cwm-site-create.");
        }

        $all = $reader->installs();

        if ($id !== null) {
            foreach ($all as $install) {
                if ($install->id !== $id) {
                    continue;
                }

                if (!is_dir($install->path)) {
                    throw new SeedException(sprintf('Install "%s" points at %s, which does not exist.', $id, $install->path));
                }

                return [$install];
            }

            $known = array_map(static fn (InstallConfig $i): string => $i->id, $all);

            throw new SeedException(sprintf('No install "%s" in build.properties. Known installs: %s.', $id, $known === [] ? '(none)' : implode(', ', $known)));
        }

        $tests = array_values(array_filter(
            $reader->installsFor(InstallConfig::ROLE_TEST),
            static fn (InstallConfig $i): bool => is_dir($i->path)
        ));

        if ($tests === []) {
            throw new SeedException(
                "No role=test install to seed. Seeding a dev site is allowed, but only when you name it:\n"
                . '  cwm-seed <profile> --install <id>'
            );
        }

        return $tests;
    }
}
