<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\CommandResult;
use CWM\BuildTools\Site\DdevConfig;
use CWM\BuildTools\Site\DdevEnvironment;
use CWM\BuildTools\Site\MountPlanner;
use CWM\BuildTools\Site\SiteException;
use CWM\BuildTools\Site\SiteSpec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DdevEnvironmentTest extends TestCase
{
    private string $tmp;

    private string $globalConfig;

    protected function setUp(): void
    {
        $this->tmp          = sys_get_temp_dir() . '/cwm-ddev-env-' . bin2hex(random_bytes(6));
        $this->globalConfig = $this->tmp . '/global_config.yaml';
        mkdir($this->tmp . '/GitHub/proj', 0o777, true);
        mkdir($this->tmp . '/Sites', 0o777, true);
        file_put_contents($this->globalConfig, "instrumentation_opt_in: false\n");
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function spec(bool $force = false): SiteSpec
    {
        return new SiteSpec('j6', $this->tmp . '/Sites/j6', '8.3', 33061, [$this->tmp . '/GitHub/proj'], $force);
    }

    /**
     * A runner that behaves like ddev where it matters here: `ddev config`
     * creates the project's .ddev folder.
     *
     * @param  array<string, CommandResult>  $responses
     */
    private function runner(array $responses = []): FakeRunner
    {
        return new FakeRunner($responses, static function (array $command, ?string $cwd): void {
            if (($command[1] ?? '') === 'config' && $cwd !== null && !is_dir($cwd . '/.ddev')) {
                mkdir($cwd . '/.ddev', 0o777, true);
            }
        });
    }

    private function environment(FakeRunner $runner): DdevEnvironment
    {
        return new DdevEnvironment($runner, new DdevConfig(), new MountPlanner(), $this->globalConfig);
    }

    #[Test]
    public function provisioningConfiguresWritesTheOverrideAndStarts(): void
    {
        $runner = $this->runner();
        $log    = [];

        $this->environment($runner)->provision($this->spec(), static function (string $l) use (&$log): void {
            $log[] = $l;
        });

        $lines = $runner->commandLines();

        $this->assertSame('ddev --version', $lines[0]);
        $this->assertStringStartsWith('docker info', $lines[1]);
        $this->assertStringStartsWith('ddev config --project-type=joomla --php-version=8.3', $lines[2]);
        $this->assertStringContainsString('--host-db-port=33061', $lines[2]);
        $this->assertSame('ddev start --skip-confirmation', $lines[3]);

        $this->assertCount(2, $log);
    }

    #[Test]
    public function theOverrideCarriesTheMountsThatMakeRelativeLinksResolve(): void
    {
        $this->environment($this->runner())->provision($this->spec(), static function (): void {
        });

        $override = $this->tmp . '/Sites/j6/.ddev/' . DdevConfig::COMPOSE_OVERRIDE;

        $this->assertFileExists($override);
        $this->assertStringContainsString(':/var/GitHub/proj:cached', (string) file_get_contents($override));
    }

    #[Test]
    public function aDdevThatCreatesNoProjectFolderIsReportedNotWarnedAbout(): void
    {
        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('did not create');

        $this->environment(new FakeRunner())->provision($this->spec(), static function (): void {
        });
    }

    #[Test]
    public function longRunningStartIsStreamedAndConfigIsNot(): void
    {
        $runner = $this->runner();

        $this->environment($runner)->provision($this->spec(), static function (): void {
        });

        $byLine = [];

        foreach ($runner->calls as $call) {
            $byLine[implode(' ', $call['command'])] = $call['stream'];
        }

        $this->assertTrue($byLine['ddev start --skip-confirmation']);
        $this->assertFalse($byLine['ddev --version']);
    }

    #[Test]
    public function aMissingDdevStopsBeforeAnythingIsCreated(): void
    {
        $runner = $this->runner(['ddev --version' => new CommandResult(127, '', 'not found')]);

        try {
            $this->environment($runner)->provision($this->spec(), static function (): void {
            });
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('brew install ddev/ddev/ddev', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->tmp . '/Sites/j6');
        $this->assertSame(['ddev --version'], $runner->commandLines());
    }

    #[Test]
    public function dockerNotRunningStopsBeforeAnythingIsCreated(): void
    {
        $runner = $this->runner(['docker info' => new CommandResult(1, '', 'Cannot connect')]);

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('Docker is not running');

        $this->environment($runner)->provision($this->spec(), static function (): void {
        });
    }

    #[Test]
    public function anUnansweredTelemetryQuestionIsLeftToTheUser(): void
    {
        file_put_contents($this->globalConfig, "router: traefik\n");
        $runner = $this->runner();

        try {
            $this->environment($runner)->provision($this->spec(), static function (): void {
            });
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('ddev config global --instrumentation-opt-in=false', $e->getMessage());
            $this->assertStringContainsString('will not make it for you', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->tmp . '/Sites/j6');
        $this->assertNotContains('ddev start --skip-confirmation', $runner->commandLines());
    }

    #[Test]
    public function aMissingGlobalConfigCountsAsUnanswered(): void
    {
        unlink($this->globalConfig);

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('usage statistics');

        $this->environment($this->runner())->provision($this->spec(), static function (): void {
        });
    }

    #[Test]
    public function aNonEmptyFolderIsRefusedWithoutForce(): void
    {
        mkdir($this->tmp . '/Sites/j6', 0o777, true);
        file_put_contents($this->tmp . '/Sites/j6/index.php', '<?php');
        $runner = $this->runner();

        try {
            $this->environment($runner)->provision($this->spec(), static function (): void {
            });
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('is not empty', $e->getMessage());
        }

        $this->assertNotContains('ddev start --skip-confirmation', $runner->commandLines());
    }

    #[Test]
    public function anExistingDdevProjectIsNamedAsSuch(): void
    {
        mkdir($this->tmp . '/Sites/j6/.ddev', 0o777, true);

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('is already a DDEV project');

        $this->environment($this->runner())->provision($this->spec(), static function (): void {
        });
    }

    #[Test]
    public function forceReconfiguresAnExistingProject(): void
    {
        mkdir($this->tmp . '/Sites/j6/.ddev', 0o777, true);
        $runner = $this->runner();

        $this->environment($runner)->provision($this->spec(true), static function (): void {
        });

        $this->assertContains('ddev start --skip-confirmation', $runner->commandLines());
    }

    #[Test]
    public function aFailedStartCarriesDdevsOwnMessage(): void
    {
        $runner = $this->runner(['ddev start' => new CommandResult(2, '', 'port 80 is already allocated')]);

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('port 80 is already allocated');

        $this->environment($runner)->provision($this->spec(), static function (): void {
        });
    }

    #[Test]
    public function aDryRunPlanChangesNothingAndRunsNothing(): void
    {
        $runner = $this->runner();
        $steps  = $this->environment($runner)->plan($this->spec());

        $this->assertSame([], $runner->calls);
        $this->assertDirectoryDoesNotExist($this->tmp . '/Sites/j6');
        $this->assertContains('ddev start', $steps);
        $this->assertNotEmpty(array_filter($steps, static fn (string $s): bool => str_contains($s, '/var/GitHub/proj')));
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
