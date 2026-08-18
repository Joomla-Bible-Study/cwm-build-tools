<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Build;

use CWM\BuildTools\Build\ZipEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Reproducibility of the artifacts this toolchain writes.
 *
 * The failure being pinned is silent: an entry's mtime changes, the archive's
 * bytes change, its size does not, and the only symptom is an update stream
 * advertising checksums for a file nobody receives (#132, #134).
 */
final class ZipEntryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/cwm-zipentry-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o777, true);
        file_put_contents($this->tmp . '/a.js', "identical content\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            unlink($f);
        }

        rmdir($this->tmp);
    }

    private function pack(string $out): void
    {
        $zip = new ZipArchive();
        $zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        ZipEntry::add($zip, $this->tmp . '/a.js', 'a.js');
        $zip->close();
    }

    #[Test]
    public function identical_sources_produce_identical_bytes_despite_mtimes(): void
    {
        $this->pack($this->tmp . '/one.zip');

        // Same content, rewritten at a different time — the state a rebuild
        // leaves behind, and the whole cause of #134.
        touch($this->tmp . '/a.js', time() - 9999);

        $this->pack($this->tmp . '/two.zip');

        self::assertSame(
            hash_file('sha256', $this->tmp . '/one.zip'),
            hash_file('sha256', $this->tmp . '/two.zip'),
            'a rebuild must not change the artifact when the sources did not'
        );
    }

    #[Test]
    public function raw_addFile_is_recorded_at_the_files_mtime(): void
    {
        /*
         * The behaviour being defended against, asserted directly so the test
         * above cannot pass for the wrong reason. Note the sizes match: this is
         * why the divergence was invisible for two releases.
         */
        $pack = function (string $out): void {
            $zip = new ZipArchive();
            $zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFile($this->tmp . '/a.js', 'a.js');
            $zip->close();
        };

        $pack($this->tmp . '/raw1.zip');
        touch($this->tmp . '/a.js', time() - 9999);
        $pack($this->tmp . '/raw2.zip');

        self::assertSame(
            filesize($this->tmp . '/raw1.zip'),
            filesize($this->tmp . '/raw2.zip'),
            'identical size is what made this hard to see'
        );
        self::assertNotSame(
            hash_file('sha256', $this->tmp . '/raw1.zip'),
            hash_file('sha256', $this->tmp . '/raw2.zip'),
            'raw addFile records the mtime, so the bytes differ'
        );
    }

    #[Test]
    public function the_entry_is_added_and_readable(): void
    {
        // Normalising the timestamp must not damage the entry itself.
        $this->pack($this->tmp . '/x.zip');

        $zip = new ZipArchive();
        $zip->open($this->tmp . '/x.zip');

        self::assertSame(1, $zip->numFiles);
        self::assertSame("identical content\n", $zip->getFromName('a.js'));

        $zip->close();
    }

    #[Test]
    public function no_writer_in_the_build_namespace_bypasses_the_helper(): void
    {
        /*
         * A setMtimeName() after each addFile() would rot: the next call site
         * added elsewhere reintroduces the problem silently. Adding an entry
         * and normalising it are one operation, and this is what keeps it that
         * way.
         */
        $offenders = [];

        foreach (glob(__DIR__ . '/../../src/Build/*.php') ?: [] as $file) {
            if (basename($file) === 'ZipEntry.php') {
                continue;
            }

            if (preg_match('/->addFile\s*\(/', (string) file_get_contents($file))) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'these call addFile directly; use ZipEntry::add()');
    }
}
