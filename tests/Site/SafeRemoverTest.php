<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\SafeRemover;
use CWM\BuildTools\Site\SiteException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SafeRemoverTest extends TestCase
{
    private string $tmp;

    private string $site;

    private string $repo;

    protected function setUp(): void
    {
        $this->tmp  = (string) realpath(sys_get_temp_dir()) . '/cwm-remover-' . bin2hex(random_bytes(6));
        $this->site = $this->tmp . '/home/me/Sites/j6';
        $this->repo = $this->tmp . '/home/me/GitHub/proj';

        mkdir($this->site . '/.ddev', 0o777, true);
        mkdir($this->site . '/administrator/components', 0o777, true);
        mkdir($this->repo . '/admin', 0o777, true);

        file_put_contents($this->site . '/.ddev/config.yaml', 'name: j6');
        file_put_contents($this->site . '/.ddev/docker-compose.cwm.yaml', 'services: {}');
        file_put_contents($this->site . '/index.php', '<?php');
        file_put_contents($this->site . '/.htaccess', 'x');
        file_put_contents($this->repo . '/admin/precious.php', '<?php // the source');
        file_put_contents($this->repo . '/README.md', 'keep me');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function remover(): SafeRemover
    {
        return new SafeRemover($this->tmp . '/home/me');
    }

    #[Test]
    public function removesASiteTreeIncludingDotfiles(): void
    {
        mkdir($this->site . '/media/deep/er', 0o777, true);
        file_put_contents($this->site . '/media/deep/er/f.txt', 'x');

        $this->remover()->assertRemovable($this->site, $this->repo);
        $stats = $this->remover()->remove($this->site);

        $this->assertDirectoryDoesNotExist($this->site);
        $this->assertSame(5, $stats['files'], 'config, override, index.php, .htaccess and the nested file');
    }

    #[Test]
    public function aSymlinkIntoTheSourceIsUnlinkedAndTheSourceSurvivesIntact(): void
    {
        symlink($this->repo . '/admin', $this->site . '/administrator/components/com_proj');
        symlink($this->repo . '/README.md', $this->site . '/README-link.md');
        symlink('../../../../GitHub/proj/admin', $this->site . '/relative-link');

        $stats = $this->remover()->remove($this->site);

        $this->assertDirectoryDoesNotExist($this->site);
        $this->assertFileExists($this->repo . '/admin/precious.php', 'the linked directory\'s contents survive');
        $this->assertSame('<?php // the source', file_get_contents($this->repo . '/admin/precious.php'));
        $this->assertSame('keep me', file_get_contents($this->repo . '/README.md'), 'a linked file survives');
        $this->assertSame(3, $stats['links']);
    }

    #[Test]
    public function aLinkToADirectoryIsNeverEntered(): void
    {
        // The case that would delete a repository: the walk must not descend through the link.
        symlink($this->repo, $this->site . '/whole-repo');

        $this->remover()->remove($this->site);

        $this->assertFileExists($this->repo . '/admin/precious.php');
        $this->assertFileExists($this->repo . '/README.md');
    }

    #[Test]
    public function aBrokenLinkIsRemovedWithoutError(): void
    {
        symlink($this->tmp . '/does-not-exist', $this->site . '/dangling');

        $this->remover()->remove($this->site);

        $this->assertDirectoryDoesNotExist($this->site);
    }

    #[Test]
    public function aSurveyCountsWithoutDeletingAndNamesWhereLinksPoint(): void
    {
        symlink($this->repo . '/admin', $this->site . '/administrator/components/com_proj');

        $stats = $this->remover()->survey($this->site);

        $this->assertDirectoryExists($this->site);
        $this->assertFileExists($this->site . '/index.php');
        $this->assertSame(1, $stats['links']);
        $this->assertSame([$this->repo . '/admin'], $stats['linkTargets']);
        $this->assertGreaterThan(0, $stats['files']);
    }

    #[Test]
    public function aFolderThisToolDidNotMakeIsRefused(): void
    {
        unlink($this->site . '/.ddev/docker-compose.cwm.yaml');

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('cwm-site-create did not make it');

        $this->remover()->assertRemovable($this->site, $this->repo);
    }

    #[Test]
    public function aRepositoryIsRefusedEvenWithTheFingerprint(): void
    {
        mkdir($this->site . '/.git');

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('it contains a .git entry');

        $this->remover()->assertRemovable($this->site, $this->repo);
    }

    #[Test]
    public function aFolderContainingTheProjectIsRefused(): void
    {
        // The project lives inside the folder to be removed: deleting it would delete the source.
        mkdir($this->site . '/src-here', 0o777, true);

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('your project');

        $this->remover()->assertRemovable($this->site, $this->site . '/src-here');
    }

    #[Test]
    public function theProjectItselfIsRefused(): void
    {
        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('your project');

        $this->remover()->assertRemovable($this->site, $this->site);
    }

    #[Test]
    public function theHomeDirectoryIsRefused(): void
    {
        $home = $this->tmp . '/home/me';
        mkdir($home . '/.ddev', 0o777, true);
        file_put_contents($home . '/.ddev/config.yaml', 'x');
        file_put_contents($home . '/.ddev/docker-compose.cwm.yaml', 'x');

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('your home directory');

        $this->remover()->assertRemovable($home, $this->tmp . '/elsewhere');
    }

    #[Test]
    public function aPathNearTheFilesystemRootIsRefused(): void
    {
        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('too close to the filesystem root');

        $this->remover()->assertRemovable('/tmp', $this->repo);
    }

    #[Test]
    public function aPathThatIsNotADirectoryIsRefused(): void
    {
        $this->expectException(SiteException::class);

        $this->remover()->assertRemovable($this->site . '/index.php', $this->repo);
    }

    #[Test]
    public function aSiteReachedThroughASymlinkedPathIsCheckedAtItsRealLocation(): void
    {
        symlink($this->site, $this->tmp . '/shortcut');

        // The shortcut resolves to the real site, so the rules apply to the real folder.
        $this->remover()->assertRemovable($this->tmp . '/shortcut', $this->repo);

        // And a shortcut that resolves to the project is refused as the project.
        symlink($this->repo, $this->tmp . '/to-repo');
        mkdir($this->repo . '/.ddev', 0o777, true);
        file_put_contents($this->repo . '/.ddev/config.yaml', 'x');
        file_put_contents($this->repo . '/.ddev/docker-compose.cwm.yaml', 'x');

        $this->expectException(SiteException::class);

        $this->remover()->assertRemovable($this->tmp . '/to-repo', $this->repo);
    }

    private function rrmdir(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->rrmdir($path . '/' . $entry);
        }

        @rmdir($path);
    }
}
