<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Config\CwmPackage;
use CWM\BuildTools\Dev\ExtensionVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the joomlaLinks → expected-extension row translation.
 * The full DB-connected verify() path is covered by ExtensionVerifierTest;
 * here we only assert the package-aware fold-in produces the right rows
 * for downstream #__extensions lookup.
 */
final class ExtensionVerifierPackagesTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/cwm-ext-verifier-pkgs-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectRoot)) {
            $files = glob($this->projectRoot . '/*') ?: [];

            foreach ($files as $f) {
                @unlink($f);
            }

            @rmdir($this->projectRoot);
        }
    }

    #[Test]
    public function expected_library_row_matches_joomla_extensions_storage(): void
    {
        $pkg     = $this->package([['type' => 'library', 'name' => 'cwmscripturelinks']]);
        $rows    = (new ExtensionVerifier($this->projectRoot, []))->expectedFromPackages([$pkg]);

        self::assertCount(1, $rows);
        self::assertSame('library', $rows[0]['type']);
        self::assertSame('cwmscripturelinks', $rows[0]['element']);
        self::assertSame('', $rows[0]['folder']);
        self::assertSame('lib_cwmscripturelinks', $rows[0]['name']);
        self::assertSame(1, $rows[0]['enabled']);
        self::assertSame(1, $rows[0]['locked'], 'libraries are locked by Joomla convention');
        self::assertSame('cwm/scripturelinks', $rows[0]['_package']);
        self::assertSame('2.1.4', $rows[0]['_version']);
    }

    #[Test]
    public function expected_plugin_row_uses_group_as_folder(): void
    {
        $pkg  = $this->package([
            ['type' => 'plugin', 'group' => 'content', 'element' => 'cwmsl_autolink'],
        ]);
        $rows = (new ExtensionVerifier($this->projectRoot, []))->expectedFromPackages([$pkg]);

        self::assertSame('plugin', $rows[0]['type']);
        self::assertSame('cwmsl_autolink', $rows[0]['element']);
        self::assertSame('content', $rows[0]['folder']);
        self::assertSame('plg_content_cwmsl_autolink', $rows[0]['name']);
        self::assertSame(0, $rows[0]['locked']);
    }

    #[Test]
    public function expected_module_row_includes_client_id_for_site(): void
    {
        $pkg  = $this->package([
            ['type' => 'module', 'name' => 'mod_cwm_widget', 'client' => 'site'],
        ]);
        $rows = (new ExtensionVerifier($this->projectRoot, []))->expectedFromPackages([$pkg]);

        self::assertSame('module', $rows[0]['type']);
        self::assertSame('mod_cwm_widget', $rows[0]['element']);
        self::assertArrayHasKey('client_id', $rows[0]);
        self::assertSame(0, $rows[0]['client_id'], 'site client_id is 0');
    }

    #[Test]
    public function expected_module_row_includes_client_id_for_administrator(): void
    {
        $pkg  = $this->package([
            ['type' => 'module', 'name' => 'mod_cwm_admin', 'client' => 'administrator'],
        ]);
        $rows = (new ExtensionVerifier($this->projectRoot, []))->expectedFromPackages([$pkg]);

        self::assertSame(1, $rows[0]['client_id'], 'administrator client_id is 1');
    }

    #[Test]
    public function expected_module_row_defaults_to_site_client_when_omitted(): void
    {
        $pkg  = $this->package([
            ['type' => 'module', 'name' => 'mod_default'],
        ]);
        $rows = (new ExtensionVerifier($this->projectRoot, []))->expectedFromPackages([$pkg]);

        self::assertSame(0, $rows[0]['client_id']);
    }

    #[Test]
    public function expected_component_row_uses_name_as_element(): void
    {
        $pkg  = $this->package([['type' => 'component', 'name' => 'com_cwmthing']]);
        $rows = (new ExtensionVerifier($this->projectRoot, []))->expectedFromPackages([$pkg]);

        self::assertSame('component', $rows[0]['type']);
        self::assertSame('com_cwmthing', $rows[0]['element']);
        self::assertSame('com_cwmthing', $rows[0]['name']);
    }

    #[Test]
    public function rows_from_multiple_packages_carry_distinct_package_attribution(): void
    {
        $a = new CwmPackage(
            name: 'cwm/a',
            version: '1.0.0',
            versionNormalized: '1.0.0.0',
            joomlaLinks: [['type' => 'library', 'name' => 'libA']],
            installPath: '/tmp/a',
            isPathRepo: false,
            sourcePath: null,
            reference: 'x',
        );

        $b = new CwmPackage(
            name: 'cwm/b',
            version: '2.0.0',
            versionNormalized: '2.0.0.0',
            joomlaLinks: [['type' => 'component', 'name' => 'com_b']],
            installPath: '/tmp/b',
            isPathRepo: false,
            sourcePath: null,
            reference: 'y',
        );

        $rows = (new ExtensionVerifier($this->projectRoot, []))->expectedFromPackages([$a, $b]);

        self::assertCount(2, $rows);
        self::assertSame('cwm/a', $rows[0]['_package']);
        self::assertSame('cwm/b', $rows[1]['_package']);
    }

    #[Test]
    public function empty_package_list_returns_empty_row_list(): void
    {
        $rows = (new ExtensionVerifier($this->projectRoot, []))->expectedFromPackages([]);

        self::assertSame([], $rows);
    }

    /**
     * @param  list<array<string, string>> $joomlaLinks
     */
    private function package(array $joomlaLinks): CwmPackage
    {
        return new CwmPackage(
            name: 'cwm/scripturelinks',
            version: '2.1.4',
            versionNormalized: '2.1.4.0',
            joomlaLinks: $joomlaLinks,
            installPath: '/tmp/pkg',
            isPathRepo: false,
            sourcePath: null,
            reference: 'aaaa',
        );
    }
}
