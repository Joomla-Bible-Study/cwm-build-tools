<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

use CWM\BuildTools\Dev\InstallConfig;
use CWM\BuildTools\Dev\PropertiesReader;

/**
 * Finds a site that cwm-site-create made, and nothing else.
 *
 * `cwm-site-reset` and `cwm-site-remove` act on a site's files and database. A
 * site recorded by hand, or by another tool, may be something the developer
 * cares about in ways this tool cannot see, so only a site with the marker block
 * this tool writes is eligible.
 */
final class SiteLookup
{
    public function __construct(private readonly SiteRegistrar $registrar = new SiteRegistrar())
    {
    }

    /**
     * @throws SiteException  saying what is wrong and what is available
     */
    public function find(string $propertiesPath, string $siteId): InstallConfig
    {
        if (!is_file($propertiesPath)) {
            throw new SiteException(dirname($propertiesPath) . '/build.properties not found. Run this from the project that created the site.');
        }

        $content = (string) file_get_contents($propertiesPath);
        $created = $this->registrar->recordedIds($content);

        if (!\in_array($siteId, $created, true)) {
            $byHand = $this->registrar->definedElsewhere($content, $siteId);

            throw new SiteException(
                $byHand
                    ? sprintf('"%s" is in build.properties but cwm-site-create did not record it, so this will not touch it. Remove it by hand.', $siteId)
                    : sprintf(
                        'No site "%s" was created by cwm-site-create here. %s',
                        $siteId,
                        $created === [] ? 'None have been.' : 'Known: ' . implode(', ', $created) . '.'
                    )
            );
        }

        foreach ((new PropertiesReader($propertiesPath))->installs() as $install) {
            if ($install->id === $siteId) {
                return $install;
            }
        }

        throw new SiteException(sprintf('"%s" has a cwm-site-create block in build.properties that could not be read as an install.', $siteId));
    }
}
