<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\CommandResult;
use CWM\BuildTools\Site\DdevConfig;
use CWM\BuildTools\Site\DdevEnvironment;
use CWM\BuildTools\Site\ExtensionInstallStage;
use CWM\BuildTools\Site\MountPlanner;
use CWM\BuildTools\Site\ResetStage;
use CWM\BuildTools\Site\SiteException;
use CWM\BuildTools\Site\SiteSpec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ResetStageTest extends TestCase
{
    private string $tmp;

    private string $site;

    protected function setUp(): void
    {
        $this->tmp  = (string) realpath(sys_get_temp_dir()) . '/cwm-reset-stage-' . bin2hex(random_bytes(6));
        $this->site = $this->tmp . '/Sites/j6';
        mkdir($this->site . '/tmp', 0o777, true);
        mkdir($this->site . '/cli', 0o777, true);
        mkdir($this->tmp . '/proj', 0o777, true);
        file_put_contents($this->site . '/configuration.php', '<?php');
        file_put_contents($this->tmp . '/helper.php', '<?php // helper');

        $zip = new \ZipArchive();
        $zip->open($this->tmp . '/pkg.zip', \ZipArchive::CREATE);
        $zip->addFromString('pkg_x.xml', '<extension/>');
        $zip->close();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function spec(): SiteSpec
    {
        return new SiteSpec('j6', $this->site, '8.3', 33061, []);
    }

    private function stage(FakeRunner $runner): ResetStage
    {
        $env = new DdevEnvironment($runner, new DdevConfig(), new MountPlanner(), $this->tmp . '/none.yaml', static function (): void {
        });

        return new ResetStage($runner, new ExtensionInstallStage($env, $this->tmp . '/helper.php'), '/tools/reset-testsite.php', '/usr/bin/php');
    }

    private function quiet(): callable
    {
        return static function (): void {
        };
    }

    private function installOk(): \Closure
    {
        return static function (string $line): ?CommandResult {
            return str_contains($line, 'cwm-install-extension.php') && !str_contains($line, "'test'")
                ? new CommandResult(0, 'CWM_RESULT:' . json_encode(['ok' => true, 'name' => 'PKG_X', 'version' => '1.0.0', 'messages' => []]))
                : null;
        };
    }

    #[Test]
    public function startsTheProjectResetsThenReinstallsInThatOrder(): void
    {
        $runner = new FakeRunner([], null, $this->installOk());

        $report = $this->stage($runner)->run($this->spec(), 'j6', $this->tmp . '/proj', $this->tmp . '/pkg.zip', $this->quiet());

        $lines = $runner->commandLines();

        $this->assertSame('ddev start --skip-confirmation', $lines[0]);
        $this->assertSame($this->site, $runner->calls[0]['cwd']);
        $this->assertSame('/usr/bin/php /tools/reset-testsite.php --install j6', $lines[1]);
        $this->assertSame($this->tmp . '/proj', $runner->calls[1]['cwd'], 'the reset reads the project\'s config');
        $this->assertNotNull($report);
        $this->assertTrue($report->ok);
        $this->assertStringContainsString('cwm-install-extension.php', implode("\n", $lines));
    }

    #[Test]
    public function withoutAPackageItOnlyResets(): void
    {
        $runner = new FakeRunner();

        $report = $this->stage($runner)->run($this->spec(), 'j6', $this->tmp . '/proj', null, $this->quiet());

        $this->assertNull($report);
        $this->assertCount(2, $runner->calls);
    }

    #[Test]
    public function aDryRunPreviewsTheResetAndInstallsNothing(): void
    {
        $runner = new FakeRunner();

        $report = $this->stage($runner)->run($this->spec(), 'j6', $this->tmp . '/proj', $this->tmp . '/pkg.zip', $this->quiet(), true);

        $this->assertNull($report);
        $this->assertSame('/usr/bin/php /tools/reset-testsite.php --install j6 --dry-run', $runner->commandLines()[1]);
        $this->assertStringNotContainsString('cwm-install-extension.php', implode("\n", $runner->commandLines()));
    }

    #[Test]
    public function aFailedResetDoesNotReinstallOverWhateverIsLeft(): void
    {
        $runner = new FakeRunner(['/usr/bin/php /tools/reset-testsite.php' => new CommandResult(1, '', 'no testSite.reset block')]);

        try {
            $this->stage($runner)->run($this->spec(), 'j6', $this->tmp . '/proj', $this->tmp . '/pkg.zip', $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('cwm-reset-testsite failed', $e->getMessage());
            $this->assertStringContainsString('no testSite.reset block', $e->getMessage());
        }

        $this->assertStringNotContainsString('cwm-install-extension.php', implode("\n", $runner->commandLines()));
    }

    #[Test]
    public function aProjectThatWillNotStartIsNotResetBlind(): void
    {
        $runner = new FakeRunner(['ddev start' => new CommandResult(1, '', 'port is already allocated')]);

        try {
            $this->stage($runner)->run($this->spec(), 'j6', $this->tmp . '/proj', null, $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('port is already allocated', $e->getMessage());
        }

        $this->assertCount(1, $runner->calls, 'the reset was not attempted');
    }

    #[Test]
    public function thePlanListsTheSteps(): void
    {
        $plan = $this->stage(new FakeRunner())->plan('j6', $this->tmp . '/pkg.zip');

        $this->assertCount(3, $plan);
        $this->assertStringContainsString('cwm-reset-testsite --install j6', $plan[1]);
        $this->assertStringContainsString('pkg.zip', $plan[2]);
        $this->assertCount(2, $this->stage(new FakeRunner())->plan('j6', null));
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
