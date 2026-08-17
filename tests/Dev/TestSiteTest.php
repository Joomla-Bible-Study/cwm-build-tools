<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\InstallConfig;
use CWM\BuildTools\Dev\TestSite;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The half of TestSite that does not need a database.
 *
 * Parsing `configuration.php` and knowing the prefix is what twelve files
 * across two repositories were each doing their own way; connecting is the part
 * that needs a live server. Splitting them is what makes any of this coverable —
 * the original could only be reached by standing a Joomla install up first,
 * which is why it never was.
 *
 * Fixtures are real `configuration.php` files under
 * tests/fixtures/joomla/, committed so they are reviewable in a PR.
 */
final class TestSiteTest extends TestCase
{
    private function fixture(string $name): string
    {
        return __DIR__ . '/../fixtures/joomla/' . $name;
    }

    #[Test]
    public function reads_the_connection_values_a_site_actually_uses(): void
    {
        $config = TestSite::readConfig($this->fixture('standard'));

        self::assertNotNull($config);
        self::assertSame('cwm_j5', $config['db']);
        self::assertSame('localhost', $config['host']);
        self::assertSame('cwmdev', $config['user']);
        self::assertSame('sekrit', $config['password']);
        self::assertSame('j5x_', $config['dbprefix']);
    }

    #[Test]
    public function unescapes_single_quoted_values(): void
    {
        // A password or user containing a quote or a backslash is the case that
        // makes parsing-as-text rather than including the file matter.
        $config = TestSite::readConfig($this->fixture('escapes'));

        self::assertNotNull($config);
        self::assertSame("o'brien", $config['user']);
        self::assertSame('back\\slash', $config['password']);
    }

    #[Test]
    public function an_install_with_no_database_reads_as_null(): void
    {
        // A freshly unpacked Joomla names no database. Saying so beats
        // connecting to a default and reporting an empty schema.
        self::assertNull(TestSite::readConfig($this->fixture('unconfigured')));
    }

    #[Test]
    public function a_missing_configuration_file_reads_as_null(): void
    {
        self::assertNull(TestSite::readConfig($this->fixture('does-not-exist')));
    }

    #[Test]
    public function from_path_rejects_a_path_that_is_not_there(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        TestSite::fromPath($this->fixture('does-not-exist'));
    }

    #[Test]
    public function from_path_rejects_an_unconfigured_install(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/configuration\.php/');

        TestSite::fromPath($this->fixture('unconfigured'));
    }

    #[Test]
    public function exposes_the_prefix_and_database(): void
    {
        $site = TestSite::fromPath($this->fixture('standard'));

        self::assertSame('j5x_', $site->prefix());
        self::assertSame('cwm_j5', $site->database());
    }

    #[Test]
    public function prefixes_schema_tokens(): void
    {
        // Every hand-rolled copy built these by concatenation, which is where a
        // missing prefix or a doubled one comes from.
        $site = TestSite::fromPath($this->fixture('standard'));

        self::assertSame('j5x_extensions', $site->table('#__extensions'));
        self::assertSame('j5x_menu', $site->table('#__menu'));
    }

    #[Test]
    public function defaults_the_prefix_the_way_joomla_does(): void
    {
        $dir = sys_get_temp_dir() . '/cwm-testsite-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/configuration.php', "<?php\nclass JConfig { public \$db = 'x'; }\n");

        try {
            self::assertSame('jos_', TestSite::fromPath($dir)->prefix());
        } finally {
            unlink($dir . '/configuration.php');
            rmdir($dir);
        }
    }

    #[Test]
    public function an_install_config_contributes_its_path_only(): void
    {
        /*
         * InstallConfig carries a db block from build.properties, and the
         * obvious reading is that TestSite should use it. It must not:
         * configuration.php is what the running site connects with, and when
         * the two disagree the site is right. A harness resetting tables
         * through build.properties' credentials either fails confusingly or
         * succeeds against the wrong database.
         */
        $install = new InstallConfig(
            id: 'j5',
            path: $this->fixture('standard'),
            db: ['host' => 'wrong-host', 'user' => 'wrong-user', 'pass' => 'wrong', 'name' => 'wrong_db'],
        );

        $site = TestSite::fromInstall($install);

        self::assertSame('cwm_j5', $site->database(), 'the site configuration.php wins over build.properties');
        self::assertSame('j5x_', $site->prefix());
    }
}
