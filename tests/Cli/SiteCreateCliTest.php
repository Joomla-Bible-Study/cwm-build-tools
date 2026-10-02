<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Cli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs `scripts/site-create.php` as a real subprocess, with stub `ddev` and
 * `docker` programs first on PATH.
 *
 * The entry point `require_once`s each class by path rather than through an
 * autoloader, so a class it reaches but does not require passes every unit test
 * and fails on first use (the same hole {@see PackageCliTest} documents). Only
 * running the script finds it.
 */
final class SiteCreateCliTest extends TestCase
{
    private string $tmp;

    private string $stubBin;

    private string $log;

    private string $globalConfig;

    protected function setUp(): void
    {
        $this->tmp          = (string) realpath(sys_get_temp_dir()) . '/cwm-site-cli-' . bin2hex(random_bytes(6));
        $this->stubBin      = $this->tmp . '/bin';
        $this->log          = $this->tmp . '/calls.log';
        $this->globalConfig = $this->tmp . '/global_config.yaml';

        mkdir($this->stubBin, 0o777, true);
        mkdir($this->tmp . '/GitHub/proj', 0o777, true);
        mkdir($this->tmp . '/Sites', 0o777, true);
        file_put_contents($this->globalConfig, "instrumentation_opt_in: false\n");

        $this->stub('ddev', <<<'SH'
#!/bin/sh
echo "ddev $*" >> "$STUB_LOG"
case "$1" in
  --version) echo "ddev version v0.0.0-stub" ;;
  config) mkdir -p .ddev ;;
esac
exit 0
SH);
        $this->stub('docker', <<<'SH'
#!/bin/sh
echo "docker $*" >> "$STUB_LOG"
echo "0.0.0-stub"
exit 0
SH);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function stub(string $name, string $body): void
    {
        file_put_contents($this->stubBin . '/' . $name, $body . "\n");
        chmod($this->stubBin . '/' . $name, 0o755);
    }

    /**
     * @param  list<string>  $args
     *
     * @return array{int, string, string}  exit code, stdout, stderr
     */
    private function runScript(array $args): array
    {
        $script = \dirname(__DIR__, 2) . '/scripts/site-create.php';
        $cmd    = array_merge([PHP_BINARY, $script], $args);

        $env = [
            'PATH'                   => $this->stubBin . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME'                   => $this->tmp,
            'STUB_LOG'               => $this->log,
            'CWM_DDEV_GLOBAL_CONFIG' => $this->globalConfig,
        ];

        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp . '/GitHub/proj', $env);
        $this->assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    #[Test]
    public function helpExitsCleanlyAndSaysWhoOwnsTheTelemetryChoice(): void
    {
        [$exit, $out] = $this->runScript(['--help']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('cwm-site-create', $out);
        $this->assertStringContainsString('will not answer it for you', $out);
    }

    #[Test]
    public function aMissingSiteIdIsAUsageError(): void
    {
        [$exit, , $err] = $this->runScript([]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Usage: cwm-site-create', $err);
    }

    #[Test]
    public function aDryRunPrintsTheNumberedPlanAndTouchesNothing(): void
    {
        $site = $this->tmp . '/Sites/j6';

        [$exit, $out] = $this->runScript(['j6', '--path', $site, '--db-port', '34567', '--dry-run']);

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('  1. check that ddev and Docker are available', $out);
        $this->assertStringContainsString('--host-db-port=34567', $out);
        $this->assertStringContainsString($this->tmp . '/GitHub/proj -> /var/GitHub/proj', $out);
        $this->assertDirectoryDoesNotExist($site);
        $this->assertFileDoesNotExist($this->log, 'a dry run must not call ddev or docker');
    }

    #[Test]
    public function provisioningRunsConfigThenStartAndWritesTheMounts(): void
    {
        $site = $this->tmp . '/Sites/j6';

        [$exit, $out, $err] = $this->runScript(['j6', '--path', $site, '--db-port', '34567']);

        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString('Site stack is running', $out);

        $calls = (string) file_get_contents($this->log);
        $this->assertLessThan(strpos($calls, 'ddev start'), strpos($calls, 'ddev config'));

        $override = $site . '/.ddev/docker-compose.cwm.yaml';
        $this->assertFileExists($override);
        $this->assertStringContainsString(':/var/GitHub/proj:cached', (string) file_get_contents($override));
    }

    #[Test]
    public function theSiteIdMayFollowAValueTakingFlag(): void
    {
        $site = $this->tmp . '/Sites/j6';

        [$exit, $out] = $this->runScript(['--path', $site, 'j6', '--db-port', '34567', '--dry-run']);

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('--project-name=j6', $out);
    }

    #[Test]
    public function anUnansweredTelemetryQuestionStopsWithTheCommandToRun(): void
    {
        file_put_contents($this->globalConfig, "router: traefik\n");
        $site = $this->tmp . '/Sites/j6';

        [$exit, , $err] = $this->runScript(['j6', '--path', $site, '--db-port', '34567']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('ddev config global --instrumentation-opt-in=false', $err);
        $this->assertDirectoryDoesNotExist($site);
        $this->assertStringNotContainsString('ddev start', (string) file_get_contents($this->log));
    }

    #[Test]
    public function aSitePathTypedThroughASymlinkStillSharesAnAncestorWithTheSource(): void
    {
        // macOS /var is /private/var: a path typed one way and a source resolved the other
        // share no ancestor unless both are canonicalised.
        symlink($this->tmp . '/Sites', $this->tmp . '/SitesLink');

        [$exit, $out, $err] = $this->runScript(['j6', '--path', $this->tmp . '/SitesLink/j6', '--db-port', '34567', '--dry-run']);

        unlink($this->tmp . '/SitesLink');

        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString($this->tmp . '/GitHub/proj -> /var/GitHub/proj', $out);
    }

    #[Test]
    public function aMalformedPhpVersionIsReportedNotThrown(): void
    {
        [$exit, , $err] = $this->runScript(['j6', '--path', $this->tmp . '/Sites/j6', '--db-port', '34567', '--php', 'eight', '--dry-run']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('must look like 8.3', $err);
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
