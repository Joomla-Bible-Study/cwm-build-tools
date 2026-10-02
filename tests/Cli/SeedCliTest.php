<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Cli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs `scripts/seed.php` as a real subprocess with real layer scripts, each of
 * which records what it was asked to do and which site it was told about.
 */
final class SeedCliTest extends TestCase
{
    private string $tmp;

    private string $project;

    private string $log;

    protected function setUp(): void
    {
        $this->tmp     = (string) realpath(sys_get_temp_dir()) . '/cwm-seed-cli-' . bin2hex(random_bytes(6));
        $this->project = $this->tmp . '/proj';
        $this->log     = $this->tmp . '/seed.log';

        mkdir($this->project . '/build/seed', 0o777, true);

        foreach (['dev1', 'test1', 'test2'] as $site) {
            mkdir($this->tmp . '/sites/' . $site, 0o777, true);
        }

        // Every layer logs "<layer> <action> <site> <role> <marker> <dbhost>" and fails when told to.
        $script = <<<'PHP'
            <?php
            file_put_contents(
                getenv('SEED_LOG'),
                implode(' ', [getenv('CWM_SEED_LAYER'), getenv('CWM_SEED_ACTION'), getenv('CWM_SEED_SITE_ID'), getenv('CWM_SEED_SITE_ROLE'), getenv('CWM_SEED_MARKER'), getenv('CWM_SEED_DB_HOST') ?: '-', $argv[1] ?? '?']) . "\n",
                FILE_APPEND
            );
            exit(in_array(getenv('CWM_SEED_LAYER'), explode(',', (string) getenv('FAIL_LAYERS')), true) ? 3 : 0);
            PHP;

        foreach (['content', 'scenarios', 'volume'] as $layer) {
            file_put_contents($this->project . '/build/seed/' . $layer . '.php', $script);
        }

        file_put_contents($this->project . '/cwm-build.config.json', json_encode(['seed' => [
            'marker'         => 'cwmseed-',
            'defaultProfile' => 'test',
            'layers'         => [
                ['name' => 'content', 'script' => 'build/seed/content.php', 'description' => 'the baseline'],
                ['name' => 'scenarios', 'script' => 'build/seed/scenarios.php', 'description' => 'awkward cases'],
                ['name' => 'volume', 'script' => 'build/seed/volume.php'],
            ],
            'profiles' => ['test' => ['content', 'scenarios'], 'bulk' => ['content', 'scenarios', 'volume']],
        ]]));

        file_put_contents($this->project . '/build.properties', <<<PROPS
            builder.installs=dev1, test1, test2
            builder.dev1.role=dev
            builder.dev1.path={$this->tmp}/sites/dev1
            builder.test1.role=test
            builder.test1.path={$this->tmp}/sites/test1
            builder.test1.db_host=127.0.0.1:33061
            builder.test2.role=test
            builder.test2.path={$this->tmp}/sites/test2
            PROPS);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    /**
     * @param  list<string>           $args
     * @param  array<string, string>  $env
     *
     * @return array{int, string, string}
     */
    private function seed(array $args, array $env = []): array
    {
        $cmd     = array_merge([PHP_BINARY, \dirname(__DIR__, 2) . '/scripts/seed.php'], $args);
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->project, ['PATH' => (string) getenv('PATH'), 'SEED_LOG' => $this->log] + $env);
        $this->assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /**
     * @return list<string>
     */
    private function logged(): array
    {
        return is_file($this->log) ? array_values(array_filter(explode("\n", (string) file_get_contents($this->log)))) : [];
    }

    #[Test]
    public function listShowsTheMarkerLayersAndProfilesWithoutNeedingASite(): void
    {
        unlink($this->project . '/build.properties');

        [$exit, $out] = $this->seed(['--list']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Marker:   cwmseed-', $out);
        $this->assertStringContainsString('content', $out);
        $this->assertStringContainsString('the baseline', $out);
        $this->assertMatchesRegularExpression('/test\s+content, scenarios\s+\(default\)/', $out);
        $this->assertSame([], $this->logged(), 'nothing ran');
    }

    #[Test]
    public function aProfileIsAppliedToEveryTestSiteInOrderAndNeverToTheDevSite(): void
    {
        [$exit, $out, $err] = $this->seed(['test']);

        $this->assertSame(0, $exit, $out . $err);
        // layer, action, site, role, marker, db host (as recorded, or '-')
        $fields = array_map(static fn (string $l): string => implode(' ', \array_slice(explode(' ', $l), 0, 6)), $this->logged());

        $this->assertSame([
            'content apply test1 test cwmseed- 127.0.0.1:33061',
            'scenarios apply test1 test cwmseed- 127.0.0.1:33061',
            'content apply test2 test cwmseed- -',
            'scenarios apply test2 test cwmseed- -',
        ], $fields, 'test1 has a recorded address and test2 does not; dev1 is not seeded');
    }

    #[Test]
    public function noRequestUsesTheDefaultProfile(): void
    {
        [$exit] = $this->seed([]);

        $this->assertSame(0, $exit);
        $this->assertCount(4, $this->logged(), 'two layers on each of two test sites');
    }

    #[Test]
    public function aDevSiteIsSeededOnlyWhenNamed(): void
    {
        [$exit, $out, $err] = $this->seed(['test', '--install', 'dev1']);

        $this->assertSame(0, $exit, $out . $err);

        $sites = array_map(static fn (string $l): string => explode(' ', $l)[2], $this->logged());
        $this->assertSame(['dev1', 'dev1'], $sites);
    }

    #[Test]
    public function removeRunsTheLayersInReverse(): void
    {
        [$exit, $out, $err] = $this->seed(['bulk', '--install', 'test1', '--remove']);

        $this->assertSame(0, $exit, $out . $err);
        $this->assertSame(['volume remove', 'scenarios remove', 'content remove'], array_map(static fn (string $l): string => implode(' ', \array_slice(explode(' ', $l), 0, 2)), $this->logged()));
    }

    #[Test]
    public function layersNamedDirectlyRunInTheOrderTheProjectDeclaredThem(): void
    {
        [$exit] = $this->seed(['--layer', 'volume', '--layer', 'content', '--install', 'test1']);

        $this->assertSame(0, $exit);
        $this->assertSame(['content', 'volume'], array_map(static fn (string $l): string => explode(' ', $l)[0], $this->logged()));
    }

    #[Test]
    public function dryRunNamesWhatWouldRunAndRunsNothing(): void
    {
        [$exit, $out] = $this->seed(['bulk', '--install', 'test1', '--dry-run']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Would apply test1 (role test): content, scenarios, volume', $out);
        $this->assertStringContainsString('Dry run: nothing was run.', $out);
        $this->assertSame([], $this->logged());
    }

    #[Test]
    public function aFailingLayerStopsTheApplyAndSaysWhatToDo(): void
    {
        [$exit, , $err] = $this->seed(['bulk', '--install', 'test1'], ['FAIL_LAYERS' => 'scenarios']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Layer scenarios (exit 3) failed.', $err);
        $this->assertStringContainsString('Already applied: content.', $err);
        $this->assertStringContainsString('--remove', $err);
        $this->assertSame(['content', 'scenarios'], array_map(static fn (string $l): string => explode(' ', $l)[0], $this->logged()), 'volume was not attempted');
    }

    #[Test]
    public function aFailingLayerDoesNotStopTheRemove(): void
    {
        [$exit, , $err] = $this->seed(['bulk', '--install', 'test1', '--remove'], ['FAIL_LAYERS' => 'scenarios']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Could not remove 1 layer(s): scenarios (exit 3).', $err);
        $this->assertCount(3, $this->logged(), 'every layer was tried');
    }

    #[Test]
    public function aProfileAndLayersTogetherAreRefusedBeforeAnythingRuns(): void
    {
        [$exit, , $err] = $this->seed(['test', '--layer', 'content']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not both', $err);
        $this->assertSame([], $this->logged());
    }

    #[Test]
    public function aProjectWithNoSeedBlockSaysSo(): void
    {
        file_put_contents($this->project . '/cwm-build.config.json', '{"extension":{}}');

        [$exit, , $err] = $this->seed(['test']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no "seed" block', $err);
    }

    #[Test]
    public function anUnknownInstallListsTheKnownOnesAndRunsNothing(): void
    {
        [$exit, , $err] = $this->seed(['test', '--install', 'prod']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Known installs: dev1, test1, test2.', $err);
        $this->assertSame([], $this->logged());
    }

    private function rrmdir(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->rrmdir($path . '/' . $entry);
        }

        @rmdir($path);
    }
}
