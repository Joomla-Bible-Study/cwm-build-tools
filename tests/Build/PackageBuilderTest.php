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

    // --- PR C: strict-mode excludes, vendor prune, include filter, run pre-build ---

    #[Test]
    public function strictModeMatchesAllFourPositionsForOnePattern(): void
    {
        // Files Proclaim's exact 4-mode logic must catch with a single ".git" entry.
        $this->writeManifest('proclaim.xml', '10.3.2');
        $this->writeFile('admin/index.html', '');                    // included
        $this->writeFile('.git/HEAD', '');                            // exact (root prefix '.git/')
        $this->writeFile('admin/.git/HEAD', '');                      // contained '/.git/'
        $this->writeFile('admin/sub/.git', '');                       // suffix '/.git'
        $this->writeFile('libraries/.git/x.txt', '');                 // contained
        // A path that contains "git" but not as a directory boundary should NOT be excluded.
        $this->writeFile('admin/widget.html', '');                    // included (contains 'git' but not in 4-mode)

        $builder = new PackageBuilder($this->makeProclaimConfig(['.git']), $this->tmpDir);
        $this->expectOutputRegex('/Building com_proclaim-10\.3\.2\.zip/');
        $zip = $builder->build();

        $entries = $this->zipEntries($zip);
        $this->assertContains('admin/index.html', $entries);
        $this->assertContains('admin/widget.html', $entries);
        $this->assertNotContains('.git/HEAD', $entries);
        $this->assertNotContains('admin/.git/HEAD', $entries);
        $this->assertNotContains('admin/sub/.git', $entries);
        $this->assertNotContains('libraries/.git/x.txt', $entries);
    }

    #[Test]
    public function strictModeDoesNotOvermatchSubstrings(): void
    {
        // Pattern 'git' under contains-mode would match 'admin/widget.html' (contains "git").
        // Strict mode should NOT — strict requires '/git/' or '/git' or 'git/' or exact 'git'.
        $this->writeManifest('proclaim.xml', '1.0.0');
        $this->writeFile('admin/widget.html', '');
        $this->writeFile('admin/git/HEAD', '');

        $builder = new PackageBuilder($this->makeProclaimConfig(['git']), $this->tmpDir);
        $this->expectOutputRegex('/Package built/');
        $zip = $builder->build();

        $entries = $this->zipEntries($zip);
        $this->assertContains('admin/widget.html', $entries);
        $this->assertNotContains('admin/git/HEAD', $entries);
    }

    #[Test]
    public function excludeExtensionsDropsMapFiles(): void
    {
        $this->writeManifest('proclaim.xml', '1.0.0');
        $this->writeFile('admin/script.js', '');
        $this->writeFile('admin/script.js.map', '');
        $this->writeFile('media/foo.css', '');
        $this->writeFile('media/foo.css.map', '');

        $builder = new PackageBuilder(
            $this->makeProclaimConfig([], ['excludeExtensions' => ['map']]),
            $this->tmpDir
        );
        $this->expectOutputRegex('/Package built/');
        $zip = $builder->build();

        $entries = $this->zipEntries($zip);
        $this->assertContains('admin/script.js', $entries);
        $this->assertContains('media/foo.css', $entries);
        $this->assertNotContains('admin/script.js.map', $entries);
        $this->assertNotContains('media/foo.css.map', $entries);
    }

    #[Test]
    public function excludePathsGlobMatchesBackupSqlFiles(): void
    {
        // The exact rule Proclaim's existing script bakes in (lines 277-279).
        $this->writeManifest('proclaim.xml', '1.0.0');
        $this->writeFile('media/backup/db.sql', '-- DDL');
        $this->writeFile('media/backup/sub/old.sql', '-- DDL');
        $this->writeFile('media/keep.sql', '-- KEEP');

        $builder = new PackageBuilder(
            $this->makeProclaimConfig([], ['excludePaths' => ['media/backup/*.sql']]),
            $this->tmpDir
        );
        $this->expectOutputRegex('/Package built/');
        $zip = $builder->build();

        $entries = $this->zipEntries($zip);
        $this->assertContains('media/keep.sql', $entries);
        $this->assertNotContains('media/backup/db.sql', $entries);
        $this->assertNotContains('media/backup/sub/old.sql', $entries);
    }

    #[Test]
    public function vendorPruneDropsComposerMetadataAndDocs(): void
    {
        $this->writeManifest('proclaim.xml', '1.0.0');
        $this->writeFile('libraries/vendor/composer/installed.json', '{}');
        $this->writeFile('libraries/vendor/composer/installed.php', '<?php');
        $this->writeFile('libraries/vendor/symfony/dependency/README.md', '# readme');
        $this->writeFile('libraries/vendor/symfony/dependency/CHANGELOG.md', '# changelog');
        $this->writeFile('libraries/vendor/symfony/dependency/LICENSE', 'mit');
        $this->writeFile('libraries/vendor/symfony/dependency/src/X.php', '<?php // keep');
        // README outside vendor — must be kept (vendorPrune only acts on vendor subtrees).
        $this->writeFile('libraries/README.md', 'lib readme');

        $builder = new PackageBuilder(
            $this->makeProclaimConfig([], ['vendorPrune' => true]),
            $this->tmpDir
        );
        $this->expectOutputRegex('/Package built/');
        $zip = $builder->build();

        $entries = $this->zipEntries($zip);
        $this->assertContains('libraries/vendor/symfony/dependency/src/X.php', $entries);
        $this->assertContains('libraries/README.md', $entries);
        $this->assertNotContains('libraries/vendor/composer/installed.json', $entries);
        $this->assertNotContains('libraries/vendor/composer/installed.php', $entries);
        $this->assertNotContains('libraries/vendor/symfony/dependency/README.md', $entries);
        $this->assertNotContains('libraries/vendor/symfony/dependency/CHANGELOG.md', $entries);
        $this->assertNotContains('libraries/vendor/symfony/dependency/LICENSE', $entries);
    }

    #[Test]
    public function includeRootsFilterDropsPathsOutsideAllowlist(): void
    {
        $this->writeManifest('proclaim.xml', '1.0.0');
        $this->writeFile('admin/x.php', '<?php');
        $this->writeFile('media/y.css', '');
        $this->writeFile('libraries/z.php', '<?php');
        // Not in any allowlisted root and not a root-level allowlist-ext file:
        $this->writeFile('docs/architecture.md', '# arch');
        $this->writeFile('scratch/temp.txt', 'tmp');

        $builder = new PackageBuilder(
            $this->makeProclaimConfig([], [
                'includeRoots' => ['admin/', 'media/', 'libraries/'],
            ]),
            $this->tmpDir
        );
        $this->expectOutputRegex('/Package built/');
        $zip = $builder->build();

        $entries = $this->zipEntries($zip);
        $this->assertContains('admin/x.php', $entries);
        $this->assertContains('media/y.css', $entries);
        $this->assertContains('libraries/z.php', $entries);
        $this->assertNotContains('docs/architecture.md', $entries);
        $this->assertNotContains('scratch/temp.txt', $entries);
    }

    #[Test]
    public function includeRootExtensionsAdmitsAllowlistedRootFiles(): void
    {
        $this->writeManifest('proclaim.xml', '1.0.0');
        $this->writeFile('admin/x.php', '<?php');
        // Root-level files: only the allowlisted extensions should land in zip.
        // (proclaim.xml is the manifest — it lands at zip root through the
        // dedicated manifest path, not the source walk; tested elsewhere.)
        $this->writeFile('LICENSE.txt', 'GPL2');
        $this->writeFile('README.md', '# readme');
        $this->writeFile('package.json', '{}');                        // .json not allowed
        $this->writeFile('docs.html', '<html/>');                       // .html not allowed

        $builder = new PackageBuilder(
            $this->makeProclaimConfig([], [
                'includeRoots'          => ['admin/'],
                'includeRootExtensions' => ['xml', 'txt', 'md'],
            ]),
            $this->tmpDir
        );
        $this->expectOutputRegex('/Package built/');
        $zip = $builder->build();

        $entries = $this->zipEntries($zip);
        $this->assertContains('admin/x.php', $entries);
        $this->assertContains('LICENSE.txt', $entries);
        $this->assertContains('README.md', $entries);
        $this->assertNotContains('package.json', $entries);
        $this->assertNotContains('docs.html', $entries);
    }

    #[Test]
    public function runModePreBuildExecutesCommand(): void
    {
        $this->writeManifest('proclaim.xml', '1.0.0');
        $this->writeFile('admin/x.php', '<?php');

        // Echo a sentinel into a marker file we can assert was created.
        $marker = $this->tmpDir . '/_marker.txt';
        $cmd    = 'printf hello > ' . escapeshellarg($marker);

        $builder = new PackageBuilder(
            $this->makeProclaimConfig([], [
                'preBuild' => ['mode' => 'run', 'command' => $cmd],
            ]),
            $this->tmpDir
        );
        $this->expectOutputRegex('/Running pre-build:/');
        $builder->build();

        $this->assertFileExists($marker);
        $this->assertSame('hello', file_get_contents($marker));
    }

    #[Test]
    public function runModePreBuildAbortsOnNonZeroExit(): void
    {
        $this->writeManifest('proclaim.xml', '1.0.0');
        $this->writeFile('admin/x.php', '<?php');

        $builder = new PackageBuilder(
            $this->makeProclaimConfig([], [
                'preBuild' => ['mode' => 'run', 'command' => 'false'],
            ]),
            $this->tmpDir
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pre-build command failed');
        $this->expectOutputRegex('/Running pre-build/');
        $builder->build();
    }

    #[Test]
    public function buildConfigRejectsRunPreBuildWithoutCommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('build.preBuild.command is required when mode is "run"');

        BuildConfig::fromArray([
            'outputDir'  => 'build/dist',
            'outputName' => 'foo-{version}.zip',
            'manifest'   => 'foo.xml',
            'sources'    => [['from' => 'src', 'to' => 'src']],
            'preBuild'   => ['mode' => 'run'],
        ]);
    }

    #[Test]
    public function buildConfigRejectsInvalidExcludeMatchMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('build.excludeMatchMode');

        BuildConfig::fromArray([
            'outputDir'        => 'build/dist',
            'outputName'       => 'foo-{version}.zip',
            'manifest'         => 'foo.xml',
            'sources'          => [['from' => 'src', 'to' => 'src']],
            'excludeMatchMode' => 'fuzzy',
        ]);
    }

    // --- 3-way version prompt (versionPrompt) ---

    #[Test]
    public function versionPromptIsSkippedWhenNonInteractive(): void
    {
        // Under PHPUnit (CI=1 or CWM_NONINTERACTIVE=1), Prompt::isNonInteractive()
        // returns true, so the prompt path is bypassed and the manifest version is
        // used. No prompt-options output should appear.
        putenv('CWM_NONINTERACTIVE=1');

        try {
            $this->writeManifest('proclaim.xml', '10.3.2');
            $this->writeFile('admin/x.php', '<?php');

            $config = $this->makeProclaimConfig([], [
                'versionPrompt' => ['enabled' => true, 'timeout' => 5],
            ]);

            $builder = new PackageBuilder($config, $this->tmpDir);
            // Build emits "Building com_proclaim-10.3.2.zip" but NOT "Version options:".
            $this->expectOutputRegex('/Building com_proclaim-10\.3\.2\.zip/');
            $zip = $builder->build();

            $this->assertSame($this->tmpDir . '/build/dist/com_proclaim-10.3.2.zip', $zip);
            $this->assertFileExists($zip);
        } finally {
            putenv('CWM_NONINTERACTIVE');
        }
    }

    #[Test]
    public function versionOverrideShortCircuitsThePrompt(): void
    {
        // Even when versionPrompt is enabled, an explicit --version override
        // skips the prompt entirely (the override is checked before the
        // versionPrompt branch).
        $this->writeManifest('proclaim.xml', '10.3.2');
        $this->writeFile('admin/x.php', '<?php');

        $config = $this->makeProclaimConfig([], [
            'versionPrompt' => ['enabled' => true, 'timeout' => 999],
        ]);

        $builder = new PackageBuilder($config, $this->tmpDir);
        $this->expectOutputRegex('/Building com_proclaim-9\.9\.9\.zip/');
        $zip = $builder->build('9.9.9');

        $this->assertSame($this->tmpDir . '/build/dist/com_proclaim-9.9.9.zip', $zip);
    }

    #[Test]
    public function buildConfigRejectsNonObjectVersionPrompt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('build.versionPrompt must be an object');

        BuildConfig::fromArray([
            'outputDir'     => 'build/dist',
            'outputName'    => 'foo-{version}.zip',
            'manifest'      => 'foo.xml',
            'sources'       => [['from' => 'src', 'to' => 'src']],
            'versionPrompt' => 'yes',
        ]);
    }

    #[Test]
    public function buildConfigRejectsNegativeVersionPromptTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('build.versionPrompt.timeout must be a non-negative integer');

        BuildConfig::fromArray([
            'outputDir'     => 'build/dist',
            'outputName'    => 'foo-{version}.zip',
            'manifest'      => 'foo.xml',
            'sources'       => [['from' => 'src', 'to' => 'src']],
            'versionPrompt' => ['enabled' => true, 'timeout' => -3],
        ]);
    }

    #[Test]
    public function buildConfigDefaultsVersionPromptTimeoutTo10(): void
    {
        $config = BuildConfig::fromArray([
            'outputDir'     => 'build/dist',
            'outputName'    => 'foo-{version}.zip',
            'manifest'      => 'foo.xml',
            'sources'       => [['from' => 'src', 'to' => 'src']],
            'versionPrompt' => ['enabled' => true],
        ]);

        $this->assertNotNull($config->versionPrompt);
        $this->assertSame(true, $config->versionPrompt['enabled']);
        $this->assertSame(10, $config->versionPrompt['timeout']);
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

    /**
     * Build a Proclaim-shape BuildConfig: walks the project root (`from: '.'`)
     * with strict-mode 4-mode excludes. Tests pass `$excludes` as positional
     * (the most-tweaked field) and `$overrides` for the rest.
     *
     * @param  list<string>          $excludes
     * @param  array<string, mixed>  $overrides
     */
    private function makeProclaimConfig(array $excludes = [], array $overrides = []): BuildConfig
    {
        $base = [
            'outputDir'        => 'build/dist',
            'outputName'       => 'com_proclaim-{version}.zip',
            'manifest'         => 'proclaim.xml',
            'sources'          => [['from' => '.', 'to' => '']],
            'excludes'         => $excludes,
            'excludeMatchMode' => 'strict',
        ];

        return BuildConfig::fromArray(array_merge($base, $overrides));
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
