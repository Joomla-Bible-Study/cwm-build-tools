<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\PackageLocator;
use CWM\BuildTools\Site\SiteException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PackageLocatorTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/cwm-locator-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/build/dist', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/build/dist/*') ?: [] as $f) {
            @unlink($f);
        }

        @unlink($this->tmp . '/cwm-build.config.json');
        @rmdir($this->tmp . '/build/dist');
        @rmdir($this->tmp . '/build');
        @rmdir($this->tmp);
    }

    private function config(string $glob): void
    {
        file_put_contents($this->tmp . '/cwm-build.config.json', json_encode(['build' => ['outputGlob' => $glob]]));
    }

    #[Test]
    public function noneMeansNoPackageAndNoNote(): void
    {
        $this->assertSame(['path' => null, 'note' => null], (new PackageLocator())->locate('none', $this->tmp, $this->tmp));
    }

    #[Test]
    public function autoPicksTheNewestZipMatchingTheProjectsOutputGlob(): void
    {
        $this->config('build/dist/pkg_x-*.zip');
        file_put_contents($this->tmp . '/build/dist/pkg_x-1.0.0.zip', 'old');
        touch($this->tmp . '/build/dist/pkg_x-1.0.0.zip', time() - 600);
        file_put_contents($this->tmp . '/build/dist/pkg_x-2.0.0.zip', 'new');

        $located = (new PackageLocator())->locate('auto', $this->tmp, $this->tmp);

        $this->assertSame($this->tmp . '/build/dist/pkg_x-2.0.0.zip', $located['path']);
        $this->assertNull($located['note']);
    }

    #[Test]
    public function autoWithNothingBuiltReturnsTheReasonInsteadOfFailing(): void
    {
        $this->config('build/dist/pkg_x-*.zip');

        $located = (new PackageLocator())->locate('auto', $this->tmp, $this->tmp);

        $this->assertNull($located['path']);
        $this->assertStringContainsString('No zip matched', (string) $located['note']);
    }

    #[Test]
    public function autoWithNoConfigReturnsTheReasonToo(): void
    {
        $located = (new PackageLocator())->locate('auto', $this->tmp, $this->tmp);

        $this->assertNull($located['path']);
        $this->assertStringContainsString('build.outputGlob is not set', (string) $located['note']);
    }

    #[Test]
    public function anExplicitPathIsResolvedAgainstTheProjectRoot(): void
    {
        file_put_contents($this->tmp . '/build/dist/mine.zip', 'z');

        $located = (new PackageLocator())->locate('build/dist/mine.zip', $this->tmp, '/elsewhere');

        $this->assertSame($this->tmp . '/build/dist/mine.zip', $located['path']);
    }

    #[Test]
    public function aMissingExplicitPathFailsAndNamesTheFlagThisCommandUses(): void
    {
        try {
            (new PackageLocator())->locate('/no/such.zip', $this->tmp, $this->tmp);
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('--package path not found', $e->getMessage());
            $this->assertStringNotContainsString('--zip', $e->getMessage());
        }
    }
}
