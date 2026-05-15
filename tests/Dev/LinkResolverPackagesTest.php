<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Config\CwmPackage;
use CWM\BuildTools\Dev\LinkResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LinkResolverPackagesTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-link-resolver-pkgs-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    #[Test]
    public function derives_library_links_into_joomla_install_layout(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/scripturelinks';
        mkdir($pkgRoot, 0o777, true);
        file_put_contents($pkgRoot . '/cwmscripturelinks.xml', '<extension/>');

        $pkg = new CwmPackage(
            name: 'cwm/scripturelinks',
            version: '2.1.4',
            versionNormalized: '2.1.4.0',
            joomlaLinks: [['type' => 'library', 'name' => 'cwmscripturelinks']],
            installPath: $pkgRoot,
            isPathRepo: false,
            sourcePath: null,
            reference: 'aaaa',
        );

        $resolver = new LinkResolver($this->tmpDir, []);
        $links    = $resolver->externalLinksForPackages('/joomla', [$pkg]);

        self::assertSame(
            [
                [
                    'source'  => $pkgRoot,
                    'target'  => '/joomla/libraries/cwmscripturelinks',
                    'package' => 'cwm/scripturelinks',
                ],
                [
                    'source'  => $pkgRoot . '/cwmscripturelinks.xml',
                    'target'  => '/joomla/administrator/manifests/libraries/cwmscripturelinks.xml',
                    'package' => 'cwm/scripturelinks',
                ],
            ],
            $links,
        );
    }

    #[Test]
    public function library_media_dir_link_emitted_when_present_on_disk(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/scripturelinks';
        mkdir($pkgRoot . '/media/lib_cwmscripturelinks', 0o777, true);

        $pkg = $this->package(
            $pkgRoot,
            [['type' => 'library', 'name' => 'cwmscripturelinks']],
        );

        $links   = (new LinkResolver($this->tmpDir, []))->externalLinksForPackages('/joomla', [$pkg]);
        $targets = array_map(static fn ($l) => $l['target'], $links);

        self::assertContains('/joomla/media/lib_cwmscripturelinks', $targets);
    }

    #[Test]
    public function library_link_uses_explicit_manifest_override(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/weird';
        mkdir($pkgRoot . '/custom', 0o777, true);

        $pkg = $this->package(
            $pkgRoot,
            [['type' => 'library', 'name' => 'weirdlib', 'manifest' => 'custom/weirdlib.xml']],
        );

        $links            = (new LinkResolver($this->tmpDir, []))->externalLinksForPackages('/joomla', [$pkg]);
        $manifestEntry    = null;

        foreach ($links as $link) {
            if (str_ends_with($link['target'], '/manifests/libraries/weirdlib.xml')) {
                $manifestEntry = $link;
                break;
            }
        }

        self::assertNotNull($manifestEntry);
        self::assertSame($pkgRoot . '/custom/weirdlib.xml', $manifestEntry['source']);
    }

    #[Test]
    public function derives_plugin_link_with_convention_or_pkg_root(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/scripturelinks';
        mkdir($pkgRoot . '/plugins/content/cwmsl_autolink', 0o777, true);

        $pkg = $this->package(
            $pkgRoot,
            [['type' => 'plugin', 'group' => 'content', 'element' => 'cwmsl_autolink']],
        );

        $links = (new LinkResolver($this->tmpDir, []))->externalLinksForPackages('/joomla', [$pkg]);

        self::assertCount(1, $links);
        self::assertSame($pkgRoot . '/plugins/content/cwmsl_autolink', $links[0]['source']);
        self::assertSame('/joomla/plugins/content/cwmsl_autolink', $links[0]['target']);
    }

    #[Test]
    public function plugin_falls_back_to_pkg_root_when_convention_dir_missing(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/flatplugin';
        mkdir($pkgRoot, 0o777, true);

        $pkg = $this->package(
            $pkgRoot,
            [['type' => 'plugin', 'group' => 'system', 'element' => 'flatplugin']],
        );

        $links = (new LinkResolver($this->tmpDir, []))->externalLinksForPackages('/joomla', [$pkg]);

        self::assertSame($pkgRoot, $links[0]['source']);
        self::assertSame('/joomla/plugins/system/flatplugin', $links[0]['target']);
    }

    #[Test]
    public function module_link_respects_client_attribute(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/admin-module';
        mkdir($pkgRoot, 0o777, true);

        $pkg = $this->package(
            $pkgRoot,
            [['type' => 'module', 'name' => 'mod_cwm_admin', 'client' => 'administrator']],
        );

        $links = (new LinkResolver($this->tmpDir, []))->externalLinksForPackages('/joomla', [$pkg]);

        self::assertSame('/joomla/administrator/modules/mod_cwm_admin', $links[0]['target']);
    }

    #[Test]
    public function component_emits_admin_site_media_when_subdirs_present(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/comp';
        mkdir($pkgRoot . '/admin', 0o777, true);
        mkdir($pkgRoot . '/site', 0o777, true);
        mkdir($pkgRoot . '/media', 0o777, true);

        $pkg = $this->package(
            $pkgRoot,
            [['type' => 'component', 'name' => 'com_cwmcomp']],
        );

        $links   = (new LinkResolver($this->tmpDir, []))->externalLinksForPackages('/joomla', [$pkg]);
        $targets = array_map(static fn ($l) => $l['target'], $links);

        self::assertContains('/joomla/administrator/components/com_cwmcomp', $targets);
        self::assertContains('/joomla/components/com_cwmcomp', $targets);
        self::assertContains('/joomla/media/com_cwmcomp', $targets);
    }

    #[Test]
    public function component_skips_missing_subdirs_silently(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/site-only';
        mkdir($pkgRoot . '/site', 0o777, true);

        $pkg = $this->package(
            $pkgRoot,
            [['type' => 'component', 'name' => 'com_siteonly']],
        );

        $links = (new LinkResolver($this->tmpDir, []))->externalLinksForPackages('/joomla', [$pkg]);

        self::assertCount(1, $links);
        self::assertSame('/joomla/components/com_siteonly', $links[0]['target']);
    }

    #[Test]
    public function attaches_package_name_to_every_emitted_link(): void
    {
        $a = $this->tmpDir . '/vendor/cwm/a';
        $b = $this->tmpDir . '/vendor/cwm/b';
        mkdir($a, 0o777, true);
        mkdir($b, 0o777, true);

        $pkgA = $this->package($a, [['type' => 'library', 'name' => 'libA']]);
        $pkgA = new CwmPackage(
            name: 'cwm/a',
            version: $pkgA->version,
            versionNormalized: $pkgA->versionNormalized,
            joomlaLinks: $pkgA->joomlaLinks,
            installPath: $pkgA->installPath,
            isPathRepo: $pkgA->isPathRepo,
            sourcePath: $pkgA->sourcePath,
            reference: $pkgA->reference,
        );

        $pkgB = $this->package($b, [['type' => 'library', 'name' => 'libB']]);
        $pkgB = new CwmPackage(
            name: 'cwm/b',
            version: $pkgB->version,
            versionNormalized: $pkgB->versionNormalized,
            joomlaLinks: $pkgB->joomlaLinks,
            installPath: $pkgB->installPath,
            isPathRepo: $pkgB->isPathRepo,
            sourcePath: $pkgB->sourcePath,
            reference: $pkgB->reference,
        );

        $links     = (new LinkResolver($this->tmpDir, []))->externalLinksForPackages('/joomla', [$pkgA, $pkgB]);
        $byPackage = [];

        foreach ($links as $link) {
            $byPackage[$link['package']][] = $link['target'];
        }

        self::assertSame(
            ['cwm/a', 'cwm/b'],
            array_keys($byPackage),
        );
    }

    #[Test]
    public function honours_source_root_override_for_path_repo_installs(): void
    {
        $vendorPath = $this->tmpDir . '/vendor/cwm/path';
        $siblingSrc = $this->tmpDir . '/sibling/CWMSibling';
        mkdir($vendorPath, 0o777, true);
        mkdir($siblingSrc, 0o777, true);
        file_put_contents($siblingSrc . '/cwmsibling.xml', '<extension/>');

        $pkg = new CwmPackage(
            name: 'cwm/sibling',
            version: 'dev-main',
            versionNormalized: '9999999-dev',
            joomlaLinks: [['type' => 'library', 'name' => 'cwmsibling']],
            installPath: $vendorPath,
            isPathRepo: true,
            sourcePath: $siblingSrc,
            reference: 'b',
        );

        $links = (new LinkResolver($this->tmpDir, []))->externalLinksForPackages('/joomla', [$pkg]);

        // Source resolves against the path-repo sibling directory, not vendor.
        self::assertSame($siblingSrc, $links[0]['source']);
        self::assertSame($siblingSrc . '/cwmsibling.xml', $links[1]['source']);
    }

    /**
     * @param  list<array<string, string>> $joomlaLinks
     */
    private function package(string $root, array $joomlaLinks): CwmPackage
    {
        return new CwmPackage(
            name: 'cwm/fixture',
            version: '1.0.0',
            versionNormalized: '1.0.0.0',
            joomlaLinks: $joomlaLinks,
            installPath: $root,
            isPathRepo: false,
            sourcePath: null,
            reference: 'fixture',
        );
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path) && !is_link($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($path);
    }
}
