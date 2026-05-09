<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Build;

use CWM\BuildTools\Build\BuildConfig;
use CWM\BuildTools\Build\PackageBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * End-to-end tests that build a real zip from a temp fixture project and
 * assert its contents — the only meaningful coverage for a script whose
 * job is producing a correct on-disk artifact.
 */
final class PackageBuilderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-build-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    #[Test]
    public function buildsLibCwmscriptureShape(): void
    {
        $this->writeManifest('cwmscripture.xml', '1.2.0');
        $this->writeFile('script.php', '<?php // install hook');
        $this->writeFile('src/Service/Foo.php', '<?php // foo');
        $this->writeFile('src/Service/Bar.php', '<?php // bar');
        $this->writeFile('sql/install.mysql.utf8.sql', '-- DDL');
        $this->writeFile('language/en-GB/en-GB.lib_cwmscripture.ini', '');
        $this->writeFile('media/lib_cwmscripture/js/foo.js', 'console.log("foo");');
        $this->writeFile('media/lib_cwmscripture/js/foo.min.js', 'console.log("foo")');
        $this->writeFile('media/lib_cwmscripture/css/foo.css', '.foo{}');
        $this->writeFile('media/lib_cwmscripture/css/foo.min.css', '.foo{}');
        // Excluded entries — should NOT end up in zip.
        $this->writeFile('src/.DS_Store', '');
        $this->writeFile('src/node_modules/x/index.js', '');

        $builder = new PackageBuilder($this->makeLibConfig(), $this->tmpDir);

        $this->expectOutputRegex('/Building lib_cwmscripture-1\.2\.0\.zip \(v1\.2\.0\)/');

        $zipPath = $builder->build();

        $this->assertSame($this->tmpDir . '/build/dist/lib_cwmscripture-1.2.0.zip', $zipPath);
        $this->assertFileExists($zipPath);

        $entries = $this->zipEntries($zipPath);

        $this->assertContains('cwmscripture.xml', $entries);
        $this->assertContains('script.php', $entries);
        $this->assertContains('lib_cwmscripture/src/Service/Foo.php', $entries);
        $this->assertContains('lib_cwmscripture/src/Service/Bar.php', $entries);
        $this->assertContains('lib_cwmscripture/sql/install.mysql.utf8.sql', $entries);
        $this->assertContains('lib_cwmscripture/language/en-GB/en-GB.lib_cwmscripture.ini', $entries);
        $this->assertContains('media/lib_cwmscripture/js/foo.js', $entries);
        $this->assertContains('media/lib_cwmscripture/js/foo.min.js', $entries);

        $this->assertNotContains('src/.DS_Store', $entries);
        $this->assertNotContains('lib_cwmscripture/src/.DS_Store', $entries);
        // node_modules anywhere in the path is excluded by the substring match.
        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('node_modules', $entry);
            $this->assertStringNotContainsString('.DS_Store', $entry);
        }
    }

    #[Test]
    public function omitsScriptFileWhenAbsent(): void
    {
        $this->writeManifest('cwmscripture.xml', '1.0.0');
        $this->writeFile('src/Foo.php', '<?php');

        $builder = new PackageBuilder($this->makeLibConfig(), $this->tmpDir);

        $this->expectOutputRegex('/Building lib_cwmscripture-1\.0\.0\.zip/');

        $zipPath = $builder->build();
        $entries = $this->zipEntries($zipPath);

        $this->assertNotContains('script.php', $entries);
        $this->assertContains('cwmscripture.xml', $entries);
    }

    #[Test]
    public function versionOverrideTakesPrecedenceOverManifest(): void
    {
        $this->writeManifest('cwmscripture.xml', '1.0.0');
        $this->writeFile('src/Foo.php', '<?php');

        $builder = new PackageBuilder($this->makeLibConfig(), $this->tmpDir);

        $this->expectOutputRegex('/Building lib_cwmscripture-9\.9\.9\.zip \(v9\.9\.9\)/');

        $zipPath = $builder->build('9.9.9');

        $this->assertSame($this->tmpDir . '/build/dist/lib_cwmscripture-9.9.9.zip', $zipPath);
    }

    #[Test]
    public function ensureMinifiedGateFailsWhenSiblingMissing(): void
    {
        $this->writeManifest('cwmscripture.xml', '1.0.0');
        $this->writeFile('src/Foo.php', '<?php');
        $this->writeFile('media/lib_cwmscripture/js/foo.js', 'console.log');
        // foo.min.js intentionally missing.

        $builder = new PackageBuilder($this->makeLibConfig(), $this->tmpDir);

        $exitCode = -1;
        $stderr   = '';

        // Capture exit() via a child process — preg flag the missing-min message.
        $script = <<<'PHP'
<?php
require '%s/src/Build/BuildConfig.php';
require '%s/src/Build/ManifestReader.php';
require '%s/src/Build/PackageBuilder.php';
$cfg = CWM\BuildTools\Build\BuildConfig::fromArray(%s);
$b   = new CWM\BuildTools\Build\PackageBuilder($cfg, '%s');
$b->build();
PHP;

        $cwm   = realpath(__DIR__ . '/../..');
        $cfg   = var_export($this->libConfigArray(), true);
        $proj  = $this->tmpDir;
        $code  = sprintf($script, $cwm, $cwm, $cwm, $cfg, $proj);
        $tmpPhp = $this->tmpDir . '/_run.php';
        file_put_contents($tmpPhp, $code);

        $proc = proc_open(
            ['php', $tmpPhp],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($proc)) {
            $this->fail('Could not spawn child PHP for gate test');
        }

        fclose($pipes[0] ?? STDIN);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $this->assertSame(1, $exitCode, 'Gate failure should exit with 1');
        $this->assertStringContainsString('missing minified assets', (string) $stderr);
        $this->assertStringContainsString('foo.min.js', (string) $stderr);
    }

    #[Test]
    public function ensureMinifiedGatePassesWhenAllPairsPresent(): void
    {
        $this->writeManifest('cwmscripture.xml', '1.0.0');
        $this->writeFile('src/Foo.php', '<?php');
        $this->writeFile('media/lib_cwmscripture/js/foo.js', 'console.log');
        $this->writeFile('media/lib_cwmscripture/js/foo.min.js', 'console.log');
        $this->writeFile('media/lib_cwmscripture/css/foo.css', '.foo{}');
        $this->writeFile('media/lib_cwmscripture/css/foo.min.css', '.foo{}');

        $builder = new PackageBuilder($this->makeLibConfig(), $this->tmpDir);

        $this->expectOutputRegex('/Package built/');
        $zipPath = $builder->build();
        $this->assertFileExists($zipPath);
    }

    #[Test]
    public function buildConfigRejectsMissingRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('build.outputDir is required');

        BuildConfig::fromArray([
            'outputName' => 'foo-{version}.zip',
            'manifest'   => 'foo.xml',
            'sources'    => [['from' => 'src', 'to' => 'src']],
        ]);
    }

    #[Test]
    public function buildConfigRejectsEmptySources(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('build.sources is required');

        BuildConfig::fromArray([
            'outputDir'  => 'build/dist',
            'outputName' => 'foo-{version}.zip',
            'manifest'   => 'foo.xml',
            'sources'    => [],
        ]);
    }

    #[Test]
    public function buildConfigRejectsUnknownPreBuildMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('build.preBuild.mode');

        BuildConfig::fromArray([
            'outputDir'  => 'build/dist',
            'outputName' => 'foo-{version}.zip',
            'manifest'   => 'foo.xml',
            'sources'    => [['from' => 'src', 'to' => 'src']],
            'preBuild'   => ['mode' => 'autorun'],
        ]);
    }

    // --- Helpers ---

    /**
     * @return array<string, mixed>
     */
    private function libConfigArray(): array
    {
        return [
            'outputDir'  => 'build/dist',
            'outputName' => 'lib_cwmscripture-{version}.zip',
            'manifest'   => 'cwmscripture.xml',
            'scriptFile' => 'script.php',
            'sources'    => [
                ['from' => 'src', 'to' => 'lib_cwmscripture/src'],
                ['from' => 'sql', 'to' => 'lib_cwmscripture/sql'],
                ['from' => 'language', 'to' => 'lib_cwmscripture/language'],
                ['from' => 'media/lib_cwmscripture', 'to' => 'media/lib_cwmscripture'],
            ],
            'excludes'   => ['.git', '.DS_Store', '.idea', 'node_modules', '.php-cs-fixer.cache'],
            'preBuild'   => [
                'mode' => 'ensure-minified',
                'dirs' => ['media/lib_cwmscripture/js', 'media/lib_cwmscripture/css'],
            ],
        ];
    }

    private function makeLibConfig(): BuildConfig
    {
        return BuildConfig::fromArray($this->libConfigArray());
    }

    private function writeManifest(string $relPath, string $version): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<extension type="library" method="upgrade">
    <name>CWM Scripture</name>
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
