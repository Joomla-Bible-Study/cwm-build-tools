<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Seed;

use CWM\BuildTools\Dev\InstallConfig;
use CWM\BuildTools\Seed\SeedConfig;
use CWM\BuildTools\Seed\SeedException;
use CWM\BuildTools\Seed\SeedLayer;
use CWM\BuildTools\Seed\SeedRunner;
use CWM\BuildTools\Site\CommandResult;
use CWM\BuildTools\Tests\Site\FakeRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SeedRunnerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/cwm-seedrun-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/build/seed', 0o777, true);

        foreach (['a', 'b', 'c'] as $f) {
            file_put_contents($this->tmp . '/build/seed/' . $f . '.php', '<?php');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/build/seed/*') ?: [] as $f) {
            @unlink($f);
        }

        @rmdir($this->tmp . '/build/seed');
        @rmdir($this->tmp . '/build');
        @rmdir($this->tmp);
    }

    private function config(): SeedConfig
    {
        return SeedConfig::fromProjectConfig(['seed' => [
            'marker' => 'cwmseed-',
            'layers' => [
                ['name' => 'a', 'script' => 'build/seed/a.php'],
                ['name' => 'b', 'script' => 'build/seed/b.php'],
                ['name' => 'c', 'script' => 'build/seed/c.php'],
            ],
        ]], $this->tmp);
    }

    /**
     * @return list<SeedLayer>
     */
    private function layers(): array
    {
        return array_values($this->config()->layers);
    }

    private function install(array $db = []): InstallConfig
    {
        return new InstallConfig(id: 'j6', path: '/sites/j6', role: 'test', db: $db);
    }

    private function quiet(): callable
    {
        return static function (): void {
        };
    }

    #[Test]
    public function appliesLayersInOrderFromTheProjectRootStreamingTheirOutput(): void
    {
        $runner = new FakeRunner();

        $done = (new SeedRunner($runner, '/usr/bin/php'))->run($this->config(), $this->layers(), 'apply', $this->install(), $this->tmp, $this->quiet());

        $this->assertSame(['a', 'b', 'c'], $done);
        $this->assertSame(
            ['/usr/bin/php ' . $this->tmp . '/build/seed/a.php apply', '/usr/bin/php ' . $this->tmp . '/build/seed/b.php apply', '/usr/bin/php ' . $this->tmp . '/build/seed/c.php apply'],
            $runner->commandLines()
        );
        $this->assertSame($this->tmp, $runner->calls[0]['cwd']);
        $this->assertTrue($runner->calls[0]['stream']);
    }

    #[Test]
    public function removingRunsTheLayersInReverse(): void
    {
        $runner = new FakeRunner();

        (new SeedRunner($runner, '/usr/bin/php'))->run($this->config(), $this->layers(), 'remove', $this->install(), $this->tmp, $this->quiet());

        $this->assertSame(['c.php remove', 'b.php remove', 'a.php remove'], array_map(static fn (string $l): string => basename(explode(' ', $l)[1]) . ' ' . explode(' ', $l)[2], $runner->commandLines()));
    }

    #[Test]
    public function eachLayerIsToldWhichSiteAndLayerItIs(): void
    {
        $runner = new FakeRunner();

        (new SeedRunner($runner))->run($this->config(), $this->layers(), 'apply', $this->install(['host' => '127.0.0.1:33061']), $this->tmp, $this->quiet());

        $this->assertSame([
            'CWM_SEED_ACTION'    => 'apply',
            'CWM_SEED_LAYER'     => 'b',
            'CWM_SEED_MARKER'    => 'cwmseed-',
            'CWM_SEED_SITE_ID'   => 'j6',
            'CWM_SEED_SITE_PATH' => '/sites/j6',
            'CWM_SEED_SITE_ROLE' => 'test',
            'CWM_SEED_DB_HOST'   => '127.0.0.1:33061',
        ], $runner->calls[1]['env']);
    }

    #[Test]
    public function noDbHostIsSentWhenBuildPropertiesRecordsNone(): void
    {
        $runner = new FakeRunner();

        (new SeedRunner($runner))->run($this->config(), $this->layers(), 'apply', $this->install(), $this->tmp, $this->quiet());

        $this->assertArrayNotHasKey('CWM_SEED_DB_HOST', $runner->calls[0]['env']);
    }

    #[Test]
    public function applyingStopsAtTheFirstFailureAndSaysWhatAlreadyRan(): void
    {
        $runner = new FakeRunner(['/usr/bin/php ' . $this->tmp . '/build/seed/b.php' => new CommandResult(3)]);

        try {
            (new SeedRunner($runner, '/usr/bin/php'))->run($this->config(), $this->layers(), 'apply', $this->install(), $this->tmp, $this->quiet());
            $this->fail('expected a SeedException');
        } catch (SeedException $e) {
            $this->assertStringContainsString('Layer b (exit 3) failed.', $e->getMessage());
            $this->assertStringContainsString('Already applied: a.', $e->getMessage());
            $this->assertStringContainsString('--remove', $e->getMessage());
        }

        $this->assertCount(2, $runner->calls, 'layer c was not attempted');
    }

    #[Test]
    public function removingCarriesOnPastAFailureAndReportsThemAll(): void
    {
        $runner = new FakeRunner([
            '/usr/bin/php ' . $this->tmp . '/build/seed/c.php' => new CommandResult(1),
            '/usr/bin/php ' . $this->tmp . '/build/seed/a.php' => new CommandResult(2),
        ]);

        try {
            (new SeedRunner($runner, '/usr/bin/php'))->run($this->config(), $this->layers(), 'remove', $this->install(), $this->tmp, $this->quiet());
            $this->fail('expected a SeedException');
        } catch (SeedException $e) {
            $this->assertStringContainsString('Could not remove 2 layer(s): c (exit 1), a (exit 2).', $e->getMessage());
            $this->assertStringContainsString('Already removed: b.', $e->getMessage());
        }

        $this->assertCount(3, $runner->calls, 'every layer was tried');
    }

    #[Test]
    public function anUnknownActionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SeedRunner(new FakeRunner()))->run($this->config(), $this->layers(), 'wipe', $this->install(), $this->tmp, $this->quiet());
    }
}
