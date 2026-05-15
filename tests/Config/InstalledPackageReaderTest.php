<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Config;

use CWM\BuildTools\Config\InstalledPackageReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InstalledPackageReaderTest extends TestCase
{
    private string $fixturesRoot;

    protected function setUp(): void
    {
        $this->fixturesRoot = \dirname(__DIR__) . '/fixtures/installed';
    }

    #[Test]
    public function returns_empty_when_vendor_dir_is_missing(): void
    {
        $reader = new InstalledPackageReader($this->fixturesRoot . '/no-vendor');

        self::assertSame([], $reader->cwmPackages());
    }

    #[Test]
    public function returns_empty_when_installed_json_has_no_packages(): void
    {
        $reader = new InstalledPackageReader($this->fixturesRoot . '/empty');

        self::assertSame([], $reader->cwmPackages());
    }

    #[Test]
    public function skips_packages_without_extra_cwm_build_tools_block(): void
    {
        $reader   = new InstalledPackageReader($this->fixturesRoot . '/registry');
        $packages = $reader->cwmPackages();

        self::assertCount(1, $packages);
        self::assertSame('cwm/scripturelinks', $packages[0]->name);
    }

    #[Test]
    public function reads_version_and_normalised_version_from_registry_install(): void
    {
        $reader  = new InstalledPackageReader($this->fixturesRoot . '/registry');
        $package = $reader->cwmPackages()[0];

        self::assertSame('2.1.4', $package->version);
        self::assertSame('2.1.4.0', $package->versionNormalized);
        self::assertFalse($package->isPathRepo);
        self::assertNull($package->sourcePath);
        self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $package->reference);
    }

    #[Test]
    public function validates_and_normalises_joomla_links_tuples(): void
    {
        $reader  = new InstalledPackageReader($this->fixturesRoot . '/registry');
        $package = $reader->cwmPackages()[0];

        self::assertSame(
            [
                ['type' => 'library', 'name' => 'cwmscripturelinks'],
                ['type' => 'plugin', 'group' => 'content', 'element' => 'cwmsl_autolink'],
            ],
            $package->joomlaLinks,
        );
    }

    #[Test]
    public function detects_path_repo_install_and_resolves_source_path(): void
    {
        $root    = $this->fixturesRoot . '/path-repo';
        $reader  = new InstalledPackageReader($root);
        $package = $reader->cwmPackages()[0];

        self::assertTrue($package->isPathRepo);
        self::assertNotNull($package->sourcePath);
        self::assertStringEndsWith('sibling/CWMScriptureLinks', $package->sourcePath);
        // Use realpath as oracle so the test is portable across filesystems.
        $expected = realpath($root . '/sibling/CWMScriptureLinks');
        self::assertSame($expected, $package->sourcePath);
    }

    #[Test]
    public function source_root_falls_back_to_install_path_for_registry_installs(): void
    {
        $reader  = new InstalledPackageReader($this->fixturesRoot . '/registry');
        $package = $reader->cwmPackages()[0];

        self::assertSame($package->installPath, $package->sourceRoot());
    }

    #[Test]
    public function source_root_returns_source_path_for_path_repo_installs(): void
    {
        $reader  = new InstalledPackageReader($this->fixturesRoot . '/path-repo');
        $package = $reader->cwmPackages()[0];

        self::assertSame($package->sourcePath, $package->sourceRoot());
    }

    #[Test]
    public function harvests_multiple_cwm_packages_and_ignores_non_cwm_deps(): void
    {
        $reader   = new InstalledPackageReader($this->fixturesRoot . '/mixed');
        $packages = $reader->cwmPackages();

        $names = array_map(static fn ($p) => $p->name, $packages);

        self::assertContains('cwm/scripturelinks', $names);
        self::assertContains('cwm/livingword', $names);
        self::assertNotContains('cwm/build-tools', $names, 'build-tools has no joomlaLinks block, must be skipped');
        self::assertNotContains('phpunit/phpunit', $names);
    }

    #[Test]
    public function throws_with_package_name_when_joomla_link_missing_required_key(): void
    {
        $reader = new InstalledPackageReader($this->fixturesRoot . '/malformed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Package 'cwm/broken': joomlaLinks[0] (type library) requires 'name'");

        $reader->cwmPackages();
    }

    #[Test]
    public function throws_when_module_client_is_not_site_or_administrator(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/cwm-installed-reader-' . bin2hex(random_bytes(6));
        mkdir($tmpRoot . '/vendor/composer', 0o777, true);

        try {
            file_put_contents(
                $tmpRoot . '/vendor/composer/installed.json',
                json_encode([
                    'packages' => [[
                        'name'               => 'cwm/oddclient',
                        'version'            => '1.0.0',
                        'version_normalized' => '1.0.0.0',
                        'dist'               => ['type' => 'zip', 'url' => 'x', 'reference' => 'z', 'shasum' => ''],
                        'type'               => 'library',
                        'install-path'       => '../cwm/oddclient',
                        'extra'              => [
                            'cwm-build-tools' => [
                                'joomlaLinks' => [
                                    ['type' => 'module', 'name' => 'mod_x', 'client' => 'frontend'],
                                ],
                            ],
                        ],
                    ]],
                ]),
            );

            $reader = new InstalledPackageReader($tmpRoot);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("Package 'cwm/oddclient': joomlaLinks[0].client must be 'site' or 'administrator'");

            $reader->cwmPackages();
        } finally {
            @unlink($tmpRoot . '/vendor/composer/installed.json');
            @rmdir($tmpRoot . '/vendor/composer');
            @rmdir($tmpRoot . '/vendor');
            @rmdir($tmpRoot);
        }
    }

    #[Test]
    public function throws_when_joomla_links_is_an_object_instead_of_array(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/cwm-installed-reader-' . bin2hex(random_bytes(6));
        mkdir($tmpRoot . '/vendor/composer', 0o777, true);

        try {
            // Force a JSON object (PHP assoc array with string keys round-trips as object).
            file_put_contents(
                $tmpRoot . '/vendor/composer/installed.json',
                <<<JSON
                {
                  "packages": [
                    {
                      "name": "cwm/wrongshape",
                      "version": "1.0.0",
                      "version_normalized": "1.0.0.0",
                      "dist": {"type":"zip","url":"x","reference":"z","shasum":""},
                      "type": "library",
                      "install-path": "../cwm/wrongshape",
                      "extra": {
                        "cwm-build-tools": {
                          "joomlaLinks": { "type": "library", "name": "x" }
                        }
                      }
                    }
                  ]
                }
                JSON,
            );

            $reader = new InstalledPackageReader($tmpRoot);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("Package 'cwm/wrongshape': extra.cwm-build-tools.joomlaLinks must be a JSON array");

            $reader->cwmPackages();
        } finally {
            @unlink($tmpRoot . '/vendor/composer/installed.json');
            @rmdir($tmpRoot . '/vendor/composer');
            @rmdir($tmpRoot . '/vendor');
            @rmdir($tmpRoot);
        }
    }

    #[Test]
    public function honors_composer_config_vendor_dir_override(): void
    {
        // Regression: CWMLivingWord uses "vendor-dir": "libraries/vendor", and
        // earlier the reader hardcoded `<project>/vendor/composer/installed.json`,
        // so the cross-package machinery silently saw zero deps and degraded
        // to no-op. Confirm the configured vendor-dir is honored.
        $tmpRoot = sys_get_temp_dir() . '/cwm-installed-reader-' . bin2hex(random_bytes(6));
        mkdir($tmpRoot . '/libraries/vendor/composer', 0o777, true);

        try {
            file_put_contents(
                $tmpRoot . '/composer.json',
                json_encode([
                    'name'   => 'consumer/test',
                    'config' => ['vendor-dir' => 'libraries/vendor'],
                ]),
            );

            file_put_contents(
                $tmpRoot . '/libraries/vendor/composer/installed.json',
                json_encode([
                    'packages' => [[
                        'name'               => 'cwm/sibling',
                        'version'            => '1.0.0',
                        'version_normalized' => '1.0.0.0',
                        'dist'               => ['type' => 'zip', 'url' => 'x', 'reference' => 'r'],
                        'type'               => 'library',
                        'install-path'       => '../cwm/sibling',
                        'extra'              => [
                            'cwm-build-tools' => [
                                'joomlaLinks' => [['type' => 'library', 'name' => 'sibling']],
                            ],
                        ],
                    ]],
                ]),
            );

            $reader   = new InstalledPackageReader($tmpRoot);
            $packages = $reader->cwmPackages();

            self::assertCount(1, $packages, 'should find sibling via configured vendor-dir');
            self::assertSame('cwm/sibling', $packages[0]->name);
        } finally {
            @unlink($tmpRoot . '/composer.json');
            @unlink($tmpRoot . '/libraries/vendor/composer/installed.json');
            @rmdir($tmpRoot . '/libraries/vendor/composer');
            @rmdir($tmpRoot . '/libraries/vendor');
            @rmdir($tmpRoot . '/libraries');
            @rmdir($tmpRoot);
        }
    }

    #[Test]
    public function tolerates_composer1_style_flat_top_level_array(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/cwm-installed-reader-' . bin2hex(random_bytes(6));
        mkdir($tmpRoot . '/vendor/composer', 0o777, true);

        try {
            // Composer 1 wrote installed.json as a top-level array (no "packages" wrapper).
            file_put_contents(
                $tmpRoot . '/vendor/composer/installed.json',
                json_encode([[
                    'name'               => 'cwm/legacy',
                    'version'            => '1.0.0',
                    'version_normalized' => '1.0.0.0',
                    'type'               => 'library',
                    'extra'              => [
                        'cwm-build-tools' => [
                            'joomlaLinks' => [['type' => 'library', 'name' => 'legacy']],
                        ],
                    ],
                ]]),
            );

            $reader   = new InstalledPackageReader($tmpRoot);
            $packages = $reader->cwmPackages();

            self::assertCount(1, $packages);
            self::assertSame('cwm/legacy', $packages[0]->name);
        } finally {
            @unlink($tmpRoot . '/vendor/composer/installed.json');
            @rmdir($tmpRoot . '/vendor/composer');
            @rmdir($tmpRoot . '/vendor');
            @rmdir($tmpRoot);
        }
    }
}
