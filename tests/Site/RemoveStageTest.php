<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Dev\PropertiesReader;
use CWM\BuildTools\Site\CommandResult;
use CWM\BuildTools\Site\RegisteredSite;
use CWM\BuildTools\Site\RemoveStage;
use CWM\BuildTools\Site\SafeRemover;
use CWM\BuildTools\Site\SiteException;
use CWM\BuildTools\Site\SiteRegistrar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RemoveStageTest extends TestCase
{
    private string $tmp;

    private string $site;

    private string $repo;

    private string $props;

    protected function setUp(): void
    {
        $this->tmp   = (string) realpath(sys_get_temp_dir()) . '/cwm-remove-stage-' . bin2hex(random_bytes(6));
        $this->site  = $this->tmp . '/Sites/j6';
        $this->repo  = $this->tmp . '/GitHub/proj';
        $this->props = $this->repo . '/build.properties';

        mkdir($this->site . '/.ddev', 0o777, true);
        mkdir($this->site . '/administrator/components', 0o777, true);
        mkdir($this->repo . '/admin', 0o777, true);
        file_put_contents($this->site . '/.ddev/config.yaml', 'name: j6');
        file_put_contents($this->site . '/.ddev/docker-compose.cwm.yaml', 'services: {}');
        file_put_contents($this->site . '/index.php', '<?php');
        file_put_contents($this->repo . '/admin/precious.php', '<?php // source');

        file_put_contents($this->props, "# mine\nbuilder.installs=a1\nbuilder.a1.path=/x\n");
        (new SiteRegistrar())->register(
            $this->props,
            new RegisteredSite('j6', 'dev', $this->site, 'https://j6.ddev.site', '6.1.4', '127.0.0.1:33061', 'db', 'db', 'db', 'admin', 'S3cretPassw0rd', 'a@example.com')
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function install(): \CWM\BuildTools\Dev\InstallConfig
    {
        foreach ((new PropertiesReader($this->props))->installs() as $i) {
            if ($i->id === 'j6') {
                return $i;
            }
        }

        $this->fail('j6 not registered');
    }

    private function stage(FakeRunner $runner): RemoveStage
    {
        return new RemoveStage($runner, new SafeRemover($this->tmp), new SiteRegistrar());
    }

    private function quiet(): callable
    {
        return static function (): void {
        };
    }

    #[Test]
    public function theDdevProjectGoesFirstThenTheFolderThenTheRecord(): void
    {
        $seen = [];

        $runner = new FakeRunner([], function (array $command, ?string $cwd) use (&$seen): void {
            if (($command[1] ?? '') === 'delete') {
                $seen = [
                    'folderStillThere' => is_dir($this->site),
                    'recordStillThere' => str_contains((string) file_get_contents($this->props), 'builder.j6.'),
                    'cwd'              => $cwd,
                ];
            }
        });

        $this->stage($runner)->run($this->install(), $this->repo, $this->props, $this->quiet());

        $this->assertSame(['ddev', 'delete', '--omit-snapshot', '--yes'], $runner->calls[0]['command']);
        $this->assertSame($this->site, $seen['cwd'], 'run from inside the project, so there is no name to get wrong');
        $this->assertTrue($seen['folderStillThere'], 'the folder still existed when the project was deleted');
        $this->assertTrue($seen['recordStillThere'], 'and so did the record');
        $this->assertDirectoryDoesNotExist($this->site);
        $this->assertStringNotContainsString('j6', (string) file_get_contents($this->props));
        $this->assertStringContainsString('# mine', (string) file_get_contents($this->props), 'the rest of the file is kept');
    }

    #[Test]
    public function aFailedDdevDeleteLeavesTheFilesAndTheRecordForARetry(): void
    {
        $runner = new FakeRunner(['ddev delete' => new CommandResult(1, '', 'container is busy')]);

        try {
            $this->stage($runner)->run($this->install(), $this->repo, $this->props, $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('no files were removed', $e->getMessage());
            $this->assertStringContainsString('container is busy', $e->getMessage());
        }

        $this->assertFileExists($this->site . '/index.php');
        $this->assertStringContainsString('builder.j6.', (string) file_get_contents($this->props));
    }

    #[Test]
    public function aMissingDdevStopsBeforeAnythingIsTouched(): void
    {
        $runner = new FakeRunner(['ddev delete' => new CommandResult(127)]);

        try {
            $this->stage($runner)->run($this->install(), $this->repo, $this->props, $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('nothing else was touched', $e->getMessage());
        }

        $this->assertFileExists($this->site . '/index.php');
        $this->assertStringContainsString('builder.j6.', (string) file_get_contents($this->props));
    }

    #[Test]
    public function symlinksIntoTheSourceAreUnlinkedAndTheSourceIsUntouched(): void
    {
        symlink($this->repo . '/admin', $this->site . '/administrator/components/com_proj');
        symlink($this->repo, $this->site . '/whole-repo');

        $this->stage(new FakeRunner())->run($this->install(), $this->repo, $this->props, $this->quiet());

        $this->assertDirectoryDoesNotExist($this->site);
        $this->assertSame('<?php // source', file_get_contents($this->repo . '/admin/precious.php'));
        $this->assertFileExists($this->props);
    }

    #[Test]
    public function aFolderThisToolDidNotMakeIsRefusedBeforeDdevIsAskedToDeleteAnything(): void
    {
        unlink($this->site . '/.ddev/docker-compose.cwm.yaml');
        $runner = new FakeRunner();

        try {
            $this->stage($runner)->run($this->install(), $this->repo, $this->props, $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('cwm-site-create did not make it', $e->getMessage());
        }

        $this->assertSame([], $runner->calls, 'ddev was never asked to delete anything');
        $this->assertFileExists($this->site . '/index.php');
        $this->assertStringContainsString('builder.j6.', (string) file_get_contents($this->props));
    }

    #[Test]
    public function aFolderAlreadyGoneStillDropsTheRecordAndTriesTheProjectByName(): void
    {
        $this->rrmdir($this->site);
        $runner = new FakeRunner(['ddev delete' => new CommandResult(1, '', 'no such project')]);

        $this->stage($runner)->run($this->install(), $this->repo, $this->props, $this->quiet());

        $this->assertSame(['ddev', 'delete', '--omit-snapshot', '--yes', 'j6'], $runner->calls[0]['command']);
        $this->assertNull($runner->calls[0]['cwd']);
        $this->assertStringNotContainsString('builder.j6.', (string) file_get_contents($this->props), 'DDEV not knowing the project is not an error here');
    }

    #[Test]
    public function thePlanSaysWhatWillGoAndChangesNothing(): void
    {
        symlink($this->repo . '/admin', $this->site . '/administrator/components/com_proj');
        $runner = new FakeRunner();

        $plan = $this->stage($runner)->plan($this->install(), $this->repo);

        $this->assertTrue($plan['folderExists']);
        $this->assertSame(1, $plan['links']);
        $this->assertStringContainsString('unlinked, never followed', implode("\n", $plan['lines']));
        $this->assertSame([], $runner->calls);
        $this->assertFileExists($this->site . '/index.php');
    }

    #[Test]
    public function thePlanRefusesAFolderThatMayNotBeRemoved(): void
    {
        mkdir($this->site . '/.git');

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('.git');

        $this->stage(new FakeRunner())->plan($this->install(), $this->repo);
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
