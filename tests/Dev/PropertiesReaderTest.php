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

    /**
     * Regression for issue #2.2: Proclaim's existing build.properties uses
     * `builder.joomla_dir` to point at a separate absolute CMS source clone.
     * cwm-build-tools treats the same key as a relative subpath under each
     * install root, so blindly concatenating produced paths like
     * `/Sites/j5-dev/Volumes/.../GitHub/joomla-cms` that fail with "Path
     * does not exist" downstream. We now ignore absolute values (and emit
     * a stderr warning that is intentionally not asserted on — it's a
     * UX nicety, the user-visible contract is the corrected install path).
     */
    #[Test]
    public function installs_legacy_flat_ignores_absolute_joomla_dir(): void
    {
        $path = $this->writeProperties(<<<INI
            builder.joomla_paths=/srv/j5
            builder.joomla_dir=/Volumes/BCCExt_APFS_Extreme_Pro/GitHub/joomla-cms
            builder.j5dev.url=https://j5.local
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertCount(1, $installs);
        self::assertSame('/srv/j5', $installs[0]->path, 'absolute joomla_dir must not be appended');
    }

    #[Test]
    public function installs_legacy_flat_accepts_relative_joomla_dir(): void
    {
        // Sanity-check: the absolute-value rejection must not also reject
        // legitimate relative subpaths like the existing 'joomla' fixture.
        $path = $this->writeProperties(<<<INI
            builder.joomla_paths=/srv
            builder.joomla_dir=joomla
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

    #[Test]
    public function installs_default_to_role_dev_when_role_key_is_absent(): void
    {
        $path = $this->writeProperties(<<<INI
            installs = j5

            [j5]
            path = /opt/joomla5
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertCount(1, $installs);
        self::assertSame(InstallConfig::ROLE_DEV, $installs[0]->role);
    }

    #[Test]
    public function installs_read_explicit_role_field(): void
    {
        $path = $this->writeProperties(<<<INI
            installs = j5, j5-test

            [j5]
            role = dev
            path = /opt/joomla5

            [j5-test]
            role = test
            path = /opt/joomla5-test
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertCount(2, $installs);
        self::assertSame(InstallConfig::ROLE_DEV,  $installs[0]->role);
        self::assertSame(InstallConfig::ROLE_TEST, $installs[1]->role);
    }

    #[Test]
    public function role_field_is_case_insensitive_and_trimmed(): void
    {
        $path = $this->writeProperties(<<<INI
            installs = j5

            [j5]
            role =   TEST
            path = /opt/joomla5
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertSame(InstallConfig::ROLE_TEST, $installs[0]->role);
    }

    #[Test]
    public function unknown_role_throws_with_clear_message(): void
    {
        $path = $this->writeProperties(<<<INI
            installs = j5

            [j5]
            role = staging
            path = /opt/joomla5
            INI);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown install role 'staging' in build.properties");

        (new PropertiesReader($path))->installs();
    }

    #[Test]
    public function installs_for_filters_by_role(): void
    {
        $path = $this->writeProperties(<<<INI
            installs = j5, j6, j5-test

            [j5]
            path = /opt/joomla5

            [j6]
            path = /opt/joomla6

            [j5-test]
            role = test
            path = /opt/joomla5-test
            INI);

        $reader = new PropertiesReader($path);

        $devs  = $reader->installsFor(InstallConfig::ROLE_DEV);
        $tests = $reader->installsFor(InstallConfig::ROLE_TEST);

        self::assertSame(
            ['j5', 'j6'],
            array_map(static fn (InstallConfig $i): string => $i->id, $devs),
        );
        self::assertSame(
            ['j5-test'],
            array_map(static fn (InstallConfig $i): string => $i->id, $tests),
        );
    }

    #[Test]
    public function installs_for_returns_empty_list_when_no_match(): void
    {
        $path = $this->writeProperties(<<<INI
            installs = j5

            [j5]
            path = /opt/joomla5
            INI);

        self::assertSame([], (new PropertiesReader($path))->installsFor(InstallConfig::ROLE_TEST));
    }

    #[Test]
    public function legacy_flat_proclaim_format_defaults_every_install_to_dev_role(): void
    {
        $path = $this->writeProperties(<<<INI
            builder.joomla_paths=/opt/j5,/opt/j6
            builder.j5dev.url=https://j5.local
            builder.j6dev.url=https://j6.local
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertCount(2, $installs);
        foreach ($installs as $install) {
            self::assertSame(InstallConfig::ROLE_DEV, $install->role);
        }
    }

    #[Test]
    public function write_round_trips_role_field(): void
    {
        $devInstall  = new InstallConfig(id: 'j5', path: '/opt/j5');
        $testInstall = new InstallConfig(id: 'j5-test', path: '/opt/j5-test', role: InstallConfig::ROLE_TEST);

        $path = $this->tmpDir . '/written.properties';
        (new PropertiesReader($path))->write([$devInstall, $testInstall]);

        $roundTripped = (new PropertiesReader($path))->installs();

        self::assertSame(InstallConfig::ROLE_DEV,  $roundTripped[0]->role);
        self::assertSame(InstallConfig::ROLE_TEST, $roundTripped[1]->role);
    }

    #[Test]
    public function parses_v1_4_flat_keys_with_explicit_builder_installs(): void
    {
        // v1.4 canonical shape: flat keys, no [sections], every key
        // globally unique so IDEs don't flag duplicates.
        $path = $this->writeProperties(<<<INI
            builder.installs = j5, j5-test

            builder.j5.role        = dev
            builder.j5.path        = /opt/j5
            builder.j5.url         = https://j5.local
            builder.j5.version     = 5.4.2
            builder.j5.db_host     = localhost
            builder.j5.db_user     = root
            builder.j5.admin_email = brent@example.com

            builder.j5-test.role = test
            builder.j5-test.path = /opt/j5-test
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertCount(2, $installs);
        self::assertSame('j5', $installs[0]->id);
        self::assertSame(InstallConfig::ROLE_DEV, $installs[0]->role);
        self::assertSame('/opt/j5', $installs[0]->path);
        self::assertSame('5.4.2', $installs[0]->version);
        self::assertSame('root', $installs[0]->dbUser());
        self::assertSame('brent@example.com', $installs[0]->adminEmail());

        self::assertSame('j5-test', $installs[1]->id);
        self::assertSame(InstallConfig::ROLE_TEST, $installs[1]->role);
    }

    #[Test]
    public function flat_format_auto_discovers_ids_from_path_keys(): void
    {
        $path = $this->writeProperties(<<<INI
            builder.j5.path = /opt/j5
            builder.j6.path = /opt/j6
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertCount(2, $installs);
        self::assertSame(['j5', 'j6'], array_map(static fn (InstallConfig $i): string => $i->id, $installs));
    }

    #[Test]
    public function paths_reads_flat_paths_dot_keys(): void
    {
        $path = $this->writeProperties(<<<INI
            builder.installs = j5
            builder.j5.path = /opt/j5

            paths.joomla-bible-study/lib-cwmscripture = /Users/brent/GitHub/lib_cwmscripture
            paths.cwm/scripture-links                 = /Users/brent/GitHub/CWMScriptureLinks
            INI);

        $paths = (new PropertiesReader($path))->paths();

        self::assertSame(
            [
                'joomla-bible-study/lib-cwmscripture' => '/Users/brent/GitHub/lib_cwmscripture',
                'cwm/scripture-links'                 => '/Users/brent/GitHub/CWMScriptureLinks',
            ],
            $paths,
        );
    }

    #[Test]
    public function write_then_read_roundtrips_flat_format(): void
    {
        $reader = new PropertiesReader($this->tmpDir . '/roundtrip.properties');

        $original = [
            new InstallConfig(id: 'j5', path: '/opt/j5', version: '5.4.2', role: InstallConfig::ROLE_DEV),
            new InstallConfig(id: 'j5-test', path: '/opt/j5-test', role: InstallConfig::ROLE_TEST),
        ];

        $reader->write($original);
        $reader->writePaths(['cwm/sib' => '/tmp/cwm-sib']);

        $written  = (string) file_get_contents($this->tmpDir . '/roundtrip.properties');
        $installs = $reader->installs();
        $paths    = $reader->paths();

        // Written file must use flat keys, NOT [sections]
        self::assertStringNotContainsString('[j5]', $written);
        self::assertStringContainsString('builder.j5.role        = dev', $written);
        self::assertStringContainsString('builder.j5-test.role        = test', $written);
        self::assertStringContainsString('paths.cwm/sib = /tmp/cwm-sib', $written);

        // Round-trip preserves data
        self::assertCount(2, $installs);
        self::assertSame('j5',      $installs[0]->id);
        self::assertSame('j5-test', $installs[1]->id);
        self::assertSame(InstallConfig::ROLE_TEST, $installs[1]->role);
        self::assertSame(['cwm/sib' => '/tmp/cwm-sib'], $paths);
    }

    #[Test]
    public function parses_files_with_hash_comments_for_ide_compat(): void
    {
        // build.dist.properties ships with # comments (not ;) so Java-
        // properties-aware IDEs (PhpStorm default) don't flag comment lines
        // as syntax errors. PHP's native parse_ini_string only treats ;
        // as a comment marker, but PropertiesReader strips both # and ;
        // lines before parsing.
        $path = $this->writeProperties(<<<INI
            # build.dist.properties — auto-managed
            # Multi-line header should be stripped cleanly.
            installs = j5

            [j5]
            # Comment inside a section is fine too.
            role = dev
            path = /opt/j5
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertCount(1, $installs);
        self::assertSame('j5', $installs[0]->id);
        self::assertSame(InstallConfig::ROLE_DEV, $installs[0]->role);
    }

    #[Test]
    public function paths_returns_empty_when_file_missing(): void
    {
        $reader = new PropertiesReader($this->tmpDir . '/nope.properties');

        self::assertSame([], $reader->paths());
    }

    #[Test]
    public function paths_returns_empty_when_no_paths_section(): void
    {
        $path = $this->writeProperties(<<<INI
            installs = j5

            [j5]
            path = /opt/joomla5
            INI);

        self::assertSame([], (new PropertiesReader($path))->paths());
    }

    #[Test]
    public function paths_reads_per_developer_sibling_paths(): void
    {
        $path = $this->writeProperties(<<<INI
            installs = j5

            [j5]
            path = /opt/joomla5

            [paths]
            joomla-bible-study/lib-cwmscripture = /Users/brent/GitHub/lib_cwmscripture
            cwm/scripture-links = /Users/brent/GitHub/CWMScriptureLinks
            INI);

        $paths = (new PropertiesReader($path))->paths();

        self::assertSame(
            [
                'joomla-bible-study/lib-cwmscripture' => '/Users/brent/GitHub/lib_cwmscripture',
                'cwm/scripture-links'                 => '/Users/brent/GitHub/CWMScriptureLinks',
            ],
            $paths,
        );
    }

    #[Test]
    public function paths_section_is_not_mistaken_for_install_when_installs_key_omitted(): void
    {
        $path = $this->writeProperties(<<<INI
            [j5]
            path = /opt/joomla5

            [paths]
            cwm/lib = /tmp/cwm-lib
            INI);

        $installs = (new PropertiesReader($path))->installs();

        self::assertCount(1, $installs);
        self::assertSame('j5', $installs[0]->id);
    }

    #[Test]
    public function write_paths_emits_flat_paths_keys(): void
    {
        // Switched in v1.4: flat `paths.<package>` keys instead of an
        // `[paths]` INI section, so Java-properties-aware IDEs handle the
        // file cleanly.
        $reader = new PropertiesReader($this->tmpDir . '/fresh.properties');

        $reader->writePaths([
            'joomla-bible-study/lib-cwmscripture' => '/Users/brent/GitHub/lib_cwmscripture',
        ]);

        $content = (string) file_get_contents($this->tmpDir . '/fresh.properties');

        self::assertStringNotContainsString('[paths]', $content);
        self::assertStringContainsString(
            'paths.joomla-bible-study/lib-cwmscripture = /Users/brent/GitHub/lib_cwmscripture',
            $content,
        );
    }

    #[Test]
    public function write_paths_preserves_existing_installs(): void
    {
        $reader   = new PropertiesReader($this->tmpDir . '/preserve.properties');
        $original = new InstallConfig(id: 'j5', path: '/opt/joomla5');

        $reader->write([$original]);
        $reader->writePaths(['cwm/x' => '/tmp/cwm-x']);

        // After writing paths, the install must still parse.
        $installs = $reader->installs();
        self::assertCount(1, $installs);
        self::assertSame('j5', $installs[0]->id);
        self::assertSame('/opt/joomla5', $installs[0]->path);

        // And the paths block is there too.
        self::assertSame(['cwm/x' => '/tmp/cwm-x'], $reader->paths());
    }

    #[Test]
    public function write_preserves_existing_paths_block(): void
    {
        $reader = new PropertiesReader($this->tmpDir . '/symmetric.properties');

        $reader->writePaths(['cwm/x' => '/tmp/cwm-x']);
        $reader->write([new InstallConfig(id: 'j6', path: '/opt/joomla6')]);

        self::assertSame(['cwm/x' => '/tmp/cwm-x'], $reader->paths());
        self::assertCount(1, $reader->installs());
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
