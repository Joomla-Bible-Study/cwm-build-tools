<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Build;

use CWM\BuildTools\Build\PackageBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `outputName` tokens, which exist so a project can name artifacts the way
 * Joomla does: `<Product>_<version>-<Stability>-Full_Package.zip`.
 */
final class ArtifactNamingTest extends TestCase
{
    #[Test]
    #[DataProvider('stabilities')]
    public function stability_follows_joomlas_dev_status_vocabulary(string $version, string $expected): void
    {
        self::assertSame($expected, PackageBuilder::stabilityFor($version));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function stabilities(): array
    {
        return [
            ['10.5.11',            'Stable'],
            ['10.5.11-dev',        'Development'],
            ['10.5.11-dev20260819', 'Development'],
            ['10.5.11-alpha1',     'Alpha'],
            ['10.5.11-beta2',      'Beta'],
            ['10.5.11-rc1',        'Release_Candidate'],

            // Joomla ships 6.1.3-rc3-dev as Development, not as a release
            // candidate: an unreleased build of an RC is still unreleased.
            ['6.1.3-rc3-dev',      'Development'],

            // Joomla sets DEV_STATUS by hand and has no word for this. An
            // unreadable pre-release marker is many things, but the only
            // answer with a cost is the one that claims it is shippable.
            ['10.5.11-wat',        'Development'],

            // Two-part and four-part versions are still versions.
            ['1.2',                'Stable'],
            ['1.2.3.4',            'Stable'],
        ];
    }

    #[Test]
    public function the_full_joomla_shape_is_expressible(): void
    {
        self::assertSame(
            'Proclaim_10.5.11-Stable-Full_Package.zip',
            PackageBuilder::expandOutputName('Proclaim_{version}-{stability}-Full_Package.zip', '10.5.11'),
        );
    }

    #[Test]
    public function a_development_build_names_itself_one(): void
    {
        self::assertSame(
            'Proclaim_10.5.11-dev-Development-Full_Package.zip',
            PackageBuilder::expandOutputName('Proclaim_{version}-{stability}-Full_Package.zip', '10.5.11-dev'),
        );
    }

    #[Test]
    public function the_existing_single_token_shape_is_untouched(): void
    {
        self::assertSame(
            'com_proclaim-10.5.11.zip',
            PackageBuilder::expandOutputName('com_proclaim-{version}.zip', '10.5.11'),
        );
    }

    #[Test]
    public function the_patch_package_shape_needs_a_from_version(): void
    {
        self::assertSame(
            'Proclaim_10.5.9_to_10.5.11-Stable-Patch_Package.zip',
            PackageBuilder::expandOutputName(
                'Proclaim_{fromVersion}_to_{version}-{stability}-Patch_Package.zip',
                '10.5.11',
                '10.5.9',
            ),
        );
    }

    #[Test]
    public function a_from_version_token_with_no_value_is_an_error(): void
    {
        // Substituting nothing would produce a literal `{fromVersion}` in a
        // filename that then gets published, so this stops the build — which
        // runs long before anything is tagged or uploaded.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no from-version was given/');

        PackageBuilder::expandOutputName('x_{fromVersion}_to_{version}.zip', '10.5.11');
    }
}
