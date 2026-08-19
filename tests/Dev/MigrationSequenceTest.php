<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\MigrationSequence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Which migrations run, and in what order.
 *
 * This is the part of a replay that can be wrong while every statement still
 * executes cleanly — files applied in an order no site ever experienced pass
 * or fail for reasons the field will never reproduce. So it is tested apart
 * from the database, where it can be exercised exhaustively.
 */
final class MigrationSequenceTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/schema-replay/migrations';

    #[Test]
    public function it_orders_by_version_not_alphabetically(): void
    {
        // The whole reason not to sort as strings: "1.10.0" sorts BEFORE
        // "1.9.0" alphabetically and after it by version. Getting this wrong
        // replays migrations in an order no upgrade has ever taken.
        self::assertSame(
            ['1.2.0', '1.9.0', '1.10.0'],
            MigrationSequence::order(['1.10.0.sql', '1.2.0.sql', '1.9.0.sql']),
        );
    }

    #[Test]
    public function a_date_suffix_sorts_above_the_plain_version_not_below_it(): void
    {
        // Reads backwards until you know what version_compare does with a
        // hyphen: it treats "-20251231" as a further component, so
        // 1.2.0-20251231 is GREATER than 1.2.0, not a pre-release of it.
        //
        // That is load-bearing rather than trivia. Joomla skips files
        // `<= $version`, so a site sitting at exactly 10.5.8 still runs
        // 10.5.8-20260811 — which is the point of naming a migration that way.
        // Assuming the intuitive ordering would silently skip the migration
        // that ships with the version being installed.
        self::assertSame(
            ['1.2.0', '1.2.0-20251231', '1.2.0-20260101'],
            MigrationSequence::order(['1.2.0.sql', '1.2.0-20260101.sql', '1.2.0-20251231.sql']),
        );
    }

    #[Test]
    public function it_strips_the_sql_extension(): void
    {
        self::assertSame(['1.0.0'], MigrationSequence::order(['1.0.0.sql']));
    }

    #[Test]
    public function it_reads_every_migration_the_manifest_ships(): void
    {
        $sequence = MigrationSequence::fromManifest(self::FIXTURE . '/manifest.xml');

        self::assertSame(
            ['1.2.0', '1.2.0-20251231', '1.2.0-20260101', '1.9.0', '1.10.0', '2.0.0'],
            $sequence->versions(),
        );
    }

    #[Test]
    public function it_matches_a_schemapath_declared_as_mysqli(): void
    {
        // The fixture declares type="mysqli", which the installer maps to
        // "mysql" before comparing. Without that mapping this throws.
        $sequence = MigrationSequence::fromManifest(self::FIXTURE . '/manifest.xml');

        self::assertStringEndsWith('sql/updates/mysql', $sequence->schemaPathDir());
    }

    #[Test]
    public function it_matches_an_install_file_declared_as_pdomysql(): void
    {
        $sequence = MigrationSequence::fromManifest(self::FIXTURE . '/manifest.xml');

        self::assertStringEndsWith('sql/install.mysql.utf8.sql', (string) $sequence->installSql());
    }

    #[Test]
    public function it_maps_pgsql_onto_postgresql_the_way_the_installer_does(): void
    {
        // The fixture declares type="pgsql" as well. Asking for "postgresql"
        // must MATCH that entry — proving the alias works — and then fail on
        // the missing directory rather than on "declares no <schemapath>".
        //
        // The distinction is the assertion: a lookup that never matched would
        // throw the other message, and the test would pass for the wrong reason
        // if it only checked that something was thrown.
        //
        // No fixture directory is created for it. PostgreSQL is not supported
        // and shipping an empty one would imply otherwise.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Schema path does not exist');

        MigrationSequence::fromManifest(self::FIXTURE . '/manifest.xml', 'postgresql');
    }

    #[Test]
    public function it_skips_everything_at_or_below_the_starting_version(): void
    {
        $sequence = MigrationSequence::fromManifest(self::FIXTURE . '/manifest.xml');

        // The installer's rule is `version_compare($file, $version) <= 0` —
        // a file EQUAL to the current version has already run. The dated 1.2.0
        // files are NOT equal to 1.2.0; they are above it, so they still run.
        self::assertSame(
            ['1.2.0-20251231', '1.2.0-20260101', '1.9.0', '1.10.0', '2.0.0'],
            $sequence->after('1.2.0'),
        );
    }

    #[Test]
    public function a_site_at_the_plain_version_still_runs_that_versions_dated_migration(): void
    {
        $sequence = MigrationSequence::fromManifest(self::FIXTURE . '/manifest.xml');

        // The consequence of the ordering above, stated as the behaviour that
        // matters: this is how Proclaim's 10.5.8-20260811 reaches a site.
        self::assertContains('1.2.0-20260101', $sequence->after('1.2.0'));
    }

    #[Test]
    public function from_zero_runs_everything(): void
    {
        $sequence = MigrationSequence::fromManifest(self::FIXTURE . '/manifest.xml');

        self::assertSame($sequence->versions(), $sequence->after('0.0.0'));
    }

    #[Test]
    public function from_the_newest_version_runs_nothing(): void
    {
        $sequence = MigrationSequence::fromManifest(self::FIXTURE . '/manifest.xml');

        self::assertSame([], $sequence->after('2.0.0'));
    }

    #[Test]
    public function it_maps_a_version_back_to_its_file(): void
    {
        $sequence = MigrationSequence::fromManifest(self::FIXTURE . '/manifest.xml');

        self::assertFileExists($sequence->fileFor('1.9.0'));
    }

    #[Test]
    public function it_refuses_a_manifest_that_does_not_exist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Manifest not found');

        MigrationSequence::fromManifest(self::FIXTURE . '/nope.xml');
    }

    #[Test]
    public function it_refuses_a_driver_the_manifest_declares_no_path_for(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declares no <schemapath');

        MigrationSequence::fromManifest(self::FIXTURE . '/manifest.xml', 'sqlite');
    }
}
