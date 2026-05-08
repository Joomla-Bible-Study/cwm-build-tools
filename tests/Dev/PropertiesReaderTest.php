<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\InstallConfig;
use CWM\BuildTools\Dev\PropertiesReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PropertiesReaderTest extends TestCase
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
    public function exists_returns_false_when_file_is_missing(): void
    {
        $reader = new PropertiesReader($this->tmpDir . '/missing.properties');

        self::assertFalse($reader->exists());
    }

    #[Test]
    public function installs_throws_when_file_is_missing(): void
    {
        $reader = new PropertiesReader($this->tmpDir . '/missing.properties');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('build.properties not found');

        $reader->installs();
    }

    /**
     * Regression for the parser fix on this branch: a stock `build.properties`
     * shipped with parens or square brackets in *comment* lines used to crash
     * `parse_ini_*` because PHP treats `()[]?!{}` as reserved characters even
     * inside comments.
     */
    #[Test]
    public function installs_tolerates_reserved_chars_inside_comment_lines(): void
    {
        $path = $this->writeProperties(<<<INI
            ; build.properties — local Joomla installs (test fixture)
            # Full path(s) to your install — !mandatory, [example]
            ;   builder.joomla_paths=/path/to/j5,/path/to/j6
            installs = j5

            [j5]
            ; Per-install path is required
            path = /opt/joomla5
            url = https://j5.local
            version = 5.4.2
            db_host = localhost
            db_user = root
            db_pass = secret
            db_name = j5
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertCount(1, $installs);
        self::assertSame('j5', $installs[0]->id);
        self::assertSame('/opt/joomla5', $installs[0]->path);
        self::assertSame('5.4.2', $installs[0]->version);
        self::assertSame('root', $installs[0]->dbUser());
    }

    #[Test]
    public function installs_normalises_crlf_line_endings(): void
    {
        $contents = "; comment with parens (a)\r\ninstalls = j5\r\n\r\n[j5]\r\npath = /opt/joomla5\r\n";
        $path     = $this->tmpDir . '/build.properties';
        file_put_contents($path, $contents);

        $installs = (new PropertiesReader($path))->installs();

        self::assertCount(1, $installs);
        self::assertSame('/opt/joomla5', $installs[0]->path);
    }

    #[Test]
    public function installs_returns_only_ids_listed_in_installs_key(): void
    {
        $path = $this->writeProperties(<<<INI
            installs = j5

            [j5]
            path = /opt/joomla5

            [j6]
            path = /opt/joomla6
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertCount(1, $installs);
        self::assertSame('j5', $installs[0]->id);
    }

    #[Test]
    public function installs_falls_back_to_every_section_when_installs_key_is_missing(): void
    {
        $path = $this->writeProperties(<<<INI
            [j5]
            path = /opt/joomla5

            [j6]
            path = /opt/joomla6
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertCount(2, $installs);
        self::assertSame(['j5', 'j6'], array_map(static fn (InstallConfig $i): string => $i->id, $installs));
    }

    #[Test]
    public function installs_strips_trailing_slash_from_path(): void
    {
        $path = $this->writeProperties(<<<INI
            installs = j5

            [j5]
            path = /opt/joomla5/
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertSame('/opt/joomla5', $installs[0]->path);
    }

    #[Test]
    public function installs_handles_proclaim_legacy_flat_format(): void
    {
        $path = $this->writeProperties(<<<INI
            joomla.version=5.4.2
            builder.joomla_paths=/legacy/j5,/legacy/j6
            builder.j5dev.url=https://j5.local
            builder.j5dev.db_host=localhost
            builder.j5dev.db_user=admin
            builder.j5dev.db_pass=secret
            builder.j5dev.db_name=j5
            builder.j5dev.username=admin
            builder.j5dev.password=admin
            builder.j5dev.email=admin@example.com
            builder.j6dev.url=https://j6.local
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertCount(2, $installs);

        // Legacy `j5dev` id is normalised to modern `j5`.
        self::assertSame('j5', $installs[0]->id);
        self::assertSame('/legacy/j5', $installs[0]->path);
        self::assertSame('https://j5.local', $installs[0]->url);
        self::assertSame('5.4.2', $installs[0]->version);
        self::assertSame('admin', $installs[0]->dbUser());
        self::assertSame('admin@example.com', $installs[0]->adminEmail());

        self::assertSame('j6', $installs[1]->id);
        self::assertSame('/legacy/j6', $installs[1]->path);
    }

    #[Test]
    public function installs_legacy_flat_format_appends_joomla_dir(): void
    {
        $path = $this->writeProperties(<<<INI
            builder.joomla_paths=/srv
            builder.joomla_dir=joomla
            builder.j5dev.url=https://j5.local
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertSame('/srv/joomla', $installs[0]->path);
    }

    #[Test]
    public function write_then_read_round_trips_an_install(): void
    {
        $path   = $this->tmpDir . '/build.properties';
        $reader = new PropertiesReader($path);

        $reader->write([
            new InstallConfig(
                id: 'j5',
                path: '/opt/joomla5',
                url: 'https://j5.local',
                version: '5.4.2',
                db: ['host' => 'db.local', 'user' => 'root', 'pass' => 'secret', 'name' => 'j5'],
                admin: ['user' => 'admin', 'pass' => 'admin', 'email' => 'admin@example.com'],
            ),
        ]);

        $roundTripped = (new PropertiesReader($path))->installs();

        self::assertCount(1, $roundTripped);
        self::assertSame('j5', $roundTripped[0]->id);
        self::assertSame('/opt/joomla5', $roundTripped[0]->path);
        self::assertSame('https://j5.local', $roundTripped[0]->url);
        self::assertSame('db.local', $roundTripped[0]->dbHost());
        self::assertSame('admin@example.com', $roundTripped[0]->adminEmail());
    }

    private function writeProperties(string $contents): string
    {
        $path = $this->tmpDir . '/build.properties';
        file_put_contents($path, $contents);

        return $path;
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
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
