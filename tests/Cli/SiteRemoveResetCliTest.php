<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Cli;

use CWM\BuildTools\Site\RegisteredSite;
use CWM\BuildTools\Site\SiteRegistrar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs `scripts/site-remove.php` and `scripts/site-reset.php` as real
 * subprocesses with a stub `ddev` first on PATH.
 *
 * These scripts delete a developer's files, so what matters most is what must
 * NOT happen: no ddev call before a refusal, the source tree surviving a site
 * full of links into it, and the rest of build.properties staying as it was.
 */
final class SiteRemoveResetCliTest extends TestCase
{
    private string $tmp;

    private string $site;

    private string $repo;

    private string $props;

    private string $log;

    protected function setUp(): void
    {
        $this->tmp   = (string) realpath(sys_get_temp_dir()) . '/cwm-rm-cli-' . bin2hex(random_bytes(6));
        $this->site  = $this->tmp . '/Sites/j6';
        $this->repo  = $this->tmp . '/GitHub/proj';
        $this->props = $this->repo . '/build.properties';
        $this->log   = $this->tmp . '/calls.log';

        mkdir($this->tmp . '/bin', 0o777, true);
        mkdir($this->repo . '/admin', 0o777, true);
        mkdir($this->site . '/.ddev', 0o777, true);
        mkdir($this->site . '/administrator/components', 0o777, true);

        file_put_contents($this->repo . '/admin/precious.php', '<?php // the source');
        file_put_contents($this->site . '/.ddev/config.yaml', 'name: j6');
        file_put_contents($this->site . '/.ddev/docker-compose.cwm.yaml', 'services: {}');
        file_put_contents($this->site . '/index.php', '<?php');
        symlink($this->repo . '/admin', $this->site . '/administrator/components/com_proj');
        symlink($this->repo, $this->site . '/whole-repo');

        file_put_contents($this->tmp . '/bin/ddev', "#!/bin/sh\necho \"ddev \$* (cwd=\$PWD)\" >> \"\$STUB_LOG\"\nexit 0\n");
        chmod($this->tmp . '/bin/ddev', 0o755);

        file_put_contents($this->props, "# my notes\nbuilder.installs=a1\nbuilder.a1.path=/somewhere\njoomla.version=5.4.2\n");
        $this->register('j6', 'dev', $this->site);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function register(string $id, string $role, string $path): void
    {
        (new SiteRegistrar())->register(
            $this->props,
            new RegisteredSite($id, $role, $path, 'https://x', '6.1.4', '127.0.0.1:33061', 'db', 'db', 'db', 'admin', 'S3cretPassw0rd', 'a@example.com')
        );
    }

    /**
     * @param  list<string>  $args
     *
     * @return array{int, string, string}
     */
    private function runScript(string $script, array $args, ?string $cwd = null): array
    {
        $cmd = array_merge([PHP_BINARY, \dirname(__DIR__, 2) . '/scripts/' . $script], $args);
        $env = [
            'PATH'     => $this->tmp . '/bin:' . (getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME'     => $this->tmp . '/home',
            'STUB_LOG' => $this->log,
        ];

        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd ?? $this->repo, $env);
        $this->assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    #[Test]
    public function withoutYesItShowsThePlanRemovesNothingAndExitsNonZero(): void
    {
        [$exit, $out] = $this->runScript('site-remove.php', ['j6']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Would remove site "j6"', $out);
        $this->assertStringContainsString('unlinked, never followed', $out);
        $this->assertStringContainsString('Nothing was removed', $out);
        $this->assertFileExists($this->site . '/index.php');
        $this->assertFileDoesNotExist($this->log, 'ddev was not called');
    }

    #[Test]
    public function dryRunShowsThePlanAndExitsZero(): void
    {
        [$exit, $out] = $this->runScript('site-remove.php', ['j6', '--dry-run']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Would remove site "j6"', $out);
        $this->assertFileExists($this->site . '/index.php');
        $this->assertFileDoesNotExist($this->log);
    }

    #[Test]
    public function yesRemovesTheSiteAndLeavesTheSourceAndTheRestOfTheFileAlone(): void
    {
        [$exit, $out, $err] = $this->runScript('site-remove.php', ['j6', '--yes']);

        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString('Removed site "j6".', $out);
        $this->assertDirectoryDoesNotExist($this->site);

        $this->assertSame('<?php // the source', file_get_contents($this->repo . '/admin/precious.php'), 'links into the source were unlinked, not followed');

        $props = (string) file_get_contents($this->props);
        $this->assertStringContainsString('# my notes', $props);
        $this->assertStringContainsString('joomla.version=5.4.2', $props);
        $this->assertStringContainsString('builder.installs=a1', $props);
        $this->assertStringNotContainsString('j6', $props);

        $calls = (string) file_get_contents($this->log);
        $this->assertStringContainsString('ddev delete --omit-snapshot --yes (cwd=' . $this->site . ')', $calls);
    }

    #[Test]
    public function anUnknownIdListsWhatThisToolMadeAndTouchesNothing(): void
    {
        [$exit, , $err] = $this->runScript('site-remove.php', ['nope', '--yes']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Known: j6.', $err);
        $this->assertFileExists($this->site . '/index.php');
        $this->assertFileDoesNotExist($this->log);
    }

    #[Test]
    public function aSiteWrittenByHandIsNeverRemoved(): void
    {
        file_put_contents($this->props, "builder.mine.role=test\nbuilder.mine.path={$this->site}\n");

        [$exit, , $err] = $this->runScript('site-remove.php', ['mine', '--yes']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('did not record it', $err);
        $this->assertFileExists($this->site . '/index.php');
        $this->assertFileDoesNotExist($this->log);
    }

    #[Test]
    public function aFolderWithoutThisToolsFilesIsRefusedBeforeDdevIsCalled(): void
    {
        unlink($this->site . '/.ddev/docker-compose.cwm.yaml');

        [$exit, , $err] = $this->runScript('site-remove.php', ['j6', '--yes']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('cwm-site-create did not make it', $err);
        $this->assertFileExists($this->site . '/index.php');
        $this->assertFileDoesNotExist($this->log);
    }

    #[Test]
    public function runningItFromInsideTheSiteIsRefused(): void
    {
        mkdir($this->site . '/project-here', 0o777, true);
        copy($this->props, $this->site . '/project-here/build.properties');

        [$exit, , $err] = $this->runScript('site-remove.php', ['j6', '--yes'], $this->site . '/project-here');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('your project', $err);
        $this->assertFileExists($this->site . '/index.php');
    }

    #[Test]
    public function aFailedDdevDeleteLeavesEverythingForARetry(): void
    {
        file_put_contents($this->tmp . '/bin/ddev', "#!/bin/sh\necho \"ddev \$*\" >> \"\$STUB_LOG\"\necho 'container busy' >&2\nexit 1\n");

        [$exit, , $err] = $this->runScript('site-remove.php', ['j6', '--yes']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no files were removed', $err);
        $this->assertFileExists($this->site . '/index.php');
        $this->assertStringContainsString('builder.j6.', (string) file_get_contents($this->props));
    }

    #[Test]
    public function resetRefusesADevSiteAndSaysWhatToDoInstead(): void
    {
        [$exit, , $err] = $this->runScript('site-reset.php', ['j6']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('role=dev', $err);
        $this->assertStringContainsString('cwm-site-remove and cwm-site-create', $err);
        $this->assertFileDoesNotExist($this->log, 'ddev was not called');
    }

    #[Test]
    public function resetOfAnUnknownIdListsWhatThisToolMade(): void
    {
        [$exit, , $err] = $this->runScript('site-reset.php', ['nope']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Known: j6.', $err);
    }

    #[Test]
    public function resetWithAMissingPackageFailsBeforeAnythingRuns(): void
    {
        $this->register('t1', 'test', $this->tmp . '/Sites/t1');

        [$exit, , $err] = $this->runScript('site-reset.php', ['t1', '--package', '/no/such.zip']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--package path not found', $err);
        $this->assertFileDoesNotExist($this->log, 'ddev was not called');
    }

    #[Test]
    public function helpForBothExitsCleanly(): void
    {
        foreach (['site-remove.php' => 'cwm-site-remove', 'site-reset.php' => 'cwm-site-reset'] as $script => $name) {
            [$exit, $out] = $this->runScript($script, ['--help']);

            $this->assertSame(0, $exit);
            $this->assertStringContainsString($name, $out);
        }

        $this->assertFileDoesNotExist($this->log);
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
