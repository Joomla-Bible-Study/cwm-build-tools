<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Build;

use CWM\BuildTools\Build\BuildConfig;
use CWM\BuildTools\Build\PackageConfig;
use CWM\BuildTools\Build\Packager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * End-to-end tests for the multi-extension package assembler.
 *
 * Each test stands up a minimal fixture project, runs the Packager, and
 * asserts the contents of the produced outer zip. Sub-build tests use a
 * tiny Stub PHP build script that produces a known zip so we don't need
 * a real Joomla extension on disk.
 */
final class PackagerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-pkg-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    // --- Layout: root vs packages-prefix ---

    #[Test]
    public function rootLayoutPlacesChildZipsAtOuterRoot(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');
        $this->prebuildChild('build/dist/sub-1.0.0.zip', 'sub.xml');

        $config = $this->makePackageConfig([
            'innerLayout' => 'root',
            'includes'    => [[
                'type'       => 'prebuilt',
                'distGlob'   => 'build/dist/sub-*.zip',
                'outputName' => 'sub.zip',
            ]],
        ]);

        $packager = new Packager($config, null, $this->tmpDir);
        $this->expectOutputRegex('/Assembling pkg-1\.0\.0\.zip/');
        $outer = $packager->package();

        $entries = $this->zipEntries($outer);
        $this->assertContains('pkg.xml', $entries);
        $this->assertContains('sub.zip', $entries);
        $this->assertNotContains('packages/sub.zip', $entries);
    }

    #[Test]
    public function packagesPrefixLayoutPlacesChildZipsUnderPackagesDir(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');
        $this->prebuildChild('build/dist/sub-1.0.0.zip', 'sub.xml');

        $config = $this->makePackageConfig([
            'innerLayout' => 'packages-prefix',
            'includes'    => [[
                'type'       => 'prebuilt',
                'distGlob'   => 'build/dist/sub-*.zip',
                'outputName' => 'sub.zip',
            ]],
        ]);

        $packager = new Packager($config, null, $this->tmpDir);
        $this->expectOutputRegex('/Assembling/');
        $outer = $packager->package();

        $entries = $this->zipEntries($outer);
        $this->assertContains('pkg.xml', $entries);
        $this->assertContains('packages/sub.zip', $entries);
        $this->assertNotContains('sub.zip', $entries);
    }

    // --- prebuilt include ---

    #[Test]
    public function prebuiltMissingZipFailsWithHelpfulMessage(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');

        $config = $this->makePackageConfig([
            'includes' => [[
                'type'       => 'prebuilt',
                'distGlob'   => 'build/dist/sub-*.zip',
                'outputName' => 'sub.zip',
            ]],
        ]);

        $packager = new Packager($config, null, $this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("no files matched distGlob 'build/dist/sub-*.zip'");
        $this->expectOutputRegex('/Assembling/');
        $packager->package();
    }

    #[Test]
    public function prebuiltPicksMostRecentlyModifiedWhenMultipleMatch(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');
        $this->prebuildChild('build/dist/sub-1.0.0.zip', 'sub.xml');
        sleep(1);  // ensure mtime ordering is observable
        $this->prebuildChild('build/dist/sub-1.1.0.zip', 'sub-newer.xml');

        $config = $this->makePackageConfig([
            'includes' => [[
                'type'       => 'prebuilt',
                'distGlob'   => 'build/dist/sub-*.zip',
                'outputName' => 'sub.zip',
            ]],
        ]);

        $packager = new Packager($config, null, $this->tmpDir);
        $this->expectOutputRegex('/sub-1\.1\.0\.zip/');
        $outer = $packager->package();

        // The staged child zip's content should be from the newer build (sub-newer.xml inside).
        $childEntries = $this->zipEntriesOfNested($outer, 'sub.zip');
        $this->assertContains('sub-newer.xml', $childEntries);
    }

    // --- subBuild include ---

    #[Test]
    public function subBuildShellsOutAndPicksUpProducedZip(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');

        // Create a sub-extension dir with a tiny PHP build script that writes a zip.
        mkdir($this->tmpDir . '/lib', 0o777, true);
        mkdir($this->tmpDir . '/lib/dist', 0o777, true);
        file_put_contents($this->tmpDir . '/lib/build.php', <<<'PHP'
<?php
$zip = new ZipArchive();
$out = __DIR__ . '/dist/lib-1.0.0.zip';
$zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('lib.xml', '<?xml version="1.0"?><extension/>');
$zip->close();
echo "  produced $out\n";
PHP);

        $config = $this->makePackageConfig([
            'includes' => [[
                'type'        => 'subBuild',
                'path'        => 'lib',
                'buildScript' => 'build.php',
                'distGlob'    => 'dist/lib-*.zip',
                'outputName'  => 'lib.zip',
            ]],
        ]);

        $packager = new Packager($config, null, $this->tmpDir);
        $this->expectOutputRegex('/sub-build lib\.zip/');
        $outer = $packager->package();

        $entries = $this->zipEntries($outer);
        $this->assertContains('lib.zip', $entries);

        // The staged child should contain lib.xml.
        $childEntries = $this->zipEntriesOfNested($outer, 'lib.zip');
        $this->assertContains('lib.xml', $childEntries);
    }

    #[Test]
    public function subBuildFailsWhenScriptExitsNonZero(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');

        mkdir($this->tmpDir . '/lib', 0o777, true);
        file_put_contents($this->tmpDir . '/lib/build.php', "<?php exit(2);\n");

        $config = $this->makePackageConfig([
            'includes' => [[
                'type'        => 'subBuild',
                'path'        => 'lib',
                'buildScript' => 'build.php',
                'distGlob'    => 'dist/*.zip',
                'outputName'  => 'lib.zip',
            ]],
        ]);

        $packager = new Packager($config, null, $this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exited with 2');
        $this->expectOutputRegex('/sub-build/');
        $packager->package();
    }

    #[Test]
    public function subBuildPassesArgsToScript(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');

        mkdir($this->tmpDir . '/lib', 0o777, true);
        mkdir($this->tmpDir . '/lib/dist', 0o777, true);
        // Script writes the args it received into a marker file that we then
        // also include in the zip so we can read it back at the test level.
        file_put_contents($this->tmpDir . '/lib/build.php', <<<'PHP'
<?php
$args = array_slice($argv, 1);
$marker = __DIR__ . '/_marker.txt';
file_put_contents($marker, implode(' ', $args));
$zip = new ZipArchive();
$out = __DIR__ . '/dist/lib-1.0.0.zip';
$zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFile($marker, '_marker.txt');
$zip->close();
PHP);

        $config = $this->makePackageConfig([
            'includes' => [[
                'type'        => 'subBuild',
                'path'        => 'lib',
                'buildScript' => 'build.php',
                'args'        => ['--plugin-only', 'extra'],
                'distGlob'    => 'dist/lib-*.zip',
                'outputName'  => 'lib.zip',
            ]],
        ]);

        $packager = new Packager($config, null, $this->tmpDir);
        $this->expectOutputRegex('/sub-build/');
        $packager->package();

        $marker = file_get_contents($this->tmpDir . '/lib/_marker.txt');
        $this->assertSame('--plugin-only extra', $marker);
    }

    // --- inline include ---

    #[Test]
    public function inlineIncludeBuildsNestedConfigAndBundles(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');
        $this->writeManifest('plg_task/cwmscripture.xml', '1.5.0');
        $this->writeFile('plg_task/script.php', '<?php // task install');
        $this->writeFile('plg_task/src/Task.php', '<?php // task');

        $config = $this->makePackageConfig([
            'includes' => [[
                'type'       => 'inline',
                'outputName' => 'plg_task.zip',
                'config'     => [
                    'outputDir'  => 'build/dist',
                    'outputName' => 'plg_task-{version}.zip',
                    'manifest'   => 'plg_task/cwmscripture.xml',
                    'scriptFile' => 'plg_task/script.php',
                    'sources'    => [
                        ['from' => 'plg_task/src', 'to' => 'src'],
                    ],
                ],
            ]],
        ]);

        $packager = new Packager($config, null, $this->tmpDir);
        $this->expectOutputRegex('/inline build plg_task\.zip/');
        $outer = $packager->package();

        $entries = $this->zipEntries($outer);
        $this->assertContains('plg_task.zip', $entries);

        $childEntries = $this->zipEntriesOfNested($outer, 'plg_task.zip');
        $this->assertContains('cwmscripture.xml', $childEntries);
        $this->assertContains('script.php', $childEntries);
        $this->assertContains('src/Task.php', $childEntries);
    }

    // --- self include ---

    #[Test]
    public function selfIncludeBuildsParentBuildConfig(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');
        $this->writeManifest('main.xml', '2.0.0');
        $this->writeFile('src/Foo.php', '<?php');

        $parentBuild = BuildConfig::fromArray([
            'outputDir'  => 'build/dist',
            'outputName' => 'main-{version}.zip',
            'manifest'   => 'main.xml',
            'sources'    => [['from' => 'src', 'to' => 'src']],
        ]);

        $config = $this->makePackageConfig([
            'includes' => [[
                'type'       => 'self',
                'outputName' => 'main.zip',
            ]],
        ]);

        $packager = new Packager($config, $parentBuild, $this->tmpDir);
        // Outer version is 1.0.0 (build/pkg.xml); the self include uses the
        // outer version, NOT main.xml's 2.0.0. Diagnostic line surfaces this
        // so the developer notices version-threading for the self include.
        $this->expectOutputRegex('/building self \(main\.zip\) at v1\.0\.0/');
        $outer = $packager->package();

        $entries = $this->zipEntries($outer);
        $this->assertContains('main.zip', $entries);

        $childEntries = $this->zipEntriesOfNested($outer, 'main.zip');
        $this->assertContains('main.xml', $childEntries);
        $this->assertContains('src/Foo.php', $childEntries);

        // The self include's intermediate zip (left in the parent's
        // build/dist) uses the OUTER version even though main.xml says 2.0.0
        // — that's the threading invariant.
        $this->assertFileExists($this->tmpDir . '/build/dist/main-1.0.0.zip');
        $this->assertFileDoesNotExist($this->tmpDir . '/build/dist/main-2.0.0.zip');
    }

    #[Test]
    public function selfIncludeThreadsCliVersionOverrideToInnerBuild(): void
    {
        // When `cwm-package --version 9.9.9` is given, the override applies
        // to the outer wrapper AND threads through to the self include's
        // inner build, so both name files at 9.9.9 — no manifest reads at all.
        $this->writeManifest('build/pkg.xml', '1.0.0');
        $this->writeManifest('main.xml', '2.0.0');
        $this->writeFile('src/Foo.php', '<?php');

        $parentBuild = BuildConfig::fromArray([
            'outputDir'  => 'build/dist',
            'outputName' => 'main-{version}.zip',
            'manifest'   => 'main.xml',
            'sources'    => [['from' => 'src', 'to' => 'src']],
        ]);

        $config = $this->makePackageConfig([
            'outputName' => 'pkg-{version}.zip',
            'includes'   => [[
                'type'       => 'self',
                'outputName' => 'main.zip',
            ]],
        ]);

        $packager = new Packager($config, $parentBuild, $this->tmpDir);
        $this->expectOutputRegex('/building self \(main\.zip\) at v9\.9\.9/');
        $outer = $packager->package('9.9.9');

        $this->assertSame($this->tmpDir . '/build/dist/pkg-9.9.9.zip', $outer);
        $this->assertFileExists($this->tmpDir . '/build/dist/main-9.9.9.zip');
    }

    #[Test]
    public function selfIncludeRequiresParentBuildConfig(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');

        $config = $this->makePackageConfig([
            'includes' => [[
                'type'       => 'self',
                'outputName' => 'main.zip',
            ]],
        ]);

        $packager = new Packager($config, null, $this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'self' entry, but the project's `build:` block isn't loaded");
        $this->expectOutputRegex('/Assembling/');
        $packager->package();
    }

    // --- installer + language files ---

    #[Test]
    public function installerAndLanguageFilesLandAtConfiguredPaths(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');
        $this->prebuildChild('build/dist/sub-1.0.0.zip', 'sub.xml');
        $this->writeFile('build/script.install.php', '<?php // installer');
        $this->writeFile('build/language/en-GB/en-GB.pkg.sys.ini', 'PKG_TITLE="Hi"');

        $config = $this->makePackageConfig([
            'installer'     => 'build/script.install.php',
            'languageFiles' => [[
                'from' => 'build/language/en-GB/en-GB.pkg.sys.ini',
                'to'   => 'language/en-GB/en-GB.pkg.sys.ini',
            ]],
            'includes' => [[
                'type'       => 'prebuilt',
                'distGlob'   => 'build/dist/sub-*.zip',
                'outputName' => 'sub.zip',
            ]],
        ]);

        $packager = new Packager($config, null, $this->tmpDir);
        $this->expectOutputRegex('/Assembling/');
        $outer = $packager->package();

        $entries = $this->zipEntries($outer);
        $this->assertContains('pkg.xml', $entries);
        $this->assertContains('script.install.php', $entries);
        $this->assertContains('language/en-GB/en-GB.pkg.sys.ini', $entries);
        $this->assertContains('sub.zip', $entries);
    }

    // --- verify step ---

    #[Test]
    public function verifyPassesWhenAllExpectedEntriesArePresent(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');
        $this->prebuildChild('build/dist/sub-1.0.0.zip', 'sub.xml');

        $config = $this->makePackageConfig([
            'includes' => [[
                'type'       => 'prebuilt',
                'distGlob'   => 'build/dist/sub-*.zip',
                'outputName' => 'sub.zip',
            ]],
            'verify' => [
                'expectedEntries' => ['pkg.xml', 'sub.zip'],
            ],
        ]);

        $packager = new Packager($config, null, $this->tmpDir);
        $this->expectOutputRegex('/2 expected entries present/');
        $outer = $packager->package();

        $this->assertFileExists($outer);
    }

    #[Test]
    public function verifyFailsWhenExpectedEntryIsMissing(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');
        $this->prebuildChild('build/dist/sub-1.0.0.zip', 'sub.xml');

        $config = $this->makePackageConfig([
            'includes' => [[
                'type'       => 'prebuilt',
                'distGlob'   => 'build/dist/sub-*.zip',
                'outputName' => 'sub.zip',
            ]],
            'verify' => [
                'expectedEntries' => ['pkg.xml', 'missing.zip'],
            ],
        ]);

        $packager = new Packager($config, null, $this->tmpDir);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing entries');
        $this->expectOutputRegex('/Assembling/');
        $packager->package();
    }

    // --- version override ---

    #[Test]
    public function versionOverrideTakesPrecedenceOverManifest(): void
    {
        $this->writeManifest('build/pkg.xml', '1.0.0');
        $this->prebuildChild('build/dist/sub-1.0.0.zip', 'sub.xml');

        $config = $this->makePackageConfig([
            'outputName' => 'pkg-{version}.zip',
            'includes'   => [[
                'type'       => 'prebuilt',
                'distGlob'   => 'build/dist/sub-*.zip',
                'outputName' => 'sub.zip',
            ]],
        ]);

        $packager = new Packager($config, null, $this->tmpDir);
        $this->expectOutputRegex('/pkg-9\.9\.9\.zip/');
        $outer = $packager->package('9.9.9');

        $this->assertSame($this->tmpDir . '/build/dist/pkg-9.9.9.zip', $outer);
    }

    // --- config validation ---

    #[Test]
    public function packageConfigRejectsMissingRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('package.manifest is required');

        PackageConfig::fromArray([
            'outputDir'  => 'build/dist',
            'outputName' => 'pkg-{version}.zip',
            'includes'   => [['type' => 'self', 'outputName' => 'main.zip']],
        ]);
    }

    #[Test]
    public function packageConfigRejectsEmptyIncludes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('package.includes is required');

        PackageConfig::fromArray([
            'manifest'   => 'pkg.xml',
            'outputDir'  => 'build/dist',
            'outputName' => 'pkg-{version}.zip',
            'includes'   => [],
        ]);
    }

    #[Test]
    public function packageConfigRejectsInvalidIncludeType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('package.includes[0].type');

        PackageConfig::fromArray([
            'manifest'   => 'pkg.xml',
            'outputDir'  => 'build/dist',
            'outputName' => 'pkg-{version}.zip',
            'includes'   => [['type' => 'bogus', 'outputName' => 'x.zip']],
        ]);
    }

    #[Test]
    public function packageConfigRejectsInvalidLayout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('package.innerLayout');

        PackageConfig::fromArray([
            'manifest'    => 'pkg.xml',
            'outputDir'   => 'build/dist',
            'outputName'  => 'pkg-{version}.zip',
            'innerLayout' => 'flat',
            'includes'    => [['type' => 'self', 'outputName' => 'm.zip']],
        ]);
    }

    #[Test]
    public function packageConfigRejectsSubBuildMissingPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("package.includes[0] (type subBuild) requires non-empty 'path'");

        PackageConfig::fromArray([
            'manifest'   => 'pkg.xml',
            'outputDir'  => 'build/dist',
            'outputName' => 'pkg-{version}.zip',
            'includes'   => [[
                'type'        => 'subBuild',
                'outputName'  => 'lib.zip',
                'buildScript' => 'build.php',
                'distGlob'    => 'dist/*.zip',
            ]],
        ]);
    }

    #[Test]
    public function packageConfigRejectsInlineMissingNestedConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("package.includes[0] (type inline) requires a 'config' object");

        PackageConfig::fromArray([
            'manifest'   => 'pkg.xml',
            'outputDir'  => 'build/dist',
            'outputName' => 'pkg-{version}.zip',
            'includes'   => [['type' => 'inline', 'outputName' => 'x.zip']],
        ]);
    }

    // --- Helpers ---

    /**
     * @param array<string, mixed> $overrides
     */
    private function makePackageConfig(array $overrides = []): PackageConfig
    {
        $base = [
            'manifest'    => 'build/pkg.xml',
            'outputDir'   => 'build/dist',
            'outputName'  => 'pkg-{version}.zip',
            'innerLayout' => 'root',
        ];

        return PackageConfig::fromArray(array_merge($base, $overrides));
    }

    private function writeManifest(string $relPath, string $version): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<extension type="library" method="upgrade">
    <name>Test</name>
    <version>$version</version>
    <creationDate>2026-05-09</creationDate>
