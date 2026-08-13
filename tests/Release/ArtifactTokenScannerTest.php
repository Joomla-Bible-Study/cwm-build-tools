<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Release;

use CWM\BuildTools\Release\ArtifactTokenScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ArtifactTokenScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-artifact-scanner-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    // -----------------------------------------------------------------
    // the case the check exists for
    // -----------------------------------------------------------------

    /**
     * pkg_proclaim.zip → packages/pkg_cwmscripture.zip → lib_cwmscripture.zip is
     * three levels, and #75's evidence was found one level in. A scanner that
     * only reads the outer archive would have reported every failure that
     * motivated this as clean.
     */
    #[Test]
    public function finds_a_token_three_archives_deep(): void
    {
        $inner = $this->zip('lib.zip', [
            'lib/src/Provider.php' => "<?php\n/** @since  __DEPLOY_VERSION__ */\n",
        ]);

        $middle = $this->zip('pkg_child.zip', [
            'child/manifest.xml' => "<extension/>\n",
            'lib.zip'            => file_get_contents($inner),
        ]);

        $outer = $this->zip('pkg_outer.zip', [
            'script.install.php' => "<?php\n// clean\n",
            'packages/pkg_child.zip' => file_get_contents($middle),
        ]);

        $findings = (new ArtifactTokenScanner())->scan($outer);

        self::assertCount(1, $findings);
        self::assertSame(
            'pkg_outer.zip!packages/pkg_child.zip!lib.zip!lib/src/Provider.php',
            $findings[0]['path'],
            'The report has to name every archive on the way down, or it cannot be acted on.'
        );
        self::assertSame(2, $findings[0]['line']);
    }

    /**
     * CWMScriptureLinks#29: the package installer shipped ten literal tags. One
     * run must show all ten — re-cutting a release to discover the next
     * occurrence is the failure mode this replaces.
     */
    #[Test]
    public function reports_every_occurrence_not_just_the_first(): void
    {
        $php = "<?php\n";

        for ($i = 0; $i < 10; $i++) {
            $php .= "/** @since  __DEPLOY_VERSION__ */\nfunction f$i() {}\n";
        }

        $archive = $this->zip('pkg.zip', ['script.install.php' => $php]);

        self::assertCount(10, (new ArtifactTokenScanner())->scan($archive));
    }

    // -----------------------------------------------------------------
    // independence from substituteTokens.extensions
    // -----------------------------------------------------------------

    /**
     * `extensions` defaults to ['php'], which is why a token in a manifest or a
     * JS file ships unnoticed. Filtering the assertion the same way would make
     * it inherit the blind spot it exists to catch.
     */
    #[Test]
    public function scans_file_types_the_substituter_never_rewrites(): void
    {
        $archive = $this->zip('pkg.zip', [
            'manifest.xml'      => "<version>__DEPLOY_VERSION__</version>\n",
            'media/js/thing.js' => "// @since __DEPLOY_VERSION__\n",
            'language/en-GB.ini' => "KEY=\"__DEPLOY_VERSION__\"\n",
        ]);

        $paths = array_column((new ArtifactTokenScanner())->scan($archive), 'path');

        self::assertCount(3, $paths);
        self::assertContains('pkg.zip!manifest.xml', $paths);
        self::assertContains('pkg.zip!media/js/thing.js', $paths);
        self::assertContains('pkg.zip!language/en-GB.ini', $paths);
    }

    #[Test]
    public function a_clean_artifact_reports_nothing(): void
    {
        $archive = $this->zip('pkg.zip', [
            'src/Thing.php' => "<?php\n/** @since  1.2.3 */\n",
            'manifest.xml'  => "<version>1.2.3</version>\n",
        ]);

        self::assertSame([], (new ArtifactTokenScanner())->scan($archive));
    }

    #[Test]
    public function honours_a_custom_token(): void
    {
        $archive = $this->zip('pkg.zip', [
            'src/Thing.php' => "<?php\n/** @since  @@VERSION@@ */\n",
        ]);

        self::assertSame([], (new ArtifactTokenScanner())->scan($archive));
        self::assertCount(1, (new ArtifactTokenScanner('@@VERSION@@'))->scan($archive));
    }

    // -----------------------------------------------------------------
    // robustness — a release gate must not fall over on real archives
    // -----------------------------------------------------------------

    #[Test]
    public function skips_binary_entries_without_choking(): void
    {
        $archive = $this->zip('pkg.zip', [
            'media/logo.png' => "\x89PNG\r\n\x1a\n\0\0__DEPLOY_VERSION__\0",
            'src/Thing.php'  => "<?php\n/** @since  __DEPLOY_VERSION__ */\n",
        ]);

        $findings = (new ArtifactTokenScanner())->scan($archive);

        self::assertCount(1, $findings, 'A NUL-bearing entry is not source and must not be reported.');
        self::assertSame('pkg.zip!src/Thing.php', $findings[0]['path']);
    }

    /**
     * A child that will not open is reported rather than silently dropped:
     * "one entry was unreadable" must not look identical to "clean".
     */
    #[Test]
    public function reports_an_unopenable_nested_archive(): void
    {
        $archive = $this->zip('pkg.zip', [
            'packages/broken.zip' => 'this is not a zip',
        ]);

        $findings = (new ArtifactTokenScanner())->scan($archive);

        self::assertCount(1, $findings);
        self::assertStringContainsString('could not be opened', $findings[0]['text']);
    }

    #[Test]
    public function reports_a_missing_archive_rather_than_claiming_it_is_clean(): void
    {
        $findings = (new ArtifactTokenScanner())->scan($this->tmpDir . '/nope.zip');

        self::assertCount(1, $findings);
        self::assertStringContainsString('could not be opened', $findings[0]['text']);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * @param array<string, string> $entries
     */
    private function zip(string $name, array $entries): string
    {
        $path = $this->tmpDir . '/' . $name;
        $zip  = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            self::fail("Could not create fixture archive $name");
        }

        foreach ($entries as $entryName => $contents) {
            $zip->addFromString($entryName, $contents);
        }

        $zip->close();

        return $path;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
