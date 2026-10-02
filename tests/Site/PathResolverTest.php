<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\PathResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PathResolverTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/cwm-pathres-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/real/inside', 0o777, true);
        symlink($this->tmp . '/real', $this->tmp . '/link');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp . '/link');
        @rmdir($this->tmp . '/real/inside');
        @rmdir($this->tmp . '/real');
        @rmdir($this->tmp);
    }

    #[Test]
    public function anExistingPathThroughASymlinkResolvesToTheRealOne(): void
    {
        $this->assertSame($this->tmp . '/real/inside', (new PathResolver())->canonical($this->tmp . '/link/inside'));
    }

    #[Test]
    public function aPathThatDoesNotExistYetKeepsItsTailAndResolvesItsParent(): void
    {
        $this->assertSame(
            $this->tmp . '/real/inside/new/site',
            (new PathResolver())->canonical($this->tmp . '/link/inside/new/site')
        );
    }

    #[Test]
    public function aRelativePathIsMadeAbsoluteAgainstTheGivenDirectory(): void
    {
        $this->assertSame($this->tmp . '/real/inside/x', (new PathResolver())->canonical('inside/x', $this->tmp . '/link'));
    }

    #[Test]
    public function dotSegmentsAreCollapsed(): void
    {
        $this->assertSame($this->tmp . '/real/y', (new PathResolver())->canonical($this->tmp . '/real/inside/../y'));
    }

    #[Test]
    public function aTrailingSlashIsIgnored(): void
    {
        $this->assertSame($this->tmp . '/real/inside', (new PathResolver())->canonical($this->tmp . '/real/inside/'));
    }

    #[Test]
    public function anEmptyPathIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PathResolver())->canonical('');
    }
}