</extension>
XML;
        $this->writeFile($relPath, $xml);
    }

    private function writeFile(string $relPath, string $content): void
    {
        $abs = $this->tmpDir . '/' . $relPath;
        $dir = dirname($abs);

        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($abs, $content);
    }

    /**
     * Pre-create a child zip with a single XML entry to simulate an
     * already-built sub-extension on disk.
     */
    private function prebuildChild(string $relPath, string $manifestEntryName): void
    {
        $abs = $this->tmpDir . '/' . $relPath;
        $dir = dirname($abs);

        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($abs, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString($manifestEntryName, '<?xml version="1.0"?><extension/>');
        $zip->close();
    }

    /**
     * @return list<string>
     */
    private function zipEntries(string $zipPath): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true, "Could not open $zipPath");

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        return $entries;
    }

    /**
     * Extract a nested zip from $outerPath, then return the entries of that
     * inner zip — used to verify what landed inside child zips.
     *
     * @return list<string>
     */
    private function zipEntriesOfNested(string $outerPath, string $innerEntry): array
    {
        $outer = new ZipArchive();
        $this->assertTrue($outer->open($outerPath) === true);

        $tmp = tempnam(sys_get_temp_dir(), 'cwm-nested-');

        // Use file_put_contents on the stream; ZipArchive::extractTo would
        // require a destination dir.
        $bytes = $outer->getFromName($innerEntry);
        $outer->close();

        if ($bytes === false) {
            $this->fail("Inner entry '$innerEntry' missing from outer zip $outerPath");
        }

        file_put_contents($tmp, $bytes);

        $inner   = new ZipArchive();
        $this->assertTrue($inner->open($tmp) === true);
        $entries = [];

        for ($i = 0; $i < $inner->numFiles; $i++) {
            $entries[] = (string) $inner->getNameIndex($i);
        }

        $inner->close();
        @unlink($tmp);

        return $entries;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir) ?: [];

        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }

            $path = $dir . '/' . $e;

            if (is_link($path) || is_file($path)) {
                @unlink($path);
                continue;
            }

            $this->rrmdir($path);
        }

        @rmdir($dir);
    }
}
