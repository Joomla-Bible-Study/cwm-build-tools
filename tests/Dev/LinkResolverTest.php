<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\LinkResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LinkResolverTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/manifests';

    #[Test]
    public function auto_derives_component_links_from_top_level_extension(): void
    {
        // Project root is the fixture dir that has admin/ site/ media/ siblings.
        $projectRoot = realpath(__DIR__ . '/../fixtures/project-component-toplevel');
        self::assertNotFalse($projectRoot);

        $config = [
            'extension' => ['type' => 'component', 'name' => 'com_example'],
            'manifests' => ['extensions' => []],
        ];

        $links   = (new LinkResolver($projectRoot, $config))->externalLinks('/var/www/joomla');
        $targets = array_column($links, 'target');

        self::assertContains('/var/www/joomla/administrator/components/com_example', $targets);
        self::assertContains('/var/www/joomla/components/com_example', $targets);
        self::assertContains('/var/www/joomla/media/com_example', $targets);
    }

    #[Test]
    public function media_source_is_the_plain_dir_when_assets_live_directly_under_media(): void
    {
        // Proclaim layout: manifest <media folder="media"> — assets live at
        // media/{css,js,…}. The install's media/<name> must link to media/.
        $projectRoot = realpath(__DIR__ . '/../fixtures/project-component-toplevel');
        self::assertNotFalse($projectRoot);

        $config = [
            'extension' => ['type' => 'component', 'name' => 'com_example'],
            'manifests' => ['extensions' => []],
        ];

        $links  = (new LinkResolver($projectRoot, $config))->externalLinks('/var/www/joomla');
        $source = self::mediaSourceFor($links, '/var/www/joomla/media/com_example');

        self::assertSame($projectRoot . '/media', $source);
    }

    #[Test]
    public function media_source_is_the_namespaced_dir_when_assets_live_under_media_name(): void
    {
        // com_cwmconnect / com_livingword layout: manifest
        // <media folder="media/com_example"> — assets live at media/com_example/.
        // The install's media/<name> must link there, not one level too high.
        $projectRoot = realpath(__DIR__ . '/../fixtures/project-component-namespaced-media');
        self::assertNotFalse($projectRoot);

        $config = [
            'extension' => ['type' => 'component', 'name' => 'com_example'],
            'manifests' => ['extensions' => []],
        ];

        $links  = (new LinkResolver($projectRoot, $config))->externalLinks('/var/www/joomla');
        $source = self::mediaSourceFor($links, '/var/www/joomla/media/com_example');

        self::assertSame($projectRoot . '/media/com_example', $source);
    }

    /**
     * @param  list<array{source: string, target: string}>  $links
     */
    private static function mediaSourceFor(array $links, string $target): string
    {
        foreach ($links as $link) {
            if ($link['target'] === $target) {
                return $link['source'];
            }
        }

        self::fail("No link found for target {$target}");
    }

    #[Test]
    public function auto_derives_component_links_when_listed_under_manifests_extensions(): void
    {
        // Different shape: project root has no top-level extension.component;
        // the component manifest lives under manifests.extensions[]. The
        // sibling admin/site/media dirs sit alongside the manifest path.
        $projectRoot = self::FIXTURES;

        $config = [
            // No top-level component, so deriveComponent() (not
            // deriveFromTopLevel) should fire.
            'extension' => ['type' => 'package', 'name' => 'pkg_example'],
            'manifests' => [
                'extensions' => [
                    ['type' => 'component', 'path' => 'component-listed/com_example.xml'],
                ],
            ],
        ];

        $links   = (new LinkResolver($projectRoot, $config))->externalLinks('/var/www/joomla');
        $targets = array_column($links, 'target');

        self::assertContains('/var/www/joomla/administrator/components/com_example', $targets);
        self::assertContains('/var/www/joomla/components/com_example', $targets);
        self::assertContains('/var/www/joomla/media/com_example', $targets);
    }

    #[Test]
    public function auto_derives_library_links(): void
    {
        $config = [
            'manifests' => [
                'extensions' => [
                    ['type' => 'library', 'path' => 'library/cwmscripture.xml'],
                ],
            ],
        ];

        $links = (new LinkResolver(self::FIXTURES, $config))->externalLinks('/var/www/joomla');

        $targets = array_column($links, 'target');

        // libraries/<name>
        self::assertContains('/var/www/joomla/libraries/cwmscripture', $targets);
        // administrator/manifests/libraries/<name>.xml
        self::assertContains('/var/www/joomla/administrator/manifests/libraries/cwmscripture.xml', $targets);
        // media/lib_<name>
        self::assertContains('/var/www/joomla/media/lib_cwmscripture', $targets);
    }

    #[Test]
    public function auto_derives_plugin_links_with_group_from_manifest(): void
    {
        $config = [
            'manifests' => [
                'extensions' => [
                    ['type' => 'plugin', 'path' => 'plugin/scripturelinks.xml'],
                ],
            ],
        ];

        $links = (new LinkResolver(self::FIXTURES, $config))->externalLinks('/var/www/joomla');

        self::assertCount(1, $links);
        self::assertSame('/var/www/joomla/plugins/content/scripturelinks', $links[0]['target']);
    }

    #[Test]
    public function auto_derives_module_links_for_site_and_admin_clients(): void
    {
        $config = [
            'manifests' => [
                'extensions' => [
                    ['type' => 'module', 'path' => 'module-site/mod_example.xml'],
                    ['type' => 'module', 'path' => 'module-admin/mod_adminexample.xml'],
                ],
            ],
        ];

        $links   = (new LinkResolver(self::FIXTURES, $config))->externalLinks('/var/www/joomla');
        $targets = array_column($links, 'target');

        self::assertContains('/var/www/joomla/modules/mod_example', $targets);
        self::assertContains('/var/www/joomla/administrator/modules/mod_adminexample', $targets);
    }

    #[Test]
    public function explicit_dev_links_interpolate_joomla_path_placeholder(): void
    {
        $config = [
            'manifests' => ['extensions' => []],
            'dev'       => [
                'links' => [
                    [
                        'source' => 'language/admin/en-GB/en-GB.com_example.ini',
                        'target' => '{joomlaPath}/administrator/language/en-GB/en-GB.com_example.ini',
                    ],
                ],
            ],
        ];

        $links = (new LinkResolver('/the/project', $config))->externalLinks('/var/www/joomla');

        self::assertCount(1, $links);
        self::assertSame('/the/project/language/admin/en-GB/en-GB.com_example.ini', $links[0]['source']);
        self::assertSame('/var/www/joomla/administrator/language/en-GB/en-GB.com_example.ini', $links[0]['target']);
    }

    #[Test]
    public function derive_links_false_disables_auto_derivation(): void
    {
        $config = [
            'extension' => ['type' => 'component', 'name' => 'com_example'],
            'manifests' => ['extensions' => [
                ['type' => 'library', 'path' => 'library/cwmscripture.xml'],
            ]],
            'dev' => [
                'deriveLinks' => false,
                'links'       => [
                    ['source' => 'foo', 'target' => '{joomlaPath}/foo'],
                ],
            ],
        ];

        $links = (new LinkResolver(self::FIXTURES, $config))->externalLinks('/var/www/joomla');

        self::assertCount(1, $links, 'only the explicit dev.links[] entry should remain');
        self::assertSame('/var/www/joomla/foo', $links[0]['target']);
    }

    #[Test]
    public function internal_links_resolve_against_project_root(): void
    {
        $config = [
            'dev' => [
                'internalLinks' => [
                    ['source' => 'src/Foo.php', 'link' => 'admin/src/Foo.php'],
                ],
            ],
        ];

        $links = (new LinkResolver('/the/project', $config))->internalLinks();

        self::assertCount(1, $links);
        self::assertSame('/the/project/src/Foo.php', $links[0]['source']);
        self::assertSame('/the/project/admin/src/Foo.php', $links[0]['target']);
    }

    #[Test]
    public function external_links_dedupe_by_target(): void
    {
        $config = [
            'manifests' => ['extensions' => [
                ['type' => 'plugin', 'path' => 'plugin/scripturelinks.xml'],
            ]],
            'dev' => [
                'links' => [
                    // Same target as the auto-derived plugin link — should
                    // collapse to one.
                    [
                        'source' => 'plugin',
                        'target' => '{joomlaPath}/plugins/content/scripturelinks',
                    ],
                ],
            ],
        ];

        $links = (new LinkResolver(self::FIXTURES, $config))->externalLinks('/var/www/joomla');

        self::assertCount(1, $links);
    }

    #[Test]
    public function an_explicit_link_overrides_the_auto_derived_pair_for_the_same_target(): void
    {
        // Without this, dev.deriveLinks:false -- hand-write every link for the
        // project -- was the only way to correct a single derived pair.
        $projectRoot = realpath(__DIR__ . '/../fixtures/project-component-toplevel');
        self::assertNotFalse($projectRoot);

        $config = [
            'extension' => ['type' => 'component', 'name' => 'com_example'],
            'manifests' => ['extensions' => []],
            'dev'       => ['links' => [
                ['source' => 'media/somewhere-else', 'target' => '{joomlaPath}/media/com_example'],
            ]],
        ];

        $links  = (new LinkResolver($projectRoot, $config))->externalLinks('/var/www/joomla');
        $source = self::mediaSourceFor($links, '/var/www/joomla/media/com_example');

        self::assertSame($projectRoot . '/media/somewhere-else', $source);
        self::assertCount(
            3,
            $links,
            'the override replaces the derived pair rather than adding a second one',
        );
    }

    #[Test]
    public function an_overriding_link_keeps_the_derived_pair_position(): void
    {
        $projectRoot = realpath(__DIR__ . '/../fixtures/project-component-toplevel');
        self::assertNotFalse($projectRoot);

        $config = [
            'extension' => ['type' => 'component', 'name' => 'com_example'],
            'manifests' => ['extensions' => []],
            'dev'       => ['links' => [
                ['source' => 'media/somewhere-else', 'target' => '{joomlaPath}/media/com_example'],
            ]],
        ];

        $links = (new LinkResolver($projectRoot, $config))->externalLinks('/var/www/joomla');

        self::assertSame(
            [
                '/var/www/joomla/administrator/components/com_example',
                '/var/www/joomla/components/com_example',
                '/var/www/joomla/media/com_example',
            ],
            array_column($links, 'target'),
        );
    }

    #[Test]
    public function stray_media_name_dir_does_not_override_a_manifest_declaring_the_flat_layout(): void
    {
        // The regression this class of bug produces: an empty media/<name>/
        // left behind by something else (a test teardown, an aborted build) is
        // indistinguishable from a real namespaced layout by is_dir() alone.
        // The manifest says folder="media", so the flat source must win.
        $stray = self::FIXTURES . '/component-flat-media/media/com_example';
        mkdir($stray, 0o777, true);

        try {
            $config = [
                'extension' => ['type' => 'package', 'name' => 'pkg_example'],
                'manifests' => ['extensions' => [
                    ['type' => 'component', 'path' => 'component-flat-media/com_example.xml'],
                ]],
            ];

            $links  = (new LinkResolver(self::FIXTURES, $config))->externalLinks('/var/www/joomla');
            $source = self::mediaSourceFor($links, '/var/www/joomla/media/com_example');

            self::assertSame(self::FIXTURES . '/component-flat-media/media', $source);
        } finally {
            rmdir($stray);
        }
    }

    #[Test]
    public function manifest_declaring_a_namespaced_media_folder_resolves_there(): void
    {
        $namespaced = self::FIXTURES . '/component-declared-media-missing/media/com_example';
        mkdir($namespaced, 0o777, true);

        try {
            $config = [
                'extension' => ['type' => 'package', 'name' => 'pkg_example'],
                'manifests' => ['extensions' => [
                    ['type' => 'component', 'path' => 'component-declared-media-missing/com_example.xml'],
                ]],
            ];

            $links  = (new LinkResolver(self::FIXTURES, $config))->externalLinks('/var/www/joomla');
            $source = self::mediaSourceFor($links, '/var/www/joomla/media/com_example');

            self::assertSame($namespaced, $source);
        } finally {
            rmdir($namespaced);
        }
    }

    #[Test]
    public function declared_media_folder_missing_on_disk_yields_no_media_link(): void
    {
        // Better to emit nothing than to link media/<name> at the parent and
        // expose the whole media tree because the declared folder is unbuilt.
        $config = [
            'extension' => ['type' => 'package', 'name' => 'pkg_example'],
            'manifests' => ['extensions' => [
                ['type' => 'component', 'path' => 'component-declared-media-missing/com_example.xml'],
            ]],
        ];

        $links   = (new LinkResolver(self::FIXTURES, $config))->externalLinks('/var/www/joomla');
        $targets = array_column($links, 'target');

        self::assertNotContains('/var/www/joomla/media/com_example', $targets);
        // The other two links still derive, so this is not a vacuous pass.
        self::assertContains('/var/www/joomla/administrator/components/com_example', $targets);
    }

    #[Test]
    public function media_folder_escaping_the_extension_root_is_ignored(): void
    {
        $config = [
            'extension' => ['type' => 'package', 'name' => 'pkg_example'],
            'manifests' => ['extensions' => [
                ['type' => 'component', 'path' => 'component-unsafe-media/com_example.xml'],
            ]],
        ];

        $links  = (new LinkResolver(self::FIXTURES, $config))->externalLinks('/var/www/joomla');
        $source = self::mediaSourceFor($links, '/var/www/joomla/media/com_example');

        self::assertSame(self::FIXTURES . '/component-unsafe-media/media', $source);
    }
}
