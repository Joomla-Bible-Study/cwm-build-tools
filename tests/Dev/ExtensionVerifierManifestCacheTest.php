<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\ExtensionVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for parseManifestXml + compareManifestCache. The full
 * verify() loop (DB-connected) is exercised separately; these tests
 * stay pure so they run in CI without a Joomla install.
 */
final class ExtensionVerifierManifestCacheTest extends TestCase
{
    private string $tmpDir;
    private string $libraryFixture;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-manifest-cache-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);

        $this->libraryFixture = \dirname(__DIR__) . '/fixtures/manifests/library/cwmscripture.xml';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function parse_manifest_xml_extracts_joomla_manifest_cache_fields(): void
    {
        $data = (new ExtensionVerifier($this->tmpDir, []))->parseManifestXml($this->libraryFixture);

        self::assertNotNull($data);
        self::assertSame('lib_cwmscripture', $data['name']);
        self::assertSame('library', $data['type']);
        self::assertSame('1.2.3', $data['version']);
        self::assertSame('CWM', $data['author']);
        self::assertSame('2026-01-01', $data['creationDate']);
        self::assertSame('LIB_CWMSCRIPTURE_DESCRIPTION', $data['description']);
    }

    #[Test]
    public function parse_manifest_xml_returns_null_for_missing_file(): void
    {
        $data = (new ExtensionVerifier($this->tmpDir, []))->parseManifestXml($this->tmpDir . '/nope.xml');

        self::assertNull($data);
    }

    #[Test]
    public function parse_manifest_xml_returns_null_for_non_extension_root(): void
    {
        $badPath = $this->tmpDir . '/bad.xml';
        file_put_contents($badPath, '<?xml version="1.0"?><not-an-extension/>');

        $data = (new ExtensionVerifier($this->tmpDir, []))->parseManifestXml($badPath);

        self::assertNull($data);
    }

    #[Test]
    public function compare_returns_empty_when_cache_matches_xml_for_checked_fields(): void
    {
        $expected = (new ExtensionVerifier($this->tmpDir, []))->parseManifestXml($this->libraryFixture);
        self::assertNotNull($expected);

        // Simulate Joomla's manifest_cache row — only the checked fields matter.
        $actualJson = json_encode([
            'name'        => 'lib_cwmscripture',
            'version'     => '1.2.3',
            'description' => 'LIB_CWMSCRIPTURE_DESCRIPTION',
            'author'      => 'CWM',
            // extra fields Joomla writes but we don't compare
            'creationDate' => '2099-09-09',
            'license'      => 'GPL',
        ]);

        $drift = (new ExtensionVerifier($this->tmpDir, []))->compareManifestCache($expected, (string) $actualJson);

        self::assertSame([], $drift);
    }

    #[Test]
    public function compare_flags_version_drift(): void
    {
        $expected   = (new ExtensionVerifier($this->tmpDir, []))->parseManifestXml($this->libraryFixture);
        $actualJson = (string) json_encode([
            'name'        => 'lib_cwmscripture',
            'version'     => '1.1.0',  // stale
            'description' => 'LIB_CWMSCRIPTURE_DESCRIPTION',
            'author'      => 'CWM',
        ]);

        $drift = (new ExtensionVerifier($this->tmpDir, []))->compareManifestCache($expected, $actualJson);

        self::assertNotEmpty($drift);
        self::assertStringContainsString('version', $drift[0]);
        self::assertStringContainsString('1.1.0', $drift[0]);
        self::assertStringContainsString('1.2.3', $drift[0]);
    }

    #[Test]
    public function compare_flags_multiple_drifted_fields_at_once(): void
    {
        $expected   = (new ExtensionVerifier($this->tmpDir, []))->parseManifestXml($this->libraryFixture);
        $actualJson = (string) json_encode([
            'name'        => 'lib_cwmscripture',
            'version'     => '1.0.0',
            'description' => '',  // empty
            'author'      => 'Old Author',
        ]);

        $drift = (new ExtensionVerifier($this->tmpDir, []))->compareManifestCache($expected, $actualJson);

        self::assertCount(3, $drift);
    }

    #[Test]
    public function compare_treats_null_manifest_cache_json_as_invalid(): void
    {
        $expected = (new ExtensionVerifier($this->tmpDir, []))->parseManifestXml($this->libraryFixture);

        $drift = (new ExtensionVerifier($this->tmpDir, []))->compareManifestCache($expected, 'not-json-at-all');

        self::assertSame(['manifest_cache is not valid JSON'], $drift);
    }

    #[Test]
    public function compare_does_not_flag_when_both_sides_have_empty_optional_field(): void
    {
        // XML lacks <description>; Joomla parses that as '' and the cache
        // mirrors it. parseManifestXml also fills author='Unknown' to match
        // Joomla's JLIB_UNKNOWN fallback — so the simulated cache must
        // include the same fallback to count as "in sync".
        $xmlPath = $this->tmpDir . '/minimal.xml';
        file_put_contents($xmlPath, <<<XML
            <?xml version="1.0"?>
            <extension type="library">
                <name>lib_minimal</name>
                <version>1.0.0</version>
            </extension>
            XML);

        $expected   = (new ExtensionVerifier($this->tmpDir, []))->parseManifestXml($xmlPath);
        $actualJson = (string) json_encode([
            'name'        => 'lib_minimal',
            'version'     => '1.0.0',
            'description' => '',
            'author'      => 'Unknown',
        ]);

        $drift = (new ExtensionVerifier($this->tmpDir, []))->compareManifestCache($expected, $actualJson);

        self::assertSame([], $drift);
    }
}
