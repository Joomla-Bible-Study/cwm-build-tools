<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Config;

use CWM\BuildTools\Config\ManagedFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The header is the only thing standing between `composer sync-configs` and
 * a consumer's hand-written .editorconfig, so the two failure modes worth
 * pinning are: failing to recognise a file we wrote (the sync then refuses
 * to update its own file forever), and claiming one we didn't (the sync
 * overwrites the consumer's settings).
 */
final class ManagedFileTest extends TestCase
{
    #[Test]
    public function stamped_content_is_recognised_as_managed(): void
    {
        $stamped = ManagedFile::stamp("root = true\n");

        self::assertTrue(ManagedFile::isManaged($stamped));
    }

    #[Test]
    public function hand_written_content_is_not_claimed(): void
    {
        $handWritten = "# EditorConfig is awesome\nroot = true\n\n[*.php]\nindent_style = space\n";

        self::assertFalse(ManagedFile::isManaged($handWritten));
    }

    #[Test]
    public function stamping_is_idempotent(): void
    {
        $once  = ManagedFile::stamp("root = true\n");
        $twice = ManagedFile::stamp($once);

        self::assertSame($once, $twice);
        self::assertSame(1, substr_count($twice, ManagedFile::MARKER));
    }

    #[Test]
    public function stamped_body_is_preserved_verbatim(): void
    {
        $body    = "root = true\n\n[*.php]\nindent_style = space\nindent_size = 4\n";
        $stamped = ManagedFile::stamp($body);

        self::assertStringEndsWith($body, $stamped);
    }

    #[Test]
    public function every_header_line_is_commented(): void
    {
        // A stray uncommented line would make .editorconfig unparseable, and
        // editors fail it quietly — the whole file stops applying.
        foreach (explode("\n", rtrim(ManagedFile::header())) as $line) {
            self::assertStringStartsWith('#', $line, "uncommented header line: {$line}");
        }
    }

    #[Test]
    public function comment_leader_is_configurable_for_other_syntaxes(): void
    {
        $stamped = ManagedFile::stamp("export default [];\n", '//');

        self::assertStringStartsWith('// ' . ManagedFile::MARKER, $stamped);
        self::assertTrue(ManagedFile::isManaged($stamped));
    }
}
