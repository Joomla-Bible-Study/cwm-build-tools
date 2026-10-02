<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\InstallConfig;
use CWM\BuildTools\Dev\TestSite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Reaching a site's database from the machine the tools run on.
 *
 * `configuration.php` names the database host as the site itself sees it, and
 * for a site in a container that is a container-internal name such as `db`,
 * which does not resolve here. Only the address changes: user, password,
 * database name and prefix still come from `configuration.php`, because that is
 * what the running site connects with (see the TestSite class docblock).
 */
final class TestSiteHostTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/cwm-testsite-host-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp . '/configuration.php');
        @rmdir($this->tmp);
    }

    private function site(string $configuredHost): string
    {
        file_put_contents($this->tmp . '/configuration.php', <<<PHP
            <?php
            class JConfig {
                public \$dbtype = 'mysqli';
                public \$host = '{$configuredHost}';
                public \$user = 'db';
                public \$password = 'from-configuration';
                public \$db = 'sitedb';
                public \$dbprefix = 'site_';
            }
            PHP);

        return $this->tmp;
    }

    /**
     * @param  array<string, string>  $db
     */
    private function install(array $db): InstallConfig
    {
        return new InstallConfig(id: 'j6', path: $this->tmp, db: $db);
    }

    #[Test]
    public function aContainerInternalHostIsReplacedByTheAddressRegisteredForThisMachine(): void
    {
        $this->site('db');

        $site = TestSite::fromInstall($this->install(['host' => '127.0.0.1:33061']), static fn (string $h): bool => false);

        self::assertSame('127.0.0.1:33061', $site->host());
    }

    #[Test]
    public function onlyTheAddressChangesTheCredentialsDatabaseAndPrefixStayFromConfiguration(): void
    {
        $this->site('db');

        $site = TestSite::fromInstall(
            $this->install(['host' => '127.0.0.1:33061', 'user' => 'wrong-user', 'pass' => 'wrong', 'name' => 'wrong_db']),
            static fn (string $h): bool => false
        );

        self::assertSame('sitedb', $site->database());
        self::assertSame('site_', $site->prefix());
        self::assertSame('127.0.0.1:33061', $site->host());
    }

    #[Test]
    public function aHostThatResolvesIsNeverReplacedSoExistingSetupsAreUntouched(): void
    {
        $this->site('mysql.internal');

        $site = TestSite::fromInstall($this->install(['host' => 'localhost:8889']), static fn (string $h): bool => true);

        self::assertSame('mysql.internal', $site->host(), 'build.properties does not override a host that works');
    }

    #[Test]
    public function anUnresolvableHostIsLeftAloneWhenBuildPropertiesNamesNoAddress(): void
    {
        $this->site('db');

        // No 'host' key: PropertiesReader leaves it out when db_host is not set.
        $site = TestSite::fromInstall($this->install(['user' => 'x']), static fn (string $h): bool => false);

        self::assertSame('db', $site->host());
    }

    #[Test]
    public function thePortIsStrippedBeforeTheHostIsLookedUp(): void
    {
        $this->site('db:3306');
        $asked = [];

        TestSite::fromInstall($this->install(['host' => '127.0.0.1:33061']), static function (string $h) use (&$asked): bool {
            $asked[] = $h;

            return false;
        });

        self::assertSame(['db'], $asked);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostsThatNeedNoLookup(): array
    {
        return [
            'localhost'            => ['localhost'],
            'localhost with port'  => ['localhost:8889'],
            'ipv4'                 => ['127.0.0.1'],
            'ipv4 with port'       => ['192.168.1.5:3307'],
            'ipv6'                 => ['[::1]:3306'],
            'unix socket'          => ['/var/run/mysqld/mysqld.sock'],
            'localhost and socket' => ['localhost:/tmp/mysql.sock'],
        ];
    }

    #[Test]
    #[DataProvider('hostsThatNeedNoLookup')]
    public function literalsLocalhostAndSocketsAreNeverLookedUpOrReplaced(string $configured): void
    {
        $this->site($configured);

        $site = TestSite::fromInstall(
            $this->install(['host' => '127.0.0.1:33061']),
            static function (string $h): bool {
                throw new \LogicException('looked up ' . $h);
            }
        );

        self::assertSame($configured, $site->host());
    }

    #[Test]
    public function aConnectionFailureOnAnUnresolvableHostExplainsWhatToDo(): void
    {
        // .invalid is reserved and never resolves.
        $this->site('cwm-no-such-host.invalid');

        $site = TestSite::fromInstall($this->install([]));

        try {
            $site->db();
            self::fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('cwm-no-such-host.invalid', $e->getMessage());
            self::assertStringContainsString('does not resolve from this machine', $e->getMessage());
            self::assertStringContainsString('db_host', $e->getMessage());
        }
    }

    #[Test]
    public function aConnectionFailureOnAHostThatResolvesDoesNotBlameDns(): void
    {
        $this->site('127.0.0.1:1');

        try {
            TestSite::fromInstall($this->install([]))->db();
            self::fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('does not resolve', $e->getMessage());
        }
    }
}
