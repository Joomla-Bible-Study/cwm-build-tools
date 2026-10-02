<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\CommandResult;
use CWM\BuildTools\Site\DdevConfig;
use CWM\BuildTools\Site\DdevEnvironment;
use CWM\BuildTools\Site\JoomlaInstallStage;
use CWM\BuildTools\Site\JoomlaSettings;
use CWM\BuildTools\Site\MountPlanner;
use CWM\BuildTools\Site\SiteException;
use CWM\BuildTools\Site\SiteSpec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class JoomlaInstallStageTest extends TestCase
{
    private string $tmp;

    private int $slept = 0;

    protected function setUp(): void
    {
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/cwm-joomla-stage-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/Sites/j6', 0o777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp . '/Sites/j6/configuration.php');
        @rmdir($this->tmp . '/Sites/j6');
        @rmdir($this->tmp . '/Sites');
        @rmdir($this->tmp);
    }

    private function spec(): SiteSpec
    {
        return new SiteSpec('j6', $this->tmp . '/Sites/j6', '8.3', 33061, [$this->tmp . '/proj']);
    }

    private function settings(?string $version = '6.1.4', string $name = 'Proclaim J6'): JoomlaSettings
    {
        return new JoomlaSettings($version, $name, 'admin', 'S3cretPassw0rd!', 'admin@example.com', 'jabcd_');
    }

    private function stage(FakeRunner $runner, FakeJoomlaSource $source): JoomlaInstallStage
    {
        $env = new DdevEnvironment(
            $runner,
            new DdevConfig(),
            new MountPlanner(),
            $this->tmp . '/none.yaml',
            function (int $s): void {
                $this->slept += $s;
            }
        );

        return new JoomlaInstallStage($env, $source);
    }

    private function quiet(): callable
    {
        return static function (): void {
        };
    }

    #[Test]
    public function installsInOrderAndReturnsTheVersion(): void
    {
        $runner = new FakeRunner();
        $source = new FakeJoomlaSource();

        $version = $this->stage($runner, $source)->run($this->spec(), $this->settings(), $this->quiet());

        $this->assertSame('6.1.4', $version);
        $this->assertSame([['version' => '6.1.4', 'path' => $this->tmp . '/Sites/j6', 'tolerate' => ['.ddev']]], $source->fetched);

        $lines = $runner->commandLines();

        $position = static function (string $needle) use ($lines): int {
            foreach ($lines as $i => $line) {
                if (str_contains($line, $needle)) {
                    return $i;
                }
            }

            return -1;
        };

        $steps = [
            $position("'test' '-f' 'installation/joomla.php'"),
            $position("'install'"),
            $position("'test' '-f' 'configuration.php'"),
            $position('extension:list'),
        ];

        $this->assertNotContains(-1, $steps, 'every step ran');
        $sorted = $steps;
        sort($sorted);
        $this->assertSame($sorted, $steps, 'wait for files, install, wait for config, boot check');
    }

    #[Test]
    public function usesTheLatestVersionWhenNoneIsGiven(): void
    {
        $source = new FakeJoomlaSource('6.2.0');

        $version = $this->stage(new FakeRunner(), $source)->run($this->spec(), $this->settings(null), $this->quiet());

        $this->assertSame('6.2.0', $version);
        $this->assertSame('6.2.0', $source->fetched[0]['version']);
    }

    #[Test]
    public function theInstallerIsPointedAtDdevsDatabaseAndTheChosenPrefix(): void
    {
        $runner = new FakeRunner();

        $this->stage($runner, new FakeJoomlaSource())->run($this->spec(), $this->settings(), $this->quiet());

        $install = implode("\n", array_filter($runner->commandLines(), static fn (string $l): bool => str_contains($l, "'install'")));

        foreach (['--db-host=db', '--db-name=db', '--db-user=db', '--db-pass=db', '--db-prefix=jabcd_', '--db-type=mysqli', '--admin-username=admin', '--no-interaction'] as $expected) {
            $this->assertStringContainsString("'" . $expected . "'", $install);
        }
    }

    #[Test]
    public function aSiteNameWithSpacesStaysOneArgument(): void
    {
        $runner = new FakeRunner();

        $this->stage($runner, new FakeJoomlaSource())->run($this->spec(), $this->settings('6.1.4', "My Church's Site"), $this->quiet());

        $install = implode("\n", array_filter($runner->commandLines(), static fn (string $l): bool => str_contains($l, "'install'")));

        $this->assertStringContainsString("'--site-name=My Church'\\''s Site'", $install);
    }

    #[Test]
    public function everyCommandRunsInsideTheSiteFolder(): void
    {
        $runner = new FakeRunner();

        $this->stage($runner, new FakeJoomlaSource())->run($this->spec(), $this->settings(), $this->quiet());

        foreach ($runner->calls as $call) {
            $this->assertSame($this->tmp . '/Sites/j6', $call['cwd']);
        }
    }

    #[Test]
    public function aFailedInstallerShowsItsOutputWithoutThePasswordAndSaysThereIsAPartialInstall(): void
    {
        $runner = new FakeRunner([], null, static function (string $line): ?CommandResult {
            return str_contains($line, "'install'")
                ? new CommandResult(1, "Checking system requirements...FAILED\nbad arg --admin-password=S3cretPassw0rd!", '')
                : null;
        });

        try {
            $this->stage($runner, new FakeJoomlaSource())->run($this->spec(), $this->settings(), $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('Checking system requirements...FAILED', $e->getMessage());
            $this->assertStringNotContainsString('S3cretPassw0rd!', $e->getMessage());
            $this->assertStringContainsString('partial install', $e->getMessage());
        }

        $this->assertStringNotContainsString('extension:list', implode("\n", $runner->commandLines()));
    }

    #[Test]
    public function installedButNotBootingIsReported(): void
    {
        $runner = new FakeRunner([], null, static function (string $line): ?CommandResult {
            return str_contains($line, 'extension:list') ? new CommandResult(255, '', 'mysqli_sql_exception') : null;
        });

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('does not boot');

        $this->stage($runner, new FakeJoomlaSource())->run($this->spec(), $this->settings(), $this->quiet());
    }

    #[Test]
    public function anExistingInstallIsRefusedBeforeAnythingIsFetched(): void
    {
        file_put_contents($this->tmp . '/Sites/j6/configuration.php', '<?php');
        $source = new FakeJoomlaSource();

        try {
            $this->stage(new FakeRunner(), $source)->run($this->spec(), $this->settings(), $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('already installed', $e->getMessage());
        }

        $this->assertSame([], $source->fetched);
    }

    #[Test]
    public function aMalformedVersionIsRefusedBeforeAnythingIsFetched(): void
    {
        $source = new FakeJoomlaSource();

        try {
            $this->stage(new FakeRunner(), $source)->run($this->spec(), $this->settings('six'), $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('such as 6.1.4', $e->getMessage());
        }

        $this->assertSame([], $source->fetched);
    }

    #[Test]
    public function aFailedDownloadIsReportedWithTheSourcesReason(): void
    {
        $source           = new FakeJoomlaSource();
        $source->failWith = new \RuntimeException('Download failed for https://example.test/x.zip');

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('Download failed for https://example.test/x.zip');

        $this->stage(new FakeRunner(), $source)->run($this->spec(), $this->settings(), $this->quiet());
    }

    #[Test]
    public function slowFileSyncIsWaitedOutRatherThanFailing(): void
    {
        $misses = 3;
        $runner = new FakeRunner([], null, static function (string $line) use (&$misses): ?CommandResult {
            if (str_contains($line, "'test' '-f' 'installation/joomla.php'") && $misses > 0) {
                $misses--;

                return new CommandResult(1);
            }

            return null;
        });

        $this->stage($runner, new FakeJoomlaSource())->run($this->spec(), $this->settings(), $this->quiet());

        $this->assertSame(3, $this->slept);
    }

    #[Test]
    public function aFileThatNeverArrivesTimesOutWithTheCommandToRun(): void
    {
        $runner = new FakeRunner([], null, static function (string $line): ?CommandResult {
            return str_contains($line, "'test' '-f'") ? new CommandResult(1) : null;
        });

        try {
            $this->stage($runner, new FakeJoomlaSource())->run($this->spec(), $this->settings(), $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('did not appear inside the container after 30 seconds', $e->getMessage());
            $this->assertStringContainsString('ddev mutagen sync', $e->getMessage());
        }

        $this->assertSame(30, $this->slept);
    }

    #[Test]
    public function thePlanListsTheStagesWithoutRunningAnything(): void
    {
        $runner = new FakeRunner();
        $plan   = $this->stage($runner, new FakeJoomlaSource())->plan($this->spec(), $this->settings(null));

        $this->assertSame([], $runner->calls);
        $this->assertStringContainsString('(latest stable)', $plan[0]);
        $this->assertStringContainsString('jabcd_', implode("\n", $plan));
        $this->assertStringNotContainsString('S3cretPassw0rd!', implode("\n", $plan));
    }
}
