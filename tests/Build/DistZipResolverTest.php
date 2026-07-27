<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Build;

use CWM\BuildTools\Build\DistZipResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DistZipResolverTest extends TestCase
{
    private DistZipResolver $resolver;

    private string $tmp;

    protected function setUp(): void
    {
        $this->resolver = new DistZipResolver();
        $this->tmp      = sys_get_temp_dir() . '/cwm-distzip-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/build/dist', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/build/dist/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp . '/build/dist');
        @rmdir($this->tmp . '/build');
        @rmdir($this->tmp);
    }

    private function zip(string $name, int $mtime): string
    {
        $path = $this->tmp . '/build/dist/' . $name;
        touch($path, $mtime);

        return $path;
    }

    #[Test]
    public function theNewestZipWinsRegardlessOfName(): void
    {
        // Lexically 10.3.10 sorts before 10.3.2; mtime is the contract here
        // (this resolver serves iterative local testing, where "the zip I
        // just built" is the one meant — the release pipeline selects by
        // version instead, in lib/artifacts.sh).
        $this->zip('pkg_x-10.3.2.zip', time() - 100);
        $newest = $this->zip('pkg_x-10.3.10.zip', time());

        $this->assertSame(
            $newest,
            $this->resolver->resolveFromGlob($this->tmp, 'build/dist/pkg_x-*.zip')
        );
    }

    #[Test]
    public function anEmptyGlobConfigurationIsAnActionableError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/outputGlob is not set/');

        $this->resolver->resolveFromGlob($this->tmp, '');
    }

    #[Test]
    public function noMatchesTellsTheUserToBuildFirst(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cwm-build/');

        $this->resolver->resolveFromGlob($this->tmp, 'build/dist/pkg_x-*.zip');
    }

    #[Test]
    public function anExplicitRelativeZipResolvesAgainstTheProjectRoot(): void
    {
        $zip = $this->zip('pkg_x-1.0.0.zip', time());

        $this->assertSame(
            realpath($zip),
            $this->resolver->resolveExplicit($this->tmp, 'build/dist/pkg_x-1.0.0.zip')
        );
    }

    #[Test]
    public function anExplicitAbsoluteZipIsUsedAsIs(): void
    {
        $zip = $this->zip('pkg_x-1.0.0.zip', time());

        $this->assertSame(realpath($zip), $this->resolver->resolveExplicit('/somewhere/else', $zip));
    }

    #[Test]
    public function aMissingExplicitZipIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/--zip path not found/');

        $this->resolver->resolveExplicit($this->tmp, 'build/dist/absent.zip');
    }
}