<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\CommandResult;
use CWM\BuildTools\Site\DdevConfig;
use CWM\BuildTools\Site\DdevEnvironment;
use CWM\BuildTools\Site\ExtensionInstallStage;
use CWM\BuildTools\Site\MountPlanner;
use CWM\BuildTools\Site\SiteException;
use CWM\BuildTools\Site\SiteSpec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ExtensionInstallStageTest extends TestCase
{
    private string $tmp;

    private string $site;

    protected function setUp(): void
    {
        $this->tmp  = (string) realpath(sys_get_temp_dir()) . '/cwm-ext-stage-' . bin2hex(random_bytes(6));
        $this->site = $this->tmp . '/Sites/j6';
        mkdir($this->site . '/tmp', 0o777, true);
        mkdir($this->site . '/cli', 0o777, true);
        file_put_contents($this->site . '/configuration.php', '<?php');
        file_put_contents($this->tmp . '/helper.php', '<?php // helper');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function spec(): SiteSpec
    {
        return new SiteSpec('j6', $this->site, '8.3', 33061, [$this->tmp . '/proj']);
    }

    /**
     * @param  array<string, string>  $entries  zip entry name => contents
     */
    private function zip(array $entries): string
    {
        $path = $this->tmp . '/pkg.zip';
        $zip  = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
        }

        $zip->close();

        return $path;
    }

    private function stage(FakeRunner $runner): ExtensionInstallStage
    {
        $env = new DdevEnvironment($runner, new DdevConfig(), new MountPlanner(), $this->tmp . '/none.yaml', static function (): void {
        });

        return new ExtensionInstallStage($env, $this->tmp . '/helper.php');
    }

    private function quiet(): callable
    {
        return static function (): void {
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function runnerAnswering(array $result, ?\Closure $onInstall = null): FakeRunner
    {
        return new FakeRunner([], null, static function (string $line) use ($result, $onInstall): ?CommandResult {
            if (!str_contains($line, 'cwm-install-extension.php') || str_contains($line, "'test'")) {
                return null;
            }

            if ($onInstall !== null) {
                $onInstall($line);
            }

            return new CommandResult(0, "Deprecated: noise\nCWM_RESULT:" . json_encode($result) . "\n");
        });
    }

    #[Test]
    public function extractsTheZipRunsTheHelperInTheContainerAndReports(): void
    {
        $saw = [];

        $runner = $this->runnerAnswering(
            ['ok' => true, 'name' => 'PKG_X', 'type' => 'package', 'version' => '1.2.3', 'messages' => ['message' => ['done']], 'error' => null],
            function (string $line) use (&$saw): void {
                $saw['helper']   = is_file($this->site . '/cli/cwm-install-extension.php');
                $saw['manifest'] = glob($this->site . '/tmp/cwm-install-*/pkg_x.xml') !== [];
                $saw['line']     = $line;
            }
        );

        $report = $this->stage($runner)->run($this->spec(), $this->zip(['pkg_x.xml' => '<extension/>', 'packages/a.zip' => 'z']), $this->quiet());

        $this->assertTrue($report->ok);
        $this->assertSame('PKG_X', $report->name);
        $this->assertTrue($saw['helper'], 'the helper was in place when the installer ran');
        $this->assertTrue($saw['manifest'], 'the zip was extracted when the installer ran');
        $this->assertStringContainsString("'/var/www/html/tmp/cwm-install-", $saw['line']);
    }

    #[Test]
    public function theHelperAndTheExtractedCopyAreRemovedAfterwards(): void
    {
        file_put_contents($this->site . '/tmp/keep-me.txt', 'mine');
        $runner = $this->runnerAnswering(['ok' => true, 'messages' => []]);

        $this->stage($runner)->run($this->spec(), $this->zip(['pkg_x.xml' => '<extension/>']), $this->quiet());

        $this->assertFileDoesNotExist($this->site . '/cli/cwm-install-extension.php');
        $this->assertSame([], glob($this->site . '/tmp/cwm-install-*') ?: []);
        $this->assertFileExists($this->site . '/tmp/keep-me.txt', 'only what the stage created is removed');
    }

    #[Test]
    public function theExtractedCopyIsRemovedInsideTheContainerAndTheSyncIsFlushed(): void
    {
        $runner = $this->runnerAnswering(['ok' => true, 'messages' => []]);

        $this->stage($runner)->run($this->spec(), $this->zip(['pkg_x.xml' => '<extension/>']), $this->quiet());

        $lines = $runner->commandLines();
        $rm    = null;

        foreach ($lines as $i => $line) {
            if (preg_match("#'rm' '-rf' '/var/www/html/tmp/cwm-install-[0-9a-f]{8}'#", $line)) {
                $rm = $i;
            }
        }

        $this->assertNotNull($rm, 'the work folder is removed from inside the container');
        $this->assertSame('ddev mutagen sync', $lines[$rm + 1], 'and the sync is flushed straight after');
    }

    #[Test]
    public function theContainerRemovalAlsoRunsWhenTheInstallFails(): void
    {
        $runner = $this->runnerAnswering(['ok' => false, 'error' => 'boom', 'messages' => []]);

        try {
            $this->stage($runner)->run($this->spec(), $this->zip(['pkg_x.xml' => '<extension/>']), $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException) {
            $this->assertNotEmpty(array_filter($runner->commandLines(), static fn (string $l): bool => str_contains($l, "'rm' '-rf'")));
        }
    }

    #[Test]
    public function aFailedInstallShowsTheInstallersOwnMessagesAndStillCleansUp(): void
    {
        $runner = $this->runnerAnswering([
            'ok' => false,
            'error' => null,
            'messages' => ['error' => ['Plugin install: Could not copy file'], 'warning' => ['ignored extension']],
        ]);

        try {
            $this->stage($runner)->run($this->spec(), $this->zip(['pkg_x.xml' => '<extension/>']), $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('did not install pkg.zip', $e->getMessage());
            $this->assertStringContainsString('error: Plugin install: Could not copy file', $e->getMessage());
            $this->assertStringContainsString('warning: ignored extension', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->site . '/cli/cwm-install-extension.php');
        $this->assertSame([], glob($this->site . '/tmp/cwm-install-*') ?: []);
    }

    #[Test]
    public function anExceptionInsideTheInstallerIsShown(): void
    {
        $runner = $this->runnerAnswering(['ok' => false, 'error' => 'ReflectionException: Class X does not exist (Foo.php:12)', 'messages' => []]);

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('ReflectionException: Class X does not exist');

        $this->stage($runner)->run($this->spec(), $this->zip(['pkg_x.xml' => '<extension/>']), $this->quiet());
    }

    #[Test]
    public function aSuccessfulInstallStillExposesTheExtensionsOwnWarnings(): void
    {
        $runner = $this->runnerAnswering([
            'ok' => true,
            'messages' => ['warning' => ['the "Retire legacy columns" step did not complete']],
        ]);

        $report = $this->stage($runner)->run($this->spec(), $this->zip(['pkg_x.xml' => '<extension/>']), $this->quiet());

        $this->assertTrue($report->ok);
        $this->assertSame(['the "Retire legacy columns" step did not complete'], $report->warnings());
    }

    #[Test]
    public function aHelperThatPrintsNoResultIsReportedAsAFatalError(): void
    {
        $runner = new FakeRunner([], null, static function (string $line): ?CommandResult {
            return str_contains($line, 'cwm-install-extension.php') && !str_contains($line, "'test'")
                ? new CommandResult(255, 'PHP Fatal error: out of memory', '')
                : null;
        });

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('out of memory');

        $this->stage($runner)->run($this->spec(), $this->zip(['pkg_x.xml' => '<extension/>']), $this->quiet());
    }

    #[Test]
    public function anEntryThatEscapesTheTargetIsRefusedBeforeAnythingIsWritten(): void
    {
        $runner = new FakeRunner();

        try {
            $this->stage($runner)->run($this->spec(), $this->zip(['pkg_x.xml' => '<extension/>', '../evil.txt' => 'x']), $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('unsafe entry name', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->site . '/evil.txt');
        $this->assertFileDoesNotExist($this->site . '/tmp/evil.txt');
        $this->assertSame([], $runner->calls, 'nothing ran in the container');
    }

    #[Test]
    public function anAbsoluteEntryIsRefused(): void
    {
        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('unsafe entry name');

        $this->stage(new FakeRunner())->run($this->spec(), $this->zip(['pkg_x.xml' => '<extension/>', '/etc/passwd' => 'x']), $this->quiet());
    }

    #[Test]
    public function aZipWithNoRootManifestIsRefused(): void
    {
        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('no manifest');

        $this->stage(new FakeRunner())->run($this->spec(), $this->zip(['sub/pkg_x.xml' => '<extension/>']), $this->quiet());
    }

    #[Test]
    public function aFileThatIsNotAZipIsRefused(): void
    {
        file_put_contents($this->tmp . '/not.zip', 'plain text');

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('as a zip file');

        $this->stage(new FakeRunner())->run($this->spec(), $this->tmp . '/not.zip', $this->quiet());
    }

    #[Test]
    public function aSiteWithoutJoomlaIsRefusedBeforeAnythingIsExtracted(): void
    {
        unlink($this->site . '/configuration.php');

        try {
            $this->stage(new FakeRunner())->run($this->spec(), $this->zip(['pkg_x.xml' => '<extension/>']), $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('Joomla is not installed', $e->getMessage());
        }

        $this->assertSame([], glob($this->site . '/tmp/cwm-install-*') ?: []);
    }

    #[Test]
    public function aSiteWithNoCliFolderIsNotTakenForJoomla(): void
    {
        rmdir($this->site . '/cli');

        try {
            $this->stage(new FakeRunner())->run($this->spec(), $this->zip(['pkg_x.xml' => '<extension/>']), $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('no cli/ folder', $e->getMessage());
        }

        $this->assertSame([], glob($this->site . '/tmp/cwm-install-*') ?: []);
    }

    #[Test]
    public function thePlanNamesTheZipAndRunsNothing(): void
    {
        $runner = new FakeRunner();
        $plan   = $this->stage($runner)->plan($this->spec(), '/x/pkg_proclaim-10.7.2.zip');

        $this->assertSame([], $runner->calls);
        $this->assertStringContainsString('pkg_proclaim-10.7.2.zip', $plan[0]);
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
