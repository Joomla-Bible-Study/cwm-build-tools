<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\JoomlaInstaller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the extraction and the target-directory rules through a local
 * file:// zip, so no network is involved.
 */
class JoomlaInstallerTest extends TestCase
{
    private string $tmp;

    private string $zipUrl;

    protected function setUp(): void
    {
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/cwm-joomla-inst-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o777, true);

        $zipPath = $this->tmp . '/joomla.zip';
        $zip     = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('index.php', '<?php // joomla');
        $zip->addFromString('images/joomla_black.png', 'png');
        $zip->close();

        $this->zipUrl = 'file://' . $zipPath;
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function install(string $target, array $tolerate = []): void
    {
        ob_start();

        try {
            (new JoomlaInstaller())->install('0.0.0', $target, $this->zipUrl, $tolerate);
        } finally {
            ob_end_clean();
        }
    }

    #[Test]
    public function extractsIntoAnEmptyDirectory(): void
    {
        $target = $this->tmp . '/site';
        mkdir($target);

        $this->install($target);

        $this->assertFileExists($target . '/index.php');
    }

    #[Test]
    public function aNonEmptyDirectoryIsRefusedAndNamesWhatIsInIt(): void
    {
        $target = $this->tmp . '/site';
        mkdir($target);
        file_put_contents($target . '/notes.txt', 'mine');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('notes.txt');

        $this->install($target);
    }

    #[Test]
    public function aToleratedNameMayAlreadyExist(): void
    {
        $target = $this->tmp . '/site';
        mkdir($target . '/.ddev', 0o777, true);
        file_put_contents($target . '/.ddev/config.yaml', 'name: x');

        $this->install($target, ['.ddev']);

        $this->assertFileExists($target . '/index.php');
        $this->assertFileExists($target . '/.ddev/config.yaml', 'the tolerated entry is left alone');
    }

    #[Test]
    public function anEmptyDirectoryIsAlwaysTolerated(): void
    {
        $target = $this->tmp . '/site';
        mkdir($target . '/images', 0o777, true);

        $this->install($target);

        $this->assertFileExists($target . '/index.php');
    }

    #[Test]
    public function aNonEmptyDirectoryThatIsNotToleratedStillBlocks(): void
    {
        $target = $this->tmp . '/site';
        mkdir($target . '/images', 0o777, true);
        file_put_contents($target . '/images/mine.jpg', 'x');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('images');

        $this->install($target);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->rrmdir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
