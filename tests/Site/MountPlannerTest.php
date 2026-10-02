<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\Mount;
use CWM\BuildTools\Site\MountPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MountPlannerTest extends TestCase
{
    private MountPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new MountPlanner();
    }

    /**
     * @param  list<Mount>  $mounts
     *
     * @return array<string, string>  container => host
     */
    private function byContainer(array $mounts): array
    {
        $out = [];

        foreach ($mounts as $m) {
            $out[$m->container] = $m->host;
        }

        return $out;
    }

    #[Test]
    public function sourceBesideTheSitesFolderMountsUnderVar(): void
    {
        $mounts = $this->byContainer($this->planner->plan('/Volumes/X/Sites/dev', ['/Volumes/X/GitHub/Proclaim']));

        $this->assertSame('/Volumes/X/GitHub/Proclaim', $mounts['/var/GitHub/Proclaim']);
    }

    #[Test]
    public function siteDirectlyBesideTheSourceMountsUnderVarWww(): void
    {
        $mounts = $this->byContainer($this->planner->plan('/work/site', ['/work/repo']));

        $this->assertSame('/work/repo', $mounts['/var/www/repo']);
    }

    #[Test]
    public function threeLevelsBelowTheAncestorMountsAtTheContainerRoot(): void
    {
        $mounts = $this->byContainer($this->planner->plan('/a/b/c/site', ['/a/src/repo']));

        $this->assertSame('/a/src/repo', $mounts['/src/repo']);
    }

    #[Test]
    public function everySiteAndSourceIsAlsoMountedAtItsHostPath(): void
    {
        $mounts = $this->byContainer($this->planner->plan('/Volumes/X/Sites/dev', ['/Volumes/X/GitHub/Proclaim']));

        $this->assertSame('/Volumes/X/Sites/dev', $mounts['/Volumes/X/Sites/dev']);
        $this->assertSame('/Volumes/X/GitHub/Proclaim', $mounts['/Volumes/X/GitHub/Proclaim']);
    }

    #[Test]
    public function twoSourcesAreEachPlannedAndNothingIsDuplicated(): void
    {
        $mounts = $this->planner->plan(
            '/Volumes/X/Sites/dev',
            ['/Volumes/X/GitHub/Proclaim', '/Volumes/X/GitHub/Proclaim', '/Volumes/X/GitHub/lib']
        );

        $containers = array_map(static fn (Mount $m): string => $m->container, $mounts);

        $this->assertSame($containers, array_values(array_unique($containers)));
        $this->assertContains('/var/GitHub/lib', $containers);
    }

    #[Test]
    public function aSourceInsideTheSiteNeedsNoRelativeMount(): void
    {
        $mounts = $this->planner->plan('/work/site', ['/work/site/src']);

        $reasons = array_map(static fn (Mount $m): string => $m->reason, $mounts);

        $this->assertNotContains('source where the relative symlinks written on the host resolve', $reasons);
    }

    #[Test]
    public function aSourceContainingTheSiteIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains the site');

        $this->planner->plan('/work/repo/sites/dev', ['/work/repo']);
    }

    #[Test]
    public function aSiteDeeperThanTheContainerCanClimbIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Move the site closer');

        $this->planner->plan('/a/b/c/d/site', ['/a/src']);
    }

    #[Test]
    public function aRelativePathIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be absolute');

        $this->planner->plan('Sites/dev', ['/work/repo']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function layouts(): array
    {
        return [
            'sites folder and github folder' => ['/Volumes/X/Sites/dev', '/Volumes/X/GitHub/Proclaim', 'administrator/components/com_proclaim'],
            'site beside repo'               => ['/work/site', '/work/repo', 'plugins/system/thing'],
            'three below ancestor'           => ['/a/b/c/site', '/a/src/repo', 'media/com_thing'],
            'worktree beside main checkout'  => ['/Volumes/X/Sites/v11', '/Volumes/X/GitHub/Proclaim-11', 'components/com_proclaim'],
        ];
    }

    /**
     * The property the whole class exists for: a relative link computed from
     * the host layout, resolved against the container layout, lands on the
     * mounted source.
     */
    #[Test]
    #[DataProvider('layouts')]
    public function aRelativeLinkWrittenOnTheHostResolvesInsideTheContainer(string $site, string $source, string $linkDir): void
    {
        $mounts = $this->planner->plan($site, [$source]);

        $hostLinkDir = $site . '/' . $linkDir;
        $hostTarget  = $source . '/admin';
        $relative    = $this->relativePath($hostLinkDir, $hostTarget);

        $containerLinkDir = MountPlanner::DOCROOT . '/' . $linkDir;
        $resolved         = $this->normalize($containerLinkDir . '/' . $relative);

        $expected = null;

        foreach ($mounts as $m) {
            if ($m->host === $source && $m->reason === 'source where the relative symlinks written on the host resolve') {
                $expected = $m->container . '/admin';
            }
        }

        $this->assertNotNull($expected, 'a relative-link mount was planned');
        $this->assertSame($expected, $resolved);
    }

    private function relativePath(string $fromDir, string $to): string
    {
        $from = array_values(array_filter(explode('/', $fromDir), static fn (string $s): bool => $s !== ''));
        $dest = array_values(array_filter(explode('/', $to), static fn (string $s): bool => $s !== ''));

        $i = 0;

        while ($i < \count($from) && $i < \count($dest) && $from[$i] === $dest[$i]) {
            $i++;
        }

        return implode('/', array_merge(array_fill(0, \count($from) - $i, '..'), \array_slice($dest, $i)));
    }

    private function normalize(string $path): string
    {
        $out = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($out);

                continue;
            }

            $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }
}
