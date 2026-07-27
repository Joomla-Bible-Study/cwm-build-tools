<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Build;

use CWM\BuildTools\Build\ManifestVersionWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What this class writes reaches every shipped manifest. A mistake surfaces as
 * a site refusing to update, or updating to the wrong version — both a long way
 * from the code that caused them. It lived in scripts/bump.php with no coverage
 * at all until issue #32.
 */
class ManifestVersionWriterTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/cwm-manifest-writer-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }

        @rmdir($this->tmp);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function versionProvider(): array
    {
        return [
            'release'              => ['1.2.3', true],
            'zero'                 => ['0.0.0', true],
            'large'                => ['10.3.6', true],
            'beta'                 => ['1.2.3-beta1', true],
            'rc with dot'          => ['1.2.3-rc.2', true],
            'alpha'                => ['2.0.0-alpha', true],
            'two segments'         => ['1.2', false],
            'four segments'        => ['1.2.3.4', false],
            'leading v'            => ['v1.2.3', false],
            'empty'                => ['', false],
            'words'                => ['next', false],
            'trailing hyphen'      => ['1.2.3-', false],
            'space'                => ['1.2.3 ', false],
            'dev suffix is a version, refused elsewhere' => ['1.2.3-dev', true],
        ];
    }

    #[DataProvider('versionProvider')]
    #[Test]
    public function version_validation_matches_semver(string $version, bool $expected): void
    {
        self::assertSame($expected, ManifestVersionWriter::isValidVersion($version));
    }

    #[Test]
    public function version_and_date_are_rewritten(): void
    {
        $xml = '<extension><version>1.0.0</version><creationDate>2020-01-01</creationDate></extension>';

        $out = ManifestVersionWriter::rewrite($xml, '2.0.0', '2026-07-27');

        self::assertStringContainsString('<version>2.0.0</version>', $out);
        self::assertStringContainsString('<creationDate>2026-07-27</creationDate>', $out);
    }

    /**
     * A manifest declares its own version once, near the top. Later matches
     * belong to something else — an update-server block, or a package child —
     * and rewriting those would corrupt them silently.
     */
    #[Test]
    public function only_the_first_version_element_is_rewritten(): void
    {
        $xml = '<extension><version>1.0.0</version>'
            . '<updateservers><server><version>9.9.9</version></server></updateservers></extension>';

        $out = ManifestVersionWriter::rewrite($xml, '2.0.0');

        self::assertStringContainsString('<version>2.0.0</version>', $out);
        self::assertStringContainsString('<version>9.9.9</version>', $out, 'Nested version must survive');
        self::assertSame(1, substr_count($out, '2.0.0'));
    }

    #[Test]
    public function a_null_date_leaves_the_creation_date_alone(): void
    {
        $xml = '<extension><version>1.0.0</version><creationDate>2020-01-01</creationDate></extension>';

        $out = ManifestVersionWriter::rewrite($xml, '2.0.0', null);

        self::assertStringContainsString('<creationDate>2020-01-01</creationDate>', $out);
    }

    #[Test]
    public function a_manifest_without_a_creation_date_is_still_bumped(): void
    {
        $out = ManifestVersionWriter::rewrite('<extension><version>1.0.0</version></extension>', '2.0.0', '2026-07-27');

        self::assertStringContainsString('<version>2.0.0</version>', $out);
        self::assertStringNotContainsString('creationDate', $out, 'Must not invent the element');
    }

    /**
     * Manifests are hand-edited and carry comments, ordering and indentation
     * that maintainers care about. Only the two elements should move.
     */
    #[Test]
    public function surrounding_content_is_untouched(): void
    {
        $xml = <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <!-- deliberate comment -->
            <extension type="component" method="upgrade">
                <name>COM_EXAMPLE</name>
                <version>1.0.0</version>
                <author>Someone</author>
            </extension>
            XML;

        $out = ManifestVersionWriter::rewrite($xml, '2.0.0');

        self::assertSame(
            str_replace('<version>1.0.0</version>', '<version>2.0.0</version>', $xml),
            $out
        );
    }

    #[Test]
    public function content_without_either_element_comes_back_unchanged(): void
    {
        $xml = '<extension><name>COM_EXAMPLE</name></extension>';

        self::assertSame($xml, ManifestVersionWriter::rewrite($xml, '2.0.0', '2026-07-27'));
    }

    #[Test]
    public function rewriting_a_file_reports_whether_it_changed(): void
    {
        $path = $this->tmp . '/manifest.xml';
        file_put_contents($path, '<extension><version>1.0.0</version></extension>');

        self::assertTrue(ManifestVersionWriter::rewriteFile($path, '2.0.0'));
        self::assertStringContainsString('<version>2.0.0</version>', file_get_contents($path));

        // Same version again: nothing to write, and the caller should be able
        // to say "no change" rather than claiming a write.
        self::assertFalse(ManifestVersionWriter::rewriteFile($path, '2.0.0'));
    }

    /**
     * A manifest listed in the config but absent is a configuration error.
     * Skipping it quietly would ship one extension still on the old version.
     */
    #[Test]
    public function an_unreadable_manifest_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not read');

        ManifestVersionWriter::rewriteFile($this->tmp . '/does-not-exist.xml', '2.0.0');
    }

    /**
     * The real shape, taken from Proclaim's package manifest.
     */
    #[Test]
    public function a_realistic_package_manifest_bumps_correctly(): void
    {
        $xml = <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <extension type="package" method="upgrade">
                <name>Proclaim</name>
                <version>10.3.5</version>
                <creationDate>2026-07-01</creationDate>
                <updateservers>
                    <server type="extension" priority="1" name="Proclaim">https://example.org/update.xml</server>
                </updateservers>
            </extension>
            XML;

        $out = ManifestVersionWriter::rewrite($xml, '10.3.6', '2026-07-27');

        self::assertStringContainsString('<version>10.3.6</version>', $out);
        self::assertStringContainsString('<creationDate>2026-07-27</creationDate>', $out);
        self::assertStringContainsString('https://example.org/update.xml', $out);
    }
}
