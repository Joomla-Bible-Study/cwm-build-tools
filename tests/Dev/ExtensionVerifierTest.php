<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\ExtensionVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the parts of ExtensionVerifier that don't need a live MySQL —
 * namely expectedExtensions(), which walks the project's manifest XMLs and
 * returns the rows that would be checked / inserted.
 *
 * Verify against a real DB lives in an integration-test layer we don't have
 * yet. The unit-level coverage here protects describeManifest() / describeXxx
 * from silent regressions when manifest shapes drift.
 */
final class ExtensionVerifierTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/manifests';

    #[Test]
    public function expected_extensions_includes_top_level_component(): void
    {
        $config = [
            'extension' => ['type' => 'component', 'name' => 'com_example'],
            'manifests' => ['extensions' => []],
        ];

        $rows = (new ExtensionVerifier(self::FIXTURES, $config))->expectedExtensions();

        self::assertCount(1, $rows);
        self::assertSame('component', $rows[0]['type']);
        self::assertSame('com_example', $rows[0]['element']);
        self::assertSame(1, $rows[0]['enabled']);
        self::assertSame(0, $rows[0]['locked']);
    }

    #[Test]
    public function expected_extensions_describes_a_library_manifest(): void
    {
        $config = [
            'manifests' => [
                'extensions' => [
                    ['type' => 'library', 'path' => 'library/cwmscripture.xml'],
                ],
            ],
        ];

        $rows = (new ExtensionVerifier(self::FIXTURES, $config))->expectedExtensions();

        self::assertCount(1, $rows);
        $row = $rows[0];

        self::assertSame('library', $row['type']);
        self::assertSame('cwmscripture', $row['element']);
        self::assertSame('lib_cwmscripture', $row['name']);
        self::assertSame(1, $row['locked'], 'libraries are locked by default');
        self::assertSame('CWM\\Library\\CWMScripture', $row['namespace']);
        self::assertArrayHasKey('installSql', $row, 'installSql should be picked up when sql/install.mysql.utf8.sql exists');
        self::assertStringEndsWith('sql/install.mysql.utf8.sql', $row['installSql']);
    }

    #[Test]
    public function expected_extensions_describes_a_plugin_manifest(): void
    {
        $config = [
            'manifests' => [
                'extensions' => [
                    ['type' => 'plugin', 'path' => 'plugin/scripturelinks.xml'],
                ],
            ],
        ];

        $rows = (new ExtensionVerifier(self::FIXTURES, $config))->expectedExtensions();

        self::assertCount(1, $rows);
        $row = $rows[0];

        self::assertSame('plugin', $row['type']);
        self::assertSame('scripturelinks', $row['element'], 'element is the manifest filename');
        self::assertSame('content', $row['folder'], 'folder comes from the group= attribute');
        self::assertSame('plg_content_scripturelinks', $row['name']);
        self::assertSame(0, $row['locked']);
    }

    #[Test]
    public function expected_extensions_skips_missing_manifests(): void
    {
        $config = [
            'manifests' => [
                'extensions' => [
                    ['type' => 'plugin', 'path' => 'plugin/scripturelinks.xml'],
                    ['type' => 'plugin', 'path' => 'plugin/does-not-exist.xml'],
                ],
            ],
        ];

        $rows = (new ExtensionVerifier(self::FIXTURES, $config))->expectedExtensions();

        self::assertCount(1, $rows);
        self::assertSame('scripturelinks', $rows[0]['element']);
    }

    #[Test]
    public function expected_extensions_returns_empty_for_empty_config(): void
    {
        $rows = (new ExtensionVerifier(self::FIXTURES, []))->expectedExtensions();

        self::assertSame([], $rows);
    }

    #[Test]
    public function expected_extensions_combines_top_level_and_listed_extensions(): void
    {
        $config = [
            'extension' => ['type' => 'component', 'name' => 'com_example'],
            'manifests' => [
                'extensions' => [
                    ['type' => 'library', 'path' => 'library/cwmscripture.xml'],
                    ['type' => 'plugin',  'path' => 'plugin/scripturelinks.xml'],
                ],
            ],
        ];

        $rows = (new ExtensionVerifier(self::FIXTURES, $config))->expectedExtensions();

        self::assertCount(3, $rows);
        $types = array_column($rows, 'type');
        self::assertSame(['component', 'library', 'plugin'], $types);
    }
}
