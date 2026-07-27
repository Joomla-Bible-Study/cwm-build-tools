<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Config\CwmPackage;
use CWM\BuildTools\Dev\LinkPlanner;
use CWM\BuildTools\Dev\PropertiesReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The first of these reproduces the v1.6.1 defect that motivated issue #32:
 * cwm-link linked every configured install rather than only `role=dev`, so a
 * file-backed test install had its extension directories pointed back at the
 * working repo — and the release harness, which wipes and reinstalls that
 * install, would delete the repo's own source.
 *
 * `installsFor()` was well covered at the time. The bug was in the caller, and
 * 214 tests passed throughout.
 */
class LinkPlannerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/cwm-link-planner-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Write a build.properties and return a reader for it.
     */
    private function reader(string $ini): PropertiesReader
    {
        $path = $this->tmp . '/build.properties';
        file_put_contents($path, $ini);

        return new PropertiesReader($path);
    }

    private function makeInstallDir(string $name): string
    {
        $path = $this->tmp . '/' . $name;
        mkdir($path, 0o777, true);

        return $path;
    }

    #[Test]
    public function only_dev_installs_are_linkable(): void
    {
        $dev  = $this->makeInstallDir('j6-dev');
        $test = $this->makeInstallDir('j6-test');

        $reader = $this->reader(<<<INI
            builder.j6dev.path={$dev}
            builder.j6dev.role=dev
            builder.j6test.path={$test}
            builder.j6test.role=test
            INI);

        $plan = LinkPlanner::selectInstalls($reader);

        $paths = array_map(static fn($i) => $i->path, $plan['linkable']);

        self::assertSame([$dev], $paths, 'A role=test install must never be linked');
    }

    #[Test]
    public function a_test_install_is_excluded_even_when_it_is_the_only_one(): void
    {
        $test = $this->makeInstallDir('j6-test');

        $reader = $this->reader(<<<INI
            builder.j6test.path={$test}
            builder.j6test.role=test
            INI);

        $plan = LinkPlanner::selectInstalls($reader);

        self::assertSame([], $plan['linkable']);
        self::assertSame([], $plan['missing'], 'Excluded by role, not reported as missing');
    }

    #[Test]
    public function every_dev_install_is_linkable(): void
    {
        $one = $this->makeInstallDir('j5-dev');
        $two = $this->makeInstallDir('j6-dev');

        $reader = $this->reader(<<<INI
            builder.j5dev.path={$one}
            builder.j5dev.role=dev
            builder.j6dev.path={$two}
            builder.j6dev.role=dev
            INI);

        $plan = LinkPlanner::selectInstalls($reader);

        self::assertCount(2, $plan['linkable']);
    }

    /**
     * A stale entry should be visible, not silently dropped — otherwise the
     * script reports success having linked nothing.
     */
    #[Test]
    public function a_configured_path_that_does_not_exist_is_reported_as_missing(): void
    {
        $real = $this->makeInstallDir('j6-dev');

        $reader = $this->reader(<<<INI
            builder.j6dev.path={$real}
            builder.j6dev.role=dev
            builder.gone.path={$this->tmp}/not-here
            builder.gone.role=dev
            INI);

        $plan = LinkPlanner::selectInstalls($reader);

        self::assertSame([$real], array_map(static fn($i) => $i->path, $plan['linkable']));
        self::assertCount(1, $plan['missing']);
        self::assertStringEndsWith('not-here', $plan['missing'][0]->path);
    }

    #[Test]
    public function no_installs_at_all_yields_two_empty_lists(): void
    {
        $plan = LinkPlanner::selectInstalls($this->reader(''));

        self::assertSame(['linkable' => [], 'missing' => []], $plan);
    }

    #[Test]
    public function dependency_links_group_by_package(): void
    {
        $links = [
            ['package' => 'cwm/scripture', 'source' => 'a', 'target' => 'A'],
            ['package' => 'cwm/links', 'source' => 'b', 'target' => 'B'],
            ['package' => 'cwm/scripture', 'source' => 'c', 'target' => 'C'],
        ];

        $grouped = LinkPlanner::groupByPackage($links);

        self::assertSame(['cwm/scripture', 'cwm/links'], array_keys($grouped), 'First-seen order preserved');
        self::assertCount(2, $grouped['cwm/scripture']);
        self::assertSame(['a', 'c'], array_column($grouped['cwm/scripture'], 'source'), 'Link order preserved');
    }

    #[Test]
    public function grouping_nothing_yields_nothing(): void
    {
        self::assertSame([], LinkPlanner::groupByPackage([]));
    }

    #[Test]
    public function a_package_is_found_by_name(): void
    {
        $wanted = $this->package('cwm/scripture');
        $others = [$this->package('cwm/links'), $wanted];

        self::assertSame($wanted, LinkPlanner::findPackage($others, 'cwm/scripture'));
        self::assertNull(LinkPlanner::findPackage($others, 'cwm/absent'));
        self::assertNull(LinkPlanner::findPackage([], 'cwm/scripture'));
    }

    /**
     * Path versus registry decides whether editing the dependency locally does
     * anything, which is why it is surfaced at all.
     */
    #[Test]
    public function a_path_repo_package_is_labelled_as_such(): void
    {
        $described = LinkPlanner::describePackage($this->package('cwm/scripture', '1.2.3', true));

        self::assertSame(' @ 1.2.3 (path)', $described);
    }

    #[Test]
    public function a_registry_package_is_labelled_as_such(): void
    {
        $described = LinkPlanner::describePackage($this->package('cwm/scripture', '1.2.3', false));

        self::assertSame(' @ 1.2.3 (registry)', $described);
    }

    /**
     * The caller concatenates the result unconditionally, so an uninstalled
     * package must contribute nothing rather than "null" or " @  ()".
     */
    #[Test]
    public function an_absent_package_describes_as_an_empty_string(): void
    {
        self::assertSame('', LinkPlanner::describePackage(null));
    }

    private function package(string $name, string $version = '1.0.0', bool $isPathRepo = false): CwmPackage
    {
        return new CwmPackage(
            name: $name,
            version: $version,
            versionNormalized: $version . '.0',
            joomlaLinks: [],
            installPath: '/tmp/' . $name,
            isPathRepo: $isPathRepo,
            sourcePath: $isPathRepo ? '/tmp/src/' . $name : null,
            reference: null,
        );
    }
}
