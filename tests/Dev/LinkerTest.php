<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\Linker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LinkerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-build-tools-tests-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    #[Test]
    public function relative_path_for_sibling_file(): void
    {
        $linker = new Linker();

        self::assertSame('../target', $linker->relativePath('/a/b/target', '/a/b/from'));
    }

    #[Test]
    public function relative_path_for_deeper_target(): void
    {
        $linker = new Linker();

        self::assertSame('../target/inner', $linker->relativePath('/a/b/target/inner', '/a/b/from'));
    }

    #[Test]
    public function relative_path_for_shallower_target(): void
    {
        $linker = new Linker();

        self::assertSame('../../target', $linker->relativePath('/a/target', '/a/b/from'));
    }

    #[Test]
    public function relative_path_for_disjoint_trees(): void
    {
        $linker = new Linker();

        self::assertSame('../../../x/y/target', $linker->relativePath('/x/y/target', '/a/b/from'));
    }

    #[Test]
    public function relative_path_for_same_directory(): void
    {
        $linker = new Linker();

        self::assertSame('.', $linker->relativePath('/a/b', '/a/b'));
    }

    #[Test]
    public function check_reports_ok_for_correct_relative_symlink(): void
    {
        $sourceFile = $this->tmpDir . '/source.txt';
        file_put_contents($sourceFile, 'hello');

        $linkPath = $this->tmpDir . '/link.txt';
        symlink('source.txt', $linkPath);

        $linker = new Linker();
        $result = $linker->check($sourceFile, $linkPath);

        self::assertSame('ok', $result['status']);
    }

    #[Test]
    public function check_reports_missing_for_absent_link(): void
    {
        $sourceFile = $this->tmpDir . '/source.txt';
        file_put_contents($sourceFile, 'hello');

        $result = (new Linker())->check($sourceFile, $this->tmpDir . '/no-link');

        self::assertSame('missing', $result['status']);
    }

    #[Test]
    public function check_reports_stale_when_real_file_sits_at_link_path(): void
    {
        $sourceFile = $this->tmpDir . '/source.txt';
        file_put_contents($sourceFile, 'hello');

        $linkPath = $this->tmpDir . '/link.txt';
        file_put_contents($linkPath, 'i am not a symlink');

        $result = (new Linker())->check($sourceFile, $linkPath);

        self::assertSame('stale', $result['status']);
    }

    #[Test]
    public function check_reports_wrong_when_symlink_points_elsewhere(): void
    {
        $sourceFile = $this->tmpDir . '/source.txt';
        file_put_contents($sourceFile, 'hello');

        $other = $this->tmpDir . '/other.txt';
        file_put_contents($other, 'wrong');

        $linkPath = $this->tmpDir . '/link.txt';
        symlink('other.txt', $linkPath);

        $result = (new Linker())->check($sourceFile, $linkPath);

        self::assertSame('wrong', $result['status']);
    }

    #[Test]
    public function link_creates_relative_symlink_to_source(): void
    {
        $sourceDir = $this->tmpDir . '/project/src';
        mkdir($sourceDir, 0o777, true);
        file_put_contents($sourceDir . '/file.txt', 'hello');

        $linkPath = $this->tmpDir . '/joomla/components/com_example';

        ob_start();

        try {
            (new Linker())->link($sourceDir, $linkPath);
        } finally {
            ob_end_clean();
        }

        self::assertTrue(is_link($linkPath), 'link should be a symlink');

        // The link target should be relative, not absolute.
        $linkTarget = readlink($linkPath);
        self::assertNotFalse($linkTarget);
        self::assertStringStartsNotWith('/', $linkTarget, 'symlink target must be relative');

        // Resolving the link should land on the source contents.
        self::assertSame('hello', (string) file_get_contents($linkPath . '/file.txt'));
    }

    #[Test]
    public function link_replaces_existing_symlink_at_same_path(): void
    {
        $oldSource = $this->tmpDir . '/old';
        $newSource = $this->tmpDir . '/new';
        mkdir($oldSource, 0o777, true);
        mkdir($newSource, 0o777, true);
        file_put_contents($oldSource . '/marker', 'old');
        file_put_contents($newSource . '/marker', 'new');

        $linkPath = $this->tmpDir . '/link';

        ob_start();

        try {
            $linker = new Linker();
            $linker->link($oldSource, $linkPath);
            $linker->link($newSource, $linkPath);
        } finally {
            ob_end_clean();
        }

        self::assertSame('new', (string) file_get_contents($linkPath . '/marker'));
    }

    #[Test]
    public function unlink_removes_only_symlinks(): void
    {
        $sourceFile = $this->tmpDir . '/source.txt';
        file_put_contents($sourceFile, 'hello');

        $linkPath = $this->tmpDir . '/link.txt';
        symlink('source.txt', $linkPath);

        $linker = new Linker();
        self::assertTrue($linker->unlink($linkPath));
        self::assertFalse(is_link($linkPath));
        self::assertFileExists($sourceFile, 'unlinking the symlink should leave the source file intact');
    }

    #[Test]
    public function unlink_returns_false_for_real_files(): void
    {
        $realFile = $this->tmpDir . '/file.txt';
        file_put_contents($realFile, 'real');

        self::assertFalse((new Linker())->unlink($realFile));
        self::assertFileExists($realFile);
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path) && !is_link($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($path);
    }
}
